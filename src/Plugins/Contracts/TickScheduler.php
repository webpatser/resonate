<?php

namespace Webpatser\Resonate\Plugins\Contracts;

use Revolt\EventLoop;
use Webpatser\Resonate\Scheduling\Scheduler;

/**
 * A plugin capability that registers periodic callbacks on the server event loop.
 *
 * Each registered tick is scheduled through the {@see Scheduler}, which builds
 * on {@see EventLoop::repeat()} and runs the body inside a fiber, so async
 * DB/Redis calls suspend the fiber rather than blocking the loop.
 */
interface TickScheduler
{
    /**
     * The periodic callbacks this plugin wants scheduled.
     *
     * `interval` is in seconds (fractional allowed). `callback` takes no
     * arguments. Since v0.6.0 runs are serialised per registration, so a
     * callback that outlives its interval needs no re-entrancy guard of its
     * own: the scheduler skips the next tick, and logs that it did, while the
     * previous fiber is still pending. Registrations do not block each other,
     * so one slow tick never delays another plugin's.
     *
     * @return array<int, array{interval: float, callback: callable():void}>
     */
    public function ticks(): array;
}
