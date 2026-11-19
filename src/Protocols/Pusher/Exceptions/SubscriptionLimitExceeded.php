<?php

namespace Webpatser\Resonate\Protocols\Pusher\Exceptions;

/**
 * The connection already holds as many channels as it may.
 *
 * Re-subscribing to a channel it already holds never raises this.
 */
class SubscriptionLimitExceeded extends SubscriptionError
{
    /**
     * The error type sent to the client.
     */
    protected string $type = 'LimitReached';

    /**
     * The error message associated with the exception.
     *
     * @var string
     */
    protected $message = 'Connection is over channel quota';
}
