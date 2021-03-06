<?php
use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Event\Dispatcher;
use Onion\Framework\Event\ListenerProviders\AggregateProvider;
use Onion\Framework\Event\ListenerProviders\SimpleProvider;
use Onion\Framework\Loop\Signal;
use function Onion\Framework\Loop\scheduler;

require __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

class TestEvent {}

$aggregate = new AggregateProvider();
$basic1 = new SimpleProvider([
    TestEvent::class => [
        function (TestEvent $event) { echo "Listener 1\n";},
        function (TestEvent $event) { echo "Listener 2\n";},
    ],
]);
$basic2 = new SimpleProvider([
    TestEvent::class => [
        function (TestEvent $event) { echo "Listener 3\n";},
        function (TestEvent $event) { echo "Listener 4\n";},
    ],
]);

$aggregate->addProvider($basic1, $basic2);

$dispatcher = new Dispatcher($aggregate);
$task = Coroutine::create(function ($dispatcher) {
    yield Coroutine::create(function () {
        echo "Coroutine 1\n";
        yield;
    });

    var_dump(yield $dispatcher->dispatch(new TestEvent));
    yield Coroutine::create(function () {
        echo "Coroutine 2\n";
        yield;
    });
}, [$dispatcher]);


scheduler()->add(new Coroutine(function (Signal $signal) {
    yield $signal;
}, [$task]));
scheduler()->start();
