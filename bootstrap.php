<?php

declare(strict_types=1);

use function Onion\Framework\Loop\{scheduler, register_default_signal_handler};

if (!defined('EVENT_LOOP_AUTOSTART')) {
    /**
     * Should the event loop auto-start or would require explicit
     * trigger by the user. Defaults to `true`
     *
     * @var bool `true` to enable, `false` otherwise
     */
    define('EVENT_LOOP_AUTOSTART', true);
}

if (!defined('EVENT_LOOP_DEFAULT_HANDLE_SIGNALS')) {
    /**
     * Use internal signal handler that is aware of the event
     * loop. Defaults to `true`
     *
     * @var bool `true` to enable, `false` otherwise
     */
    define('EVENT_LOOP_DEFAULT_HANDLE_SIGNALS', false);
}

if (!defined('EVENT_LOOP_STREAM_IDLE_TIMEOUT')) {
    /**
     * A default timeout block the event loop if there are no tasks
     * or timers pending, specifically in situations where the server
     * is waiting for connections, etc. This would allow near instant
     * scheduling (based on the `EVENT_LOOP_STREAM_IDLE_TIMEOUT`, with
     * the default 1s it'd be as close as ~1s correct trigger).
     *
     * An obvious candidate would be implementation of a cron-like
     * functionality without leaving the application scope.
     *
     * Defaults to 1s
     * @var int timeout in microseconds.
     */
    define('EVENT_LOOP_STREAM_IDLE_TIMEOUT', 1_000_000);
}

if (EVENT_LOOP_DEFAULT_HANDLE_SIGNALS) {
    register_default_signal_handler();
}

if (EVENT_LOOP_AUTOSTART) {
    register_shutdown_function(fn () => scheduler()->start());
}
