<?php

namespace Webpatser\Resonate\Plugins\Contracts;

/**
 * A plugin capability that registers periodic callbacks on the server event loop.
 *
 * Each registered tick is scheduled with {@see \Revolt\EventLoop::repeat()} and
 * its body runs inside a fiber, so async DB/Redis calls suspend the fiber
 * rather than blocking the loop.
 */
interface TickScheduler
{
    /**
     * The periodic callbacks this plugin wants scheduled.
     *
     * `interval` is in seconds (fractional allowed). `callback` takes no
     * arguments. A callback that may run longer than its interval must guard
     * against re-entrancy itself: the loop fires the next tick regardless of
     * whether the previous fiber has finished.
     *
     * @return array<int, array{interval: float, callback: callable():void}>
     */
    public function ticks(): array;
}
