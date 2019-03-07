<?php
namespace Onion\Framework\EventLoop\Interfaces;

use Closure;

interface LoopInterface
{
    const TASK_IMMEDIATE = 0;
    const TASK_DEFERRED = 1;

    public function attach($resource, ?Closure $onRead = null, ?Closure $onWrite = null): bool;
    public function detach($resource): bool;

    public function start(): void;
    public function push(TaskInterface $task, int $type = self::TASK_IMMEDIATE): TaskInterface;
    public function stop(): void;
}