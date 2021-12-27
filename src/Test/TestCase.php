<?php

namespace Onion\Framework\Test;

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\scheduler;
use Onion\Framework\Loop\Scheduler;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

class TestCase extends PhpUnitTestCase
{
    private string $realTestName;

    final public function setName(string $name): void
    {
        parent::setName($name);
        $this->realTestName = $name;
    }

    final public function runAsyncTest(mixed ...$args): void
    {
        parent::setName($this->realTestName);

        scheduler(new Scheduler());
        coroutine(function () use ($args) {
            $this->{$this->realTestName}(...$args);
        }, $args);
        scheduler()->start();
    }

    final protected function runTest(): void
    {
        parent::setName('runAsyncTest');
        parent::runTest();
    }
}
