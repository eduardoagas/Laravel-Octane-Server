<?php

require __DIR__ . '/vendor/autoload.php';

use App\Events\BattleEvent;

$event = new BattleEvent("battle123", [], "event");

dd(serialize($event));
