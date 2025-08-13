<?php
// app/WebSocket/UnityEventDispatcher.php

namespace App\Listeners\WebSockets;

use App\Listeners\WebSocket\Contracts\HandlesUnityEvent;
use Laravel\Reverb\Contracts\Connection;
use Illuminate\Support\Str;

class UnityEventDispatcher
{
    protected array $handlers;

    public function __construct()
    {
        $this->handlers = [
            'create_battle' => \App\Listeners\WebSockets\Handlers\CreateBattleHandler::class,
            'join_battle' => \App\Listeners\WebSockets\Handlers\JoinBattleHandler::class,
            'shoot' => \App\Listeners\WebSockets\Handlers\ShootHandler::class,
            'client-shootRequest' => \App\Listeners\WebSockets\Handlers\ShootShotHandler::class,
            'connect_to_server' => \App\Listeners\WebSockets\Handlers\ConnectToServerHandler::class,
            'battle_with_monster' => \App\Listeners\WebSockets\Handlers\BattleWithMonsterHandler::class,
            'use_skill' => \App\Listeners\WebSockets\Handlers\UseSkillHandler::class,
            'subscribeConfirmed' => \App\Listeners\WebSockets\Handlers\SubscribeConfirmedHandler::class,
            // adicionar os demais aqui...
        ];
    }
    public function dispatch(string $event, array $payload, int $userId, string $token, Connection $connection): void
    {
        // Ignorar eventos Pusher internos
        if (Str::startsWith($event, 'pusher:')) {
            // Opcional: logar ou simplesmente ignorar
            return;
        }
        $handlerClass = $this->handlers[$event] ?? null;

        if (!$handlerClass || !class_exists($handlerClass)) {
            $errorMessage = 'Invalid message type';
            if (!empty($event)) {
                $errorMessage .= ": " . $event;
            }
            $connection->send(json_encode(['error' => $errorMessage]));
            return;
        }

        /** @var HandlesUnityEvent $handler */
        $handler = app($handlerClass);
        $handler->handle($payload, $userId, $token, $connection);
    }
}
