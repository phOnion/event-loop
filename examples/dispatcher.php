<?php

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\scheduler;
use function Onion\Framework\Loop\tick;

use Onion\Framework\Event\Dispatcher;
use Onion\Framework\Event\ListenerProviders\AggregateProvider;
use Onion\Framework\Event\ListenerProviders\SimpleProvider;

use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Signal;

require __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

class TestEvent
{
}

$aggregate = new AggregateProvider();
$basic1 = new SimpleProvider([
    TestEvent::class => [
        function (TestEvent $event) {
            echo "Listener 1\n";
        },
        function (TestEvent $event) {
            echo "Listener 2\n";
        },
    ],
]);
$basic2 = new SimpleProvider([
    TestEvent::class => [
        function (TestEvent $event) {
            echo "Listener 3\n";
        },
        function (TestEvent $event) {
            echo "Listener 4\n";
        },
    ],
]);

$aggregate->addProvider($basic1, $basic2);

$dispatcher = new Dispatcher($aggregate);
$task = coroutine(function ($dispatcher) {
    coroutine(function () {
        echo "Coroutine 1\n";
        tick();
    });

    var_dump($dispatcher->dispatch(new TestEvent));
    coroutine(function () {
        echo "Coroutine 2\n";
        tick();
    });
}, [$dispatcher]);

coroutine(function (Coroutine $signal) {
    var_dump($signal);
}, [$task]);
scheduler()->start();
