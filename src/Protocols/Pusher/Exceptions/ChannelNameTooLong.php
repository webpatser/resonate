<?php

namespace Webpatser\Resonate\Protocols\Pusher\Exceptions;

/**
 * The requested channel name is longer than `max_channel_name_length`.
 */
class ChannelNameTooLong extends SubscriptionError
{
    /**
     * The error type sent to the client.
     */
    protected string $type = 'InvalidChannelName';

    /**
     * The error message associated with the exception.
     *
     * @var string
     */
    protected $message = 'Channel name is too long';
}
