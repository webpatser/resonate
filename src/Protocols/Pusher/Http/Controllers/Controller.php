<?php

namespace Webpatser\Resonate\Protocols\Pusher\Http\Controllers;

use Fledge\Async\Http\Server\Request as FledgeRequest;
use Fledge\Async\Http\Server\RequestHandler;
use Fledge\Async\Http\Server\Response as FledgeResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Exceptions\InvalidApplication;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Http\Exceptions\HttpException;
use Webpatser\Resonate\Server\Request;
use Webpatser\Resonate\Server\Response;
use Webpatser\Resonate\Server\Router;

/**
 * Base controller for the Pusher-compatible HTTP REST API.
 *
 * Reverb's controllers are PSR-7 + Symfony-Routing based and call `verify()`
 * themselves from `__invoke()`. Resonate adapts that to the fledge-fiber
 * {@see RequestHandler} interface with a template-method `handleRequest()`:
 * it wraps the fledge request, reads the matched route parameters, runs the
 * (verbatim-ported) signature verification, then delegates to the concrete
 * controller's `handle()`. The HMAC canonicalization in `verifySignature()`
 * is byte-for-byte the same as Reverb's (and as `pusher/pusher-php-server`'s
 * own signer) so the host app's stock `pusher` broadcaster signs requests
 * Resonate accepts unchanged. Inputs that canonicalization cannot represent
 * unambiguously are rejected rather than re-encoded: see
 * {@see ensureQueryParametersAreUnambiguous()}.
 */
abstract class Controller implements RequestHandler
{
    /**
     * Handle an incoming fledge-fiber HTTP request.
     */
    public function handleRequest(FledgeRequest $request): FledgeResponse
    {
        $parameters = $request->getAttribute(Router::class) ?? [];
        $resonateRequest = new Request($request);

        try {
            $this->verify($resonateRequest, $parameters['appId'] ?? null);

            return $this->handle($resonateRequest, $parameters)->toFledgeResponse();
        } catch (HttpException $e) {
            return Response::json(
                (object) ['error' => $e->getMessage()],
                $e->getStatusCode(),
            )->toFledgeResponse();
        } catch (Throwable $e) {
            return Response::json(
                (object) ['error' => 'Server error.'],
                500,
            )->toFledgeResponse();
        }
    }

    /**
     * Handle the verified request and produce a response.
     *
     * @param  array<string, string>  $parameters  The matched route parameters.
     */
    abstract protected function handle(Request $request, array $parameters): Response;

    /**
     * Verify that the incoming request is valid.
     *
     * @throws HttpException
     */
    protected function verify(Request $request, ?string $appId): void
    {
        // Request-scoped state lives on the per-request {@see Request} wrapper,
        // never on the controller instance: the controllers are registered as
        // singletons in the router, so any state stored on `$this` would bleed
        // across concurrent fiber-handled requests (cross-app disclosure).
        $this->setApplication($request, $appId);
        $this->setChannels($request);
        $this->verifySignature($request);
    }

    /**
     * Resolve the application for the request's application ID onto the request.
     *
     * @throws HttpException
     */
    protected function setApplication(Request $request, ?string $appId): void
    {
        if (! $appId) {
            throw new HttpException(400, 'Application ID not provided.');
        }

        try {
            $request->setApplication(app(ApplicationProvider::class)->findById($appId));
        } catch (InvalidApplication) {
            throw new HttpException(404, 'No matching application for ID ['.$appId.'].');
        }
    }

    /**
     * Resolve the channel manager for the request's application onto the request.
     */
    protected function setChannels(Request $request): void
    {
        $request->setChannels(app(ChannelManager::class)->for($request->application()));
    }

    /**
     * Verify the Pusher authentication signature.
     *
     * @throws HttpException
     */
    protected function verifySignature(Request $request): void
    {
        $query = $request->query();
        $body = $request->getBody();

        $params = Arr::except($query, [
            'auth_signature', 'body_md5', 'body_sha256', 'appId', 'appKey', 'channelName',
        ]);

        $this->ensureQueryParametersAreUnambiguous($params);

        $params = array_merge($params, $this->bodyDigestsForVerification($query, $body));

        ksort($params);

        $path = $request->getPath();

        if ($prefix = config('reverb.servers.reverb.path')) {
            $path = '/'.ltrim(Str::after($path, rtrim($prefix, '/')), '/');
        }

        $signature = implode("\n", [
            $request->getMethod(),
            $path,
            $this->formatQueryParametersForVerification($params),
        ]);

        $signature = hash_hmac('sha256', $signature, $request->application()->secret());
        $authSignature = $query['auth_signature'] ?? '';

        if (! is_string($authSignature) || ! hash_equals($signature, $authSignature)) {
            throw new HttpException(401, 'Authentication signature invalid.');
        }

        $this->verifyTimestamp($request);
    }

