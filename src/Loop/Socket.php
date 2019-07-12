<?php

namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Descriptor;
use Onion\Framework\Loop\Interfaces\SocketInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\ResourceInterface;

class Socket extends Descriptor implements SocketInterface
{
    public function read(int $size, int $flags = 0): string
    {
        return stream_socket_recvfrom(
            $this->getDescriptor(),
            $size,
            $flags
        );
    }

    public function write(string $data, int $flags = 0): int
    {
        return stream_socket_sendto(
            $this->getDescriptor(),
            $data,
            $flags,
            stream_socket_get_name($this->getDescriptor(), true)
        );
    }

    public function accept(?int $timeout = 0): Signal
    {
        $waitFn = function (TaskInterface $task, SchedulerInterface $scheduler, ResourceInterface $resource, ?int $timeout) {
            $task->send(new Descriptor(@stream_socket_accept($resource->getDescriptor(), $timeout)));
            yield $scheduler->schedule($task);
        };

        return new Signal(function (TaskInterface $task, SchedulerInterface $scheduler) use ($timeout, $waitFn) {
            $scheduler->add(new Coroutine($waitFn, [$task, $scheduler, $this, $timeout]));
        });
    }
}
