<?php

declare(strict_types=1);

namespace Onion\Framework\Loop;

use Closure;
use Fiber;
use FiberError;
use Onion\Framework\Loop\Channels\Channel;
use Onion\Framework\Loop\Interfaces\{
    ResourceInterface,
    SchedulerInterface,
    TaskInterface,
    TimerInterface
};
use Onion\Framework\Loop\Resources\Buffer;
use Onion\Framework\Loop\Types\Operation;
use RuntimeException;
use Throwable;

if (!function_exists(__NAMESPACE__ . '\read')) {
    /**
     * Trigger `$fn` whenever `$resource` is readable (i.e has pending
     * data). The signature of `$fn` is `fn (ResourceInterface $resource
     * ) => mixed` that will be returned to the calling function on
     * completion. (in blocking mode - default) or null in non-blocking
     *
     * Note that the function should take care checking if the resource is EOF(closed)
     *
     * @param ResourceInterface $socket Resource to wait upon
     * @param ?Closure $fn The function to trigger when data is available
     * @param bool $sync Should the calling coroutine block until completion
     * or return immediately.
     *
     * @return mixed The result of `$fn` in blocking mode (default) or null in non-blocking
     *
     * @throws FiberError
     * @throws Throwable
     */
    function read(
        ResourceInterface $socket,
        ?Closure $fn = null,
        bool $sync = true,
    ): mixed {
        $fn = $fn ?? fn (ResourceInterface $socket): ResourceInterface => $socket;

        return $sync ?
            signal(
                fn (
                    Closure $resume,
                    TaskInterface $task,
                    SchedulerInterface $scheduler,
                ) => $scheduler->onRead(
                    $socket,
                    Task::create(fn (): mixed => $resume(($fn)($socket)))
                )
            ) : scheduler()->onRead($socket, Task::create($fn, [$socket]));
    }
}

if (!function_exists(__NAMESPACE__ . '\write')) {
    /**
     * Attempt to write `$data` to `$resource`. The function will return
     * either immediately or block the current coroutine until the data
     * has been written or has failed writing.
     *
     * @param ResourceInterface $socket Resource to wait upon
     * @param string $data The text to attempt to write
     * @param bool $sync Should the calling coroutine block until completion
     * or return immediately.
     *
     * @return int|null The number of bytes written in blocking mode (default) or
     *  null when in non-blocking or error occurred while writing
     *
     * @throws FiberError
     * @throws Throwable
     */
    function write(
        ResourceInterface $socket,
        string $data,
        bool $sync = true,
    ): ?int {

        $fn = static function (ResourceInterface $resource) use (&$data) {
            $length = strlen($data);
            $size = 0;
            while ($size < $length) {
                $len = $resource->write(substr($data, $size));
                suspend();

                if ($len === false) {
                    // prevent infinite retries
                    break;
                }

                $size += $len;
            }
            return $size !== false ? $size : null;
        };

        return $sync ?
            signal(
                fn (
                    /**
                     * @psalm-trace
                     */
                    Closure $resume,
                    TaskInterface $task,
                    SchedulerInterface $scheduler,
                ) => $scheduler->onWrite(
                    $socket,
                    Task::create(
                        fn (ResourceInterface $socket): mixed => $resume(($fn)($socket)),
                        [$socket]
                    )
                )
            ) : scheduler()->onWrite($socket, Task::create($fn, [$socket]));
    }
}

if (!function_exists(__NAMESPACE__ . '\scheduler')) {
    /**
     * Retrieve the current instance of the scheduler or set a new one
     * instance.
     *
     * A new `Onion\Framework\Loop\Scheduler` instance will be created
     * if none has been explicitly set.
     *
     * @param null|SchedulerInterface $instance No argument is needed
     * when fetching the currently active one
     */
    function scheduler(
        ?SchedulerInterface $instance = null,
    ): SchedulerInterface {
        /** @var SchedulerInterface|null $scheduler */
        static $scheduler;

        if ($instance !== null) {
            $scheduler = $instance;
            if (defined('EVENT_LOOP_DEFAULT_HANDLE_SIGNALS') && EVENT_LOOP_DEFAULT_HANDLE_SIGNALS) {
                register_default_signal_handler();
            }
        } elseif (!$scheduler) {
            if (extension_loaded('uv')) {
                $scheduler = new Scheduler\Uv();
            } elseif (extension_loaded('ev')) {
                $scheduler = new Scheduler\Ev();
            } elseif (extension_loaded('event')) {
                $scheduler = new Scheduler\Event();
            } else {
                $scheduler = new Scheduler\Select();
            }
        }

        if ($scheduler === null) {
            throw new RuntimeException(
                "Unable to create default scheduler and a default one couldn't be created"
            );
        }

        return $scheduler;
    }
}

