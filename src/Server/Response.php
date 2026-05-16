<?php

namespace Webpatser\Resonate\Server;

use Fledge\Async\Http\HttpStatus;
use Fledge\Async\Http\Server\Response as FledgeResponse;

/**
 * Thin adapter for building fledge-fiber HTTP server responses.
 *
 * Controllers (added in a later phase) build a Resonate response and the
 * transport unwraps it into the underlying fledge-fiber response.
 */
class Response
{
    /**
     * Create a new response adapter instance.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function __construct(
        protected string $body = '',
        protected int $status = HttpStatus::OK,
        protected array $headers = [],
    ) {
        //
    }

    /**
     * Create a new JSON response.
     *
     * @param  array<string, mixed>|object  $data
     * @param  array<string, string|array<int, string>>  $headers
     */
    public static function json(array|object $data, int $status = HttpStatus::OK, array $headers = []): self
    {
        return new self(
            json_encode($data),
            $status,
            array_merge(['content-type' => 'application/json'], $headers),
        );
    }

    /**
     * Create a new plain text response.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    public static function text(string $body, int $status = HttpStatus::OK, array $headers = []): self
    {
        return new self(
            $body,
            $status,
            array_merge(['content-type' => 'text/plain; charset=utf-8'], $headers),
        );
    }

    /**
     * Get the response status code.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Get the response body.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Get the response headers.
     *
     * @return array<string, string|array<int, string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Set a response header.
     */
    public function withHeader(string $name, string|array $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Convert the adapter into a fledge-fiber HTTP server response.
     */
    public function toFledgeResponse(): FledgeResponse
    {
        return new FledgeResponse(
            $this->status,
            $this->headers,
            $this->body,
        );
    }
}
