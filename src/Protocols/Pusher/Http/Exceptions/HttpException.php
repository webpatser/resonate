<?php

namespace Webpatser\Resonate\Protocols\Pusher\Http\Exceptions;

use Exception;

/**
 * A transport-level HTTP error raised while handling a Pusher REST API request.
 *
 * Resonate does not depend on symfony/http-kernel, so this is a lightweight
 * stand-in for Reverb's `Symfony\Component\HttpKernel\Exception\HttpException`.
 */
class HttpException extends Exception
{
    /**
     * Create a new HTTP exception instance.
     */
    public function __construct(protected int $statusCode, string $message = '')
    {
        parent::__construct($message);
    }

    /**
     * Get the HTTP status code for the exception.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