if (!function_exists(__NAMESPACE__ . '\coroutine')) {
    /**
     * Register a new coroutine to execute on the next tick of the loop.
     *
     * @param Closure $fn The function to execute
     * @param array $args A list of arguments to pass to the function
     * when executing
     *
     * @return TaskInterface A reference to the task, with which it can
     * be externally manipulated
     */
    function coroutine(Closure $fn, array $args = []): TaskInterface
    {
        return signal(function (Closure $resume, TaskInterface $task, SchedulerInterface $scheduler) use ($fn, $args) {
            $t = Task::create($fn, $args);
            $scheduler->schedule($t);
            $resume($t);
        });
    }
}

if (!function_exists(__NAMESPACE__ . '\signal')) {
    /**
     * A special instruction to send to the scheduler, that should
     * allow alteration of the behavior, i.e suspend a task until another
     * completes, continue only when certain conditions are met, etc.
     *
     * @param Closure $fn The logic of the signal
     *
     * @return mixed The value provided to `$resume` or through
     * `TaskInterface::resume`
     *
     * @throws FiberError
     * @throws Throwable
     *
     * @internal
     */
    function signal(Closure $fn): mixed
    {
        if (!Fiber::getCurrent() || !class_exists(Signal::class)) {
            $result = null;
            $fn(function (mixed $value = null) use (&$result) {
                $result = $value;
            }, Task::create(fn () => null), scheduler());

            return $result;
        }

        return Fiber::suspend(new Signal(function (TaskInterface $task, SchedulerInterface $scheduler) use ($fn) {
            try {
                $fn(
                    fn (mixed $value = null) => $task->resume($value) && $scheduler->schedule($task),
                    $task,
                    $scheduler
                );
            } catch (Throwable $ex) {
                $task->throw($ex);
                $scheduler->schedule($task);
            }
        }));
    }
}

if (!function_exists(__NAMESPACE__ . '\with')) {
    /**
     * Block the current coroutine until the result of `$expr` is truthful
     * then the calling coroutine will receive the result of $expr
     *
     * @param Closure $expr The expression to run
     * @param mixed $args arguments to pass to `$expr` on every run
     *
     * @return mixed The return value of `$expr`
     *
     * @throws FiberError
     * @throws Throwable
     */
    function with(Closure $expr, ...$args): mixed
    {
        return signal(function (Closure $resume) use (&$expr, &$args): void {
            $result = null;
            while (!($result = $expr($args))) {
                suspend();
            }

            $resume($result);
        });
    }
}

if (!function_exists(__NAMESPACE__ . '\tick')) {
    /**
     * @deprecated Use `suspend` instead
     * @see \Onion\Framework\Loop\suspend()
     */
    function tick(): void
    {
        suspend();
    }
}

if (!function_exists(__NAMESPACE__ . '\suspend')) {
    /**
     * Enables the cooperative behavior nature of the event loop, i.e
     * handles control back to the event loop to continue executing
     * other tasks and pause the current one.
     *
     * @return void
     */
    function suspend(): void
    {
        signal(fn (Closure $resume): mixed => $resume());
    }
}

if (!function_exists(__NAMESPACE__ . '\is_readable')) {
    /**
     * Checks the provided `$resource` if it is in a readable mode
     *
     * @param ResourceInterface $resource
     * @return bool
     */
    function is_readable(ResourceInterface $resource): bool
    {
        $modes = [
            'r' => true,
            'w+' => true,
            'r+' => true,
            'x+' => true,
            'c+' => true,
            'rb' => true,
            'w+b' => true,
            'r+b' => true,
            'x+b' => true,
            'c+b' => true,
            'rt' => true,
            'w+t' => true,
            'r+t' => true,
            'x+t' => true,
            'c+t' => true,
            'a+' => true,
            'a+b' => true,
        ];

        if ($resource->eof()) {
            return false;
        }

        $metadata = stream_get_meta_data($resource->getResource());

        return isset($modes[$metadata['mode']]);
    }
}

if (!function_exists(__NAMESPACE__ . '\is_writeable')) {
    /**
     * Checks the provided `$resource` if it is in a writable mode
     * @param ResourceInterface $resource
     * @return bool
     */
    function is_writeable(ResourceInterface $resource): bool
    {
        $modes = [
            'w' => true,
            'w+' => true,
            'rw' => true,
            'r+' => true,
            'x+' => true,
            'c+' => true,
            'wb' => true,
            'w+b' => true,
            'r+b' => true,
            'x+b' => true,
            'c+b' => true,
            'w+t' => true,
            'r+t' => true,
            'x+t' => true,
            'c+t' => true,
            'a' => true,
            'a+' => true,
            'a+b' => true,
        ];

        if ($resource->eof()) {
            return false;
        }

        $metadata = stream_get_meta_data($resource->getResource());

        return isset($modes[$metadata['mode']]);
    }
}