    /**
     * Reject expired or missing `auth_timestamp` values to bound the replay window.
     *
     * The Pusher canonical string already binds the timestamp into the HMAC, so
     * a signed request cannot be re-signed at a later time, but the verifier
     * never checks the timestamp itself. This closes that gap by rejecting any
     * request whose timestamp is more than `auth_timestamp_grace` seconds out
     * of sync. Set the config to `0` to disable the check (matches Reverb's
     * pre-fix behaviour).
     *
     * @throws HttpException
     */
    protected function verifyTimestamp(Request $request): void
    {
        $grace = (int) config('reverb.servers.reverb.auth_timestamp_grace', 600);

        if ($grace <= 0) {
            return;
        }

        $timestamp = $request->query()['auth_timestamp'] ?? null;

        if (! is_string($timestamp) || ! ctype_digit($timestamp)) {
            throw new HttpException(401, 'Authentication timestamp missing or invalid.');
        }

        if (abs(time() - (int) $timestamp) > $grace) {
            throw new HttpException(401, 'Authentication timestamp out of range.');
        }
    }

    /**
     * Reject query parameters the canonical signing string cannot represent unambiguously.
     *
     * The canonical string is `key=value` pairs joined with `&`, with no
     * escaping whatsoever. That is not our choice: it is what
     * `pusher/pusher-php-server` signs (`Pusher::array_implode('=', '&', ...)`),
     * so re-encoding here would reject every request the stock Laravel `pusher`
     * broadcaster sends. The ambiguity is real though: a `&` or `=` inside a
     * value is indistinguishable from a separator, so a caller who influences
     * one signed value can smuggle in or delete other signed parameters and
     * still land on the same signed string. Arrays are worse: `a[]=x&a[]=y`
     * canonicalized identically to `a=x,y`, and a nested array stringified to
     * the literal `Array`, leaving the nested values effectively unsigned.
     *
     * Rejecting the ambiguous input rather than changing the canonical form
     * keeps ordinary scalar queries byte-identical on the wire. Nothing that
     * previously worked is lost: the SDK only ever signs scalars, and where it
     * does accept an array parameter it signs `info=a,b` while Guzzle sends
     * `info[0]=a&info[1]=b`, which never verified in the first place.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws HttpException
     */
    protected function ensureQueryParametersAreUnambiguous(array $params): void
    {
        foreach ($params as $key => $value) {
            if (! is_scalar($value)) {
                throw new HttpException(400, 'Signed query parameters must be scalar values.');
            }

            if ($this->isAmbiguousInSignature((string) $key) || $this->isAmbiguousInSignature((string) $value)) {
                throw new HttpException(400, 'Signed query parameters must not contain signature separators.');
            }
        }
    }

    /**
     * Determine whether the given string contains a canonical-string separator.
     */
    protected function isAmbiguousInSignature(string $value): bool
    {
        // `&` and `=` separate the pairs; `\n` separates method, path and query.
        return strpbrk($value, "&=\n\r") !== false;
    }

    /**
     * Get the body digests to bind into the signature.
     *
     * The body is bound to the signature by digest, and the digest the caller
     * signed is recomputed here from the body actually received, so a swapped
     * body fails verification. `body_md5` is a Pusher wire-protocol field and
     * every stock client sends it, so it has to keep working; MD5 chosen-prefix
     * collisions are practical though, so a client that also (or only) signs
     * `body_sha256` gets that bound instead. When both are supplied both are
     * bound, which means the stronger digest has to hold too.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    protected function bodyDigestsForVerification(array $query, string $body): array
    {
        $digests = [];

        if (array_key_exists('body_sha256', $query)) {
            $digests['body_sha256'] = hash('sha256', $body);
        }

        if (array_key_exists('body_md5', $query)) {
            $digests['body_md5'] = md5($body);
        }

        // Stock Pusher clients always send `body_md5` alongside a body, but a
        // request that carries a body and no digest at all must still bind it.
        if ($digests === [] && $body !== '') {
            $digests['body_md5'] = md5($body);
        }

        return $digests;
    }

    /**
     * Format the given parameters into the correct format for signature verification.
     *
     * Every value is a scalar by the time this runs: anything else was already
     * rejected by {@see ensureQueryParametersAreUnambiguous()}.
     *
     * @param  array<string, scalar>  $params
     */
    protected static function formatQueryParametersForVerification(array $params): string
    {
        return collect($params)
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode('&');
    }
}
