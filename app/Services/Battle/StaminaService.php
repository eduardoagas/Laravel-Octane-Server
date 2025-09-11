<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class StaminaService
{
    public function initializeStamina(int $now, float $maxStamina, float $agility): array
    {

        return [
            'start_time' => $now,
            'initial_stamina' => 0,
            'max_stamina' => $maxStamina,
            'dexterity' => $agility,
            // NOVO: campo para acumular consumo sem mexer no start_time
            'used_stamina_total' => 0,
        ];
    }

    /**
     * ALTERADO: função reescrita para usar cálculo analítico por bandas ABSOLUTAS.
     * - Não itera segundo-a-segundo; calcula quanto tempo leva para preencher cada banda e salta entre bandas.
     * - Mantém compatibilidade com o script Lua analítico (mesmas constantes / bandas).
     * - Subtrai used_stamina_total (como você já tinha definido).
     *
     * Comentários:
     * - Mantenha as constantes e as bandas idênticas às do script Lua para evitar inconsistências.
     */
    public static function getCurrentStamina(string $battleId, string $id, string $type = 'character'): float
    {
        $field = "{$type}:{$id}";
        $key = "battle:$battleId:stamina_data";
        $data = Redis::hget($key, $field);

        Log::info("📥 [getCurrentStamina] Buscando stamina", [
            'redis_key' => $key,
            'field' => $field,
            'raw_data' => $data,
        ]);

        if (!$data) {
            Log::warning("⚠️ Nenhum dado de stamina encontrado", [
                'battleId' => $battleId,
                'field' => $field
            ]);
            return 0.0;
        }

        $parsed = json_decode($data, true);

        $startTime = (int) ($parsed['start_time'] ?? 0);
        $initial = (float) ($parsed['initial_stamina'] ?? 0.0);
        $sMax = (float) ($parsed['max_stamina'] ?? 0.0);
        //$sMax = 50 + ($sab - 1) * (650.0 / 299.0);
        $dex = (float) ($parsed['dexterity'] ?? 0.0);
        $dex = max(1.0, $dex);

        // NOVO: lê o consumo acumulado que agora é subtraído do resultado
        $used = (float) ($parsed['used_stamina_total'] ?? 0.0);

        $elapsed = now()->timestamp - $startTime;

        Log::info("📊 [getCurrentStamina] Dados extraídos:", compact('startTime', 'initial', 'sMax', 'dex', 'elapsed', 'used')); // incluído used no log (NOVO)

        // ---------- constantes (MANTER idênticas ao Lua) ----------
        $minRate = 3.60;      // regen mínima com dex=1
        $maxRate = 20.0;     // regen máxima com dex=300
        $maxDex = 300.0;     // agilidade máxima
        $alpha   = 0.3;      // curva acelerada para agilidade
        // ----------------------------------------------------------

        // ABSOLUTE bands (valores em pontos de stamina) - MANTER idêntico ao Lua
        // Formato: [from, to, multiplier]
        $absBands = [
            [0.0, 50.0, 0.4],
            [50.0, 150.0, 0.8],
            [150.0, 350.0, 1.2],
            [350.0, 700.0, 1.8],
            [700.0, PHP_FLOAT_MAX, 3.0],
        ];

        // Cálculo da taxa base de regeneração (influência só da agilidade)
        $agiFactor = pow(min($dex / $maxDex, 1.0), $alpha);
        $baseRegen = $minRate + ($maxRate - $minRate) * $agiFactor; // stamina por segundo

        // ------- Versão analítica: calcular recovered atravessando bandas (sem loop por segundo) -------
        $remaining = max(0.0, (float)$elapsed); // segundos disponíveis para regen
        // currentSim representa o 'ponto atual' antes de recovered; aplicamos used aqui (como no Lua)
        $currentSim = max(0.0, $initial - $used);
        $recovered = 0.0;

        foreach ($absBands as $band) {
            if ($remaining <= 0.0) break;
            if ($currentSim >= $sMax) break;

            $bFrom = $band[0];
            $bTo = min($band[1], $sMax); // não exceder sMax
            $mult = $band[2];

            // se já estamos depois dessa banda, pular
            if ($currentSim >= $bTo) {
                continue;
            }

            // target é o topo desta banda (ou sMax)
            $target = $bTo;
            $rate = $baseRegen * $mult; // stamina por segundo dentro desta banda

            if ($rate <= 0.0) {
                // segurança: não progredimos se rate inválida
                $remaining = 0.0;
                break;
            }

            $need = $target - $currentSim; // pontos necessários para encher a banda
            $timeToFill = $need / $rate; // segundos necessários para encher a banda

            if ($timeToFill <= $remaining) {
                // conseguimos encher toda a banda dentro do tempo restante
                $recovered += $need;
                $currentSim += $need;
                $remaining -= $timeToFill;
            } else {
                // apenas preenchimento parcial dentro desta banda
                $gain = $rate * $remaining;
                $recovered += $gain;
                $currentSim += $gain;
                $remaining = 0.0;
                break;
            }
        }

        // Segurança para evitar tiny overshoot numérico
        if (($initial + $recovered - $used) > $sMax) {
            $recovered -= (($initial + $recovered - $used) - $sMax);
        }

        // NOVO/ALTERADO: stamina final considerando used acumulado (mantém tua abordagem)
        $stamina = max(0.0, min($sMax, $initial + $recovered - $used));

        Log::info("✅ [getCurrentStamina] Resultado calculado:", [
            'stamina_calculada' => $stamina,
            'stamina_limitada' => $stamina,
            'initial_stamina' => $initial,
            'recovered' => $recovered,
            'used_stamina_total' => $used, // NOVO: log do usado
            'elapsed_seconds' => $elapsed,
        ]);

        return $stamina;
    }

    /**
     * Consume stamina atomically using a Lua script stored in storage/redis_scripts/consume_stamina.lua
     *
     * - NOVO: agora incrementamos used_stamina_total (em vez de sobrescrever initial_stamina).
     * - NOVO: operação feita via Lua para evitar race conditions (atomicidade).
     * - Retorna o valor de stamina atual depois do consumo (float) ou null se insuficiente/erro.
     *
     * Observação: o script Lua deve implementar a mesma lógica analítica (mesmas constantes/bandas)
     * para garantir consistência entre leitura (PHP) e consumo (Lua).
     */
    public static function consumeStamina(string $battleId, string $id, float $amount, string $type = 'character'): ?array
    {
        $field = "{$type}:{$id}";
        $key = "battle:$battleId:stamina_data";

        Log::info("🛠️ [consumeStamina] Tentando consumir stamina (via Lua ATÔMICO)", [
            'field' => $field,
            'amount' => $amount,
        ]);

        // Carrega script Lua a partir do storage (NOVO: arquivo separado)
        $luaPath = storage_path("redis_scripts/consume_stamina.lua");
        if (!file_exists($luaPath)) {
            Log::error("[consumeStamina] Lua script não encontrado em: $luaPath");
            return null;
        }
        $luaScript = file_get_contents($luaPath);

        // Passa timestamp do servidor para o script (evita diferença de tempo dentro do script)
        $nowTs = now()->timestamp;

        try {
            // Executa o script Lua de forma atômica
            // KEYS: 1 (hash key), ARGV: field, amount, nowTs
            try {
                $raw = Redis::eval($luaScript, 1, $key, $field, (string)$amount, (string)$nowTs);
            } catch (\Throwable $e) {
                // NOVO: logar erro de execução do eval (exceção Redis)
                Log::error("[consumeStamina] Redis::eval lançou exceção: " . $e->getMessage(), [
                    'exception' => $e,
                    'field' => $field,
                    'amount' => $amount,
                    'nowTs' => $nowTs,
                ]);
                return null;
            }
            // NOVO: caso o cliente Redis retorne false (lua runtime error / nil), logar contexto completo
            if ($raw === false) {
                Log::error("[consumeStamina] Redis::eval retornou false (lua runtime ou nil).", [
                    'field' => $field,
                    'amount' => $amount,
                    'nowTs' => $nowTs,
                    // não logar todo o script em produção, mas em dev pode ser útil:
                    //'lua_script_snippet' => substr($luaScript, 0, 200),
                ]);
                return null;
            }
            // Esperamos que o script retorne JSON (veja o arquivo Lua abaixo)
            $decoded = json_decode($raw, true);

            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                // NOVO: log mais explícito sobre porque o decode falhou
                Log::error("[consumeStamina] Resposta do script Lua não pôde ser decodificada", [
                    'raw' => $raw,
                    'json_last_error' => json_last_error_msg(),
                    'field' => $field,
                    'amount' => $amount,
                ]);
                return null;
            }

            if (isset($decoded['error']) && $decoded['error'] === 'insufficient') {
                Log::warning("❌ [consumeStamina] Stamina insuficiente (detectado no Lua)", [
                    'field' => $field,
                    'current' => $decoded['current'] ?? null,
                    'needed' => $amount
                ]);
                return null;
            }

            // NOVO: script retorna used (novo used_stamina_total) e current_after (stamina após consumir)
            $newUsed = isset($decoded['used']) ? (float)$decoded['used'] : null;
            $currentAfter = isset($decoded['current_after']) ? (float)$decoded['current_after'] : null;

            Log::info("✅ [consumeStamina] Consumo aplicado (via Lua)", [
                'field' => $field,
                'amount' => $amount,
                'new_used_stamina_total' => $newUsed,
                'current_after' => $currentAfter
            ]);

            // Retorna a stamina atual após o consumo (pode ser usada para broadcast imediato)
            //return $currentAfter;
            // retorna o array completo para o caller (mais info)
            return $decoded;
        } catch (\Throwable $e) {
            Log::error("[consumeStamina] Erro ao executar script Lua: " . $e->getMessage(), [
                'exception' => $e
            ]);
            return null;
        }
    }
}