if (!function_exists(__NAMESPACE__ . '\channel')) {
    /**
     * Creates a new `Onion\Framework\Loop\Channel` to be used to
     * to communicate with other coroutines
     */
    function channel(): Channel
    {
        return new Channel();
    }
}

if (!function_exists(__NAMESPACE__ . '\is_pending')) {
    /**
     * Checks the provided `$resource` if it has pending operation to be performed
     *
     * @param ResourceInterface $resource
     * @param Operation $operation
     * @return bool
     */
    function is_pending(ResourceInterface $resource, Operation $operation = Operation::READ): bool
    {
        if ($resource->eof()) {
            return false;
        }

        $read = $write = null;

        switch ($operation) {
            case Operation::READ:
                $read = [$resource->getResource()];
                break;
            case Operation::WRITE:
                $write = [$resource->getResource()];
                break;
        }

        $error = [];
        $result = stream_select($read, $write, $error, 0, 0);

        return $result !== false && $result > 0;
    }
}

if (!function_exists(__NAMESPACE__ . '\sleep')) {
    /**
     * An async `sleep` function that will delay the execution of the
     * calling function until the specified timeout is reached.
     *
     * @param float|int $timeout The timeout in milliseconds before
     * continuing with the execution.
     *
     * @deprecated
     * @see \Onion\Framework\Loop\delay
     *
     * @return void
     */
    function sleep(float|int $timeout): void
    {
        signal(fn (Closure $resume) => Timer::after(fn (): mixed => $resume(), (int) $timeout * 1000));
    }
}

if (!function_exists(__NAMESPACE__ . '\delay')) {
    /**
     * An async wait function that will delay the execution of the
     * calling function until the specified timeout is reached.
     *
     * @param float|int $timeout The timeout in milliseconds before
     * continuing with the execution.
     *
     *
     * @return void
     */
    function delay(float|int $timeout): void
    {
        signal(fn (Closure $resume) => after($resume, (int) $timeout * 1000));
    }
}

if (!function_exists(__NAMESPACE__ . '\after')) {
    /**
     * Trigger the provided `$fn` after the specified timeout
     *
     * @param Closure $fn
     * @param int $timeout Timeout in milliseconds
     *
     * @return TimerInterface
     */
    function after(Closure $fn, int $timeout): TimerInterface
    {
        return Timer::after($fn, $timeout);
    }
}

if (!function_exists(__NAMESPACE__ . '\repeat')) {
    /**
     * Trigger the provided `$fn` in the set interval indefinitely until suspended
     *
     * @param Closure $fn
     * @param int $interval Interval in milliseconds
     *
     * @return TimerInterface
     */
    function repeat(Closure $fn, int $interval): TimerInterface
    {
        return Timer::interval($fn, $interval);
    }
}

if (!function_exists(__NAMESPACE__ . '\pipe')) {
    /**
     * Summary of Onion\Framework\Loop\pipe
     * @param \Onion\Framework\Loop\Interfaces\ResourceInterface $source
     * @param \Onion\Framework\Loop\Interfaces\ResourceInterface $destination
     * @param int $chunkSize
     * @param bool $sync
     *
     * @return int|null Number of bytes written or null if the operation is async
     */
    function pipe(
        ResourceInterface $source,
        ResourceInterface $destination,
        int $chunkSize = 8192,
        bool $sync = true,
    ): void {
        $sync ? signal(function ($resume) use ($source, $destination, $chunkSize) {
            while (!$source->eof()) {
                write($destination, $source->read($chunkSize));
            }

            $resume();
        }) : scheduler()->onRead(
            $source,
            Task::create(function (ResourceInterface $source, ResourceInterface $destination) use ($chunkSize) {
                while (!$source->eof()) {
                    write($destination, $source->read($chunkSize));
                }
            }, [$source, $destination], false)
        );
    }
}

if (!function_exists(__NAMESPACE__ . '\buffer')) {
    function buffer(ResourceInterface $resource, int $limit = -1, int $chunk = 4096): Buffer
    {
        $buffer = new Buffer($limit);
        read($resource, static function (ResourceInterface $resource) use (&$buffer, &$chunk) {
            while (!$resource->eof()) {
                write($buffer, $resource->read($chunk));
            }
        }, false);

        return $buffer;
    }
}

if (!function_exists(__NAMESPACE__ . '\register_default_signal_handler')) {
    function register_default_signal_handler(): void
    {
        if (!defined('CTRL_C')) {
            if (defined('PHP_WINDOWS_EVENT_CTRL_C')) {
                define('CTRL_C', PHP_WINDOWS_EVENT_CTRL_C);
            } elseif (defined('SIGINT')) {
                define('CTRL_C', SIGINT);
            } else {
                define('CTRL_C', 0);
            }
        }

        scheduler()->signal(CTRL_C, Task::create(function () {
            fwrite(STDOUT, "\nAttempting graceful termination by user request.\n");

            scheduler()->stop();
        }));
    }
}
