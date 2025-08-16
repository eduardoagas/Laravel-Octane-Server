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
            'agility' => $agility,
            // NOVO: campo para acumular consumo sem mexer no start_time
            'used_stamina_total' => 0,
        ];
    }

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
            return 0;
        }

        $parsed = json_decode($data, true);

        $startTime = (int) ($parsed['start_time'] ?? 0);
        $initial = (float) ($parsed['initial_stamina'] ?? 0);
        $sMax = (float) ($parsed['max_stamina'] ?? 0);
        //$sMax = 50 + ($sab - 1) * (650.0 / 299.0);
        $agi = (float) ($parsed['agility'] ?? 0);
        $agi = max(1.0, $agi);

        // NOVO: lê o consumo acumulado que agora é subtraído do resultado
        $used = (float) ($parsed['used_stamina_total'] ?? 0.0);

        $elapsed = now()->timestamp - $startTime;

        Log::info("📊 [getCurrentStamina] Dados extraídos:", compact('startTime', 'initial', 'sMax', 'agi', 'elapsed', 'used')); // incluído used no log (NOVO)

        // Constantes
        /*$minRate = 3;      // regen mínima com agi=1
        $maxRate = 20;     // regen máxima com agi=300
        $maxAgi = 300;          // agilidade máxima
        $alpha   = 0.3;    // curva acelerada para agilidade
        $beta    = 0.45;   // influência suavizada da sabedoria
        $B = 1.7;*/               // máximo multiplicador do betaFactor (aumento máximo da taxa)

        //valores deepsk

        $minRate = 3.31;      // Taxa mínima (Agi=1)
        $maxRate = 30.69;     // Taxa máxima (Agi=300)
        $maxAgi = 300;        // Agilidade máxima
        $alpha = 1;           // Comportamento linear
        $beta = 0.4;          // Influência da stamina atual
        $B = 2.5;             // Máximo multiplicador do betaFactor


        // Cálculo da taxa base de regeneração (sem fator beta)
        // Calcula fator baseado na agilidade (curva suavizada pelo alpha)
        $agiFactor = pow(min($agi / $maxAgi, 1), $alpha);
        // Calcula taxa base de regeneração (stamina/segundo)
        $baseRegen = $minRate + ($maxRate - $minRate) * $agiFactor;

        // Fator beta: quanto mais stamina bruta, mais rápido regenera
        $maxInitial = 100; // Define o teto de influência do beta (ex: 100 pontos)
        $clampedInitial = min($initial, $maxInitial);
        $betaFactor = 1 + ($B - 1) * pow($clampedInitial / $maxInitial, $beta);

        // Recuperação
        // Quantidade de stamina regenerada desde o último cálculo
        $recovered = $baseRegen * $betaFactor * $elapsed;

        // NOVO: subtrai used_stamina_total para evitar double-counting quando o start_time é fixo
        $stamina = $initial + $recovered - $used;

        // Estamina atualizada limitada ao máximo permitido
        $stamina = min(max(0.0, $stamina), $sMax);

        Log::info("✅ [getCurrentStamina] Resultado calculado:", [
            'stamina_calculada' => $stamina,
            'stamina_limitada' => $stamina,
            'initial_stamina' => $initial,
            'recovered' => $recovered,
            'used_stamina_total' => $used, // NOVO: log do usado
        ]);

        return $stamina;
    }

    /**
     * Consume stamina atomically using a Lua script stored in storage/redis_scripts/consume_stamina.lua
     *
     * - NOVO: agora incrementamos used_stamina_total (em vez de sobrescrever initial_stamina).
     * - NOVO: operação feita via Lua para evitar race conditions (atomicidade).
     * - Retorna o valor de stamina atual depois do consumo (float) ou null se insuficiente/erro.
     */
    public static function consumeStamina(string $battleId, string $id, float $amount, string $type = 'character'): ?float
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
            $raw = Redis::eval($luaScript, 1, $key, $field, (string)$amount, (string)$nowTs);

            // Esperamos que o script retorne JSON (veja o arquivo Lua abaixo)
            $decoded = json_decode($raw, true);

            if (!$decoded) {
                Log::error("[consumeStamina] Resposta do script Lua não pôde ser decodificada", [
                    'raw' => $raw
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
            return $currentAfter;
        } catch (\Throwable $e) {
            Log::error("[consumeStamina] Erro ao executar script Lua: " . $e->getMessage(), [
                'exception' => $e
            ]);
            return null;
        }
    }
}
