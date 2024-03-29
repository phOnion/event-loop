<?php

namespace Onion\Framework\Event;

use Psr\EventDispatcher\{EventDispatcherInterface, ListenerProviderInterface, StoppableEventInterface};

use function Onion\Framework\Loop\signal;

class Dispatcher implements EventDispatcherInterface
{
    private ListenerProviderInterface $listenerProvider;

    public function __construct(ListenerProviderInterface $listenerProvider)
    {
        $this->listenerProvider = $listenerProvider;
    }

    public function dispatch(object $event): object
    {

        $listeners = (
            /**
             * @psalm-return \Generator<mixed, mixed, mixed, void>
             */
            function (object $event): \Generator {
                yield from $this->listenerProvider->getListenersForEvent($event);
            })($event);


        $next = function (object $event, \Generator $iterator) use (&$next): object {
            return signal(function (\Closure $resume) use ($event, &$next, $iterator) {
                if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                    $resume($event);
                    return;
                }

                $current = $iterator->current();
                if ($current) {
                    $iterator->next();
                    $resume($next($current($event) ?? $event, $iterator));
                } else {
                    $resume($event);
                }
            });
        };

        return $next($event, $listeners);
    }
}
