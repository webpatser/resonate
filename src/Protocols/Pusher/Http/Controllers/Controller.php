<?php

namespace Webpatser\Resonate\Protocols\Pusher\Http\Controllers;

use Fledge\Async\Http\Server\Request as FledgeRequest;
use Fledge\Async\Http\Server\RequestHandler;
use Fledge\Async\Http\Server\Response as FledgeResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
use Webpatser\Resonate\Application;
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
 * is byte-for-byte the same as Reverb's so the host app's stock `pusher`
 * broadcaster signs requests Resonate accepts unchanged.
 */
abstract class Controller implements RequestHandler
{
    /**
     * Current application instance.
     */
    protected ?Application $application = null;

    /**
     * Active channels for the application.
     */
    protected ?ChannelManager $channels = null;

    /**
     * The incoming request's body.
     */
    protected ?string $body = null;

    /**
     * The incoming request's query parameters.
     *
     * @var array<string, mixed>
     */
    protected array $query = [];

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
        $this->body = $request->getBody();
        $this->query = $request->query();

        $this->setApplication($appId);
        $this->setChannels();
        $this->verifySignature($request);
    }

    /**
     * Set the application instance for the incoming request's application ID.
     *
     * @throws HttpException
     */
    protected function setApplication(?string $appId): Application
    {
        if (! $appId) {
            throw new HttpException(400, 'Application ID not provided.');
        }

        try {
            return $this->application = app(ApplicationProvider::class)->findById($appId);
        } catch (InvalidApplication) {
            throw new HttpException(404, 'No matching application for ID ['.$appId.'].');
        }
    }

    /**
     * Set the channel manager instance for the application.
     */
    protected function setChannels(): void
    {
        $this->channels = app(ChannelManager::class)->for($this->application);
    }

    /**
     * Verify the Pusher authentication signature.
     *
     * @throws HttpException
     */
    protected function verifySignature(Request $request): void
    {
        $params = Arr::except($this->query, [
            'auth_signature', 'body_md5', 'appId', 'appKey', 'channelName',
        ]);

        if ($this->body !== '') {
            $params['body_md5'] = md5($this->body);
        }

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

        $signature = hash_hmac('sha256', $signature, $this->application->secret());
        $authSignature = $this->query['auth_signature'] ?? '';

        if (! is_string($authSignature) || ! hash_equals($signature, $authSignature)) {
            throw new HttpException(401, 'Authentication signature invalid.');
        }

        $this->verifyTimestamp();
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
    protected function verifyTimestamp(): void
    {
        $grace = (int) config('reverb.servers.reverb.auth_timestamp_grace', 600);

        if ($grace <= 0) {
            return;
        }

        $timestamp = $this->query['auth_timestamp'] ?? null;

        if (! is_string($timestamp) || ! ctype_digit($timestamp)) {
            throw new HttpException(401, 'Authentication timestamp missing or invalid.');
        }

        if (abs(time() - (int) $timestamp) > $grace) {
            throw new HttpException(401, 'Authentication timestamp out of range.');
        }
    }

    /**
     * Format the given parameters into the correct format for signature verification.
     *
     * @param  array<string, mixed>  $params
     */
    protected static function formatQueryParametersForVerification(array $params): string
    {
        return collect($params)->map(function ($value, $key) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }

            return "{$key}={$value}";
        })->implode('&');
    }
}
