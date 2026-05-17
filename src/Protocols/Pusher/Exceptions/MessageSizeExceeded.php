<?php

namespace Webpatser\Resonate\Protocols\Pusher\Exceptions;

class MessageSizeExceeded extends PusherException
{
    /**
     * The error code associated with the exception.
     *
     * @var int
     */
    protected $code = 4019;

    /**
     * The error message associated with the exception.
     *
     * @var string
     */
    protected $message = 'Message size exceeded';
}
