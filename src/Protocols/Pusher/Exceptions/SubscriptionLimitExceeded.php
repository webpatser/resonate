<?php

namespace Webpatser\Resonate\Protocols\Pusher\Exceptions;

class SubscriptionLimitExceeded extends PusherException
{
    /**
     * The error code associated with the exception.
     *
     * The 43xx range means "the request was rejected, do not retry", which is
     * what this is: the connection stays open and everything it is already
     * subscribed to keeps working. The 41xx range would tell the client to
     * reconnect with backoff, which cannot help here.
     *
     * @var int
     */
    protected $code = 4302;

    /**
     * The error message associated with the exception.
     *
     * @var string
     */
    protected $message = 'Subscription limit exceeded';
}
