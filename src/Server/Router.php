<?php

namespace Webpatser\Resonate\Server;

use Fledge\Async\Http\HttpStatus;
use Fledge\Async\Http\Server\Request as FledgeRequest;
use Fledge\Async\Http\Server\RequestHandler;
use Fledge\Async\Http\Server\Response as FledgeResponse;

/**
 * A plain prefix/regex router for the Resonate HTTP server.
 *
 * This replaces Reverb's Symfony Routing based router. fledge-fiber ships its
 * own FastRoute based router, but Resonate uses a self-contained matcher so
 * route matching is trivially unit-testable without booting the event loop and
 * so the (later phase) HTTP API controllers slot in with no extra wiring.
 *
 * The router itself is a fledge-fiber {@see RequestHandler}: it is registered
 * directly with the {@see \Fledge\Async\Http\Server\SocketHttpServer}.
 */
class Router implements RequestHandler
{
    /**
     * The registered routes.
     *
     * @var array<int, Route>
     */
    protected array $routes = [];

    /**
     * Create a new router instance.
     *
     * @param  string  $prefix  Optional path prefix applied to every route.
     */
    public function __construct(protected string $prefix = '')
    {
        $this->prefix = $this->normalizePrefix($prefix);
    }

    /**
     * Get the configured path prefix.
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get all of the registered routes.
     *
     * @return array<int, Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Register a route for any HTTP method.
     */
    public function add(string $method, string $path, RequestHandler $handler): Route
    {
        $route = new Route(
            strtoupper($method),
            $this->prefix.'/'.ltrim($path, '/'),
            $handler,
        );

        $this->routes[] = $route;

        return $route;
    }

    /**
     * Register a GET route.
     */
    public function get(string $path, RequestHandler $handler): Route
    {
        return $this->add('GET', $path, $handler);
    }

    /**
     * Register a POST route.
     */
    public function post(string $path, RequestHandler $handler): Route
    {
        return $this->add('POST', $path, $handler);
    }

    /**
     * Match a method and path against the registered routes.
     *
     * Returns the matched route and its extracted parameters, or null when no
     * route matches.
     *
     * @return array{0: Route, 1: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            $parameters = $route->match($method, $path);

            if ($parameters !== null) {
                return [$route, $parameters];
            }
        }

        return null;
    }

    /**
     * Handle an incoming fledge-fiber HTTP request.
     */
    public function handleRequest(FledgeRequest $request): FledgeResponse
    {
        $match = $this->match($request->getMethod(), $request->getUri()->getPath());

        if ($match === null) {
            return new FledgeResponse(
                HttpStatus::NOT_FOUND,
                ['content-type' => 'text/plain; charset=utf-8'],
                'Not found.',
            );
        }

        [$route, $parameters] = $match;

        // Expose the matched route parameters to the handler the same way
        // fledge-fiber's own router does, keyed by this class.
        $request->setAttribute(self::class, $parameters);

        return $route->handler()->handleRequest($request);
    }

    /**
     * Normalize the path prefix: no trailing slash, single leading slash.
     */
    protected function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix, '/');

        return $prefix === '' ? '' : '/'.$prefix;
    }
}
