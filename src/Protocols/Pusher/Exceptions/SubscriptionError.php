<?php

namespace Webpatser\Resonate\Protocols\Pusher\Exceptions;

/**
 * A subscribe the server refused while keeping the connection open.
 *
 * Sent as `pusher:subscription_error` naming the requested channel, with a
 * string-encoded `data` object carrying `type`, `error` and `status`, the
 * shape Pusher clients bind to for a failed subscription. The 43xx status
 * means "rejected, do not retry": everything the connection already holds
 * keeps working.
 */
abstract class SubscriptionError extends PusherException
{
    /**
     * The error code associated with the exception.
     *
     * @var int
     */
    protected $code = 4300;

    /**
     * The error type sent to the client.
     */
    protected string $type;

    /**
     * Create a new subscription error for the requested channel.
     */
    public function __construct(protected string $channel = '')
    {
        //
    }

    /**
     * Get the name of the channel the refused subscribe asked for.
     */
    public function channel(): string
    {
        return $this->channel;
    }

    /**
     * Get the Pusher formatted error payload.
     *
     * @return array{event: string, data: string|false, channel: string}
     */
    public function payload(): array
    {
        return [
            'event' => 'pusher:subscription_error',
            'data' => json_encode([
                'type' => $this->type,
                'error' => $this->message,
                'status' => $this->code,
            ]),
            'channel' => $this->channel,
        ];
    }
}
