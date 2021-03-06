<?php

use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Timer;
use function Onion\Framework\Loop\scheduler;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

scheduler()->add(new Coroutine(function () {
    yield Timer::after(function () {
        yield var_dump(microtime(true));
    }, 1000);

    yield Timer::interval(function () {
        yield var_dump(microtime(true));
    }, 500, false);
}));



scheduler()->start();
