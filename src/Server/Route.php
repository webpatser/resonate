<?php

namespace Webpatser\Resonate\Server;

use Fledge\Async\Http\Server\RequestHandler;

/**
 * A single registered route.
 *
 * Holds the HTTP method, a compiled regular expression for the path (with
 * named capture groups for {placeholder} segments) and the fledge-fiber
 * request handler invoked on a match.
 */
class Route
{
    /**
     * The compiled path pattern.
     */
    protected string $pattern;

    /**
     * The names of the {placeholder} parameters in the path, in order.
     *
     * @var array<int, string>
     */
    protected array $parameters = [];

    /**
     * Create a new route instance.
     */
    public function __construct(
        protected string $method,
        protected string $path,
        protected RequestHandler $handler,
    ) {
        $this->pattern = $this->compile($path);
    }

    /**
     * Get the HTTP method for the route.
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Get the (uncompiled) path for the route.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Get the request handler for the route.
     */
    public function handler(): RequestHandler
    {
        return $this->handler;
    }

    /**
     * Determine whether the route matches the given method and path.
     *
     * Returns the matched route parameters on a match, or null on no match.
     *
     * @return array<string, string>|null
     */
    public function match(string $method, string $path): ?array
    {
        if (strcasecmp($method, $this->method) !== 0) {
            return null;
        }

        if (! preg_match($this->pattern, $this->normalize($path), $matches)) {
            return null;
        }

        $parameters = [];

        foreach ($this->parameters as $name) {
            if (isset($matches[$name]) && $matches[$name] !== '') {
                $parameters[$name] = rawurldecode($matches[$name]);
            }
        }

        return $parameters;
    }

    /**
     * Compile a path with {placeholder} segments into a regular expression.
     *
     * Each `{name}` segment becomes a named capture group matching a single
     * path segment; all other characters are matched literally.
     */
    protected function compile(string $path): string
    {
        $this->parameters = [];

        $segments = $this->normalize($path) === '/'
            ? ['']
            : explode('/', trim($this->normalize($path), '/'));

        $compiled = [];

        foreach ($segments as $segment) {
            if (preg_match('#^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$#', $segment, $match)) {
                $this->parameters[] = $match[1];
                $compiled[] = '(?P<'.$match[1].'>[^/]+)';

                continue;
            }

            $compiled[] = preg_quote($segment, '#');
        }

        return '#^/'.implode('/', $compiled).'$#';
    }

    /**
     * Normalize a path: ensure a single leading slash and strip the trailing one.
     */
    protected function normalize(string $path): string
    {
        $path = '/'.trim($path, '/');

        return $path;
    }
}
