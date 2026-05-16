<?php

namespace Webpatser\Resonate\Server;

use Fledge\Async\Http\Server\Request as FledgeRequest;

/**
 * Thin adapter around the fledge-fiber HTTP server Request.
 *
 * Exposes the subset of request data the Resonate HTTP controllers need
 * without leaking the underlying transport (or PSR-7) into the protocol layer.
 */
class Request
{
    /**
     * The parsed query string parameters.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $query = null;

    /**
     * The buffered request body.
     */
    protected ?string $body = null;

    /**
     * Create a new request adapter instance.
     */
    public function __construct(protected FledgeRequest $request)
    {
        //
    }

    /**
     * Get the underlying fledge-fiber request.
     */
    public function base(): FledgeRequest
    {
        return $this->request;
    }

    /**
     * Get the HTTP request method.
     */
    public function getMethod(): string
    {
        return $this->request->getMethod();
    }

    /**
     * Get the request path.
     */
    public function getPath(): string
    {
        return $this->request->getUri()->getPath();
    }

    /**
     * Get the full request URI as a string.
     */
    public function getUri(): string
    {
        return (string) $this->request->getUri();
    }

    /**
     * Get the request host.
     */
    public function getHost(): string
    {
        return $this->request->getUri()->getHost();
    }

    /**
     * Get the raw query string.
     */
    public function getQueryString(): string
    {
        return $this->request->getUri()->getQuery();
    }

    /**
     * Get all of the query string parameters.
     *
     * @return array<string, mixed>
     */
    public function query(): array
    {
        if ($this->query === null) {
            parse_str($this->getQueryString(), $query);

            $this->query = $query;
        }

        return $this->query;
    }

    /**
     * Get a single query string parameter.
     */
    public function queryParameter(string $key, mixed $default = null): mixed
    {
        return $this->query()[$key] ?? $default;
    }

    /**
     * Get the buffered request body.
     */
    public function getBody(): string
    {
        return $this->body ??= $this->request->getBody()->buffer();
    }

    /**
     * Get a single request header value.
     */
    public function header(string $name): ?string
    {
        return $this->request->getHeader($name);
    }

    /**
     * Get all values for a request header.
     *
     * @return array<int, string>
     */
    public function headerArray(string $name): array
    {
        return $this->request->getHeaderArray($name);
    }

    /**
     * Get all of the request headers.
     *
     * @return array<string, array<int, string>>
     */
    public function headers(): array
    {
        return $this->request->getHeaders();
    }

    /**
     * Get the request origin header, if present.
     */
    public function origin(): ?string
    {
        return $this->request->getHeader('origin');
    }
}
