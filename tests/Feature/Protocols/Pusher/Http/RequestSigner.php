<?php

namespace Webpatser\Resonate\Tests\Feature\Protocols\Pusher\Http;

use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\Request as FledgeRequest;
use Fledge\Async\Http\Server\Response as FledgeResponse;
use Mockery;
use Webpatser\Resonate\Server\Router;

use function Fledge\Async\Stream\buffer;

/**
 * Builds fledge-fiber HTTP requests signed the way the stock `pusher`
 * broadcaster signs them, so the event-dispatch controllers can be
 * exercised directly without booting the async HTTP server.
 *
 * The signing logic mirrors the canonicalization in the base
 * {@see \Webpatser\Resonate\Protocols\Pusher\Http\Controllers\Controller}.
 */
class RequestSigner
{
    /**
     * Build a signed POST request for the given path with a JSON body.
     *
     * @param  array<string, mixed>|null  $payload  The request payload, JSON encoded into the body.
     * @param  array<string, string>  $routeParams  The matched route parameters.
     */
    public static function post(
        string $path,
        ?array $payload,
        array $routeParams = ['appId' => 'app-id'],
        string $secret = 'app-secret',
    ): FledgeRequest {
        $body = $payload === null ? '' : json_encode($payload);

        return self::signed('POST', $path, $body, $routeParams, $secret);
    }

    /**
     * Build a signed request for the given method, path and body.
     *
     * @param  array<string, string>  $routeParams
     */
    public static function signed(
        string $method,
        string $path,
        string $body = '',
        array $routeParams = ['appId' => 'app-id'],
        string $secret = 'app-secret',
    ): FledgeRequest {
        $query = [
            'auth_key' => 'app-key',
            'auth_timestamp' => '1700000000',
            'auth_version' => '1.0',
        ];

        $params = $query;

        if ($body !== '') {
            $params['body_md5'] = md5($body);
        }

        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }

        $query['auth_signature'] = hash_hmac(
            'sha256',
            implode("\n", [$method, $path, implode('&', $pairs)]),
            $secret,
        );

        $uri = 'http://localhost'.$path.'?'.http_build_query($query);

        $request = new FledgeRequest(
            Mockery::mock(Client::class),
            $method,
            \League\Uri\Http::new($uri),
            [],
            $body,
        );

        $request->setAttribute(Router::class, $routeParams);

        return $request;
    }

    /**
     * Build an unsigned POST request (no auth_signature) for the given path.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $routeParams
     */
    public static function unsigned(
        string $path,
        array $payload,
        array $routeParams = ['appId' => 'app-id'],
    ): FledgeRequest {
        $body = json_encode($payload);

        $uri = 'http://localhost'.$path.'?'.http_build_query([
            'auth_key' => 'app-key',
            'auth_timestamp' => '1700000000',
            'auth_version' => '1.0',
        ]);

        $request = new FledgeRequest(
            Mockery::mock(Client::class),
            'POST',
            \League\Uri\Http::new($uri),
            [],
            $body,
        );

        $request->setAttribute(Router::class, $routeParams);

        return $request;
    }

    /**
     * Buffer the body of a fledge-fiber HTTP server response into a string.
     */
    public static function body(FledgeResponse $response): string
    {
        return buffer($response->getBody());
    }

    /**
     * Subscribe a connection to a channel, supplying the Pusher auth token
     * (and presence channel data) the channel type requires.
     */
    public static function subscribe(
        \Webpatser\Resonate\Contracts\Connection $connection,
        string $channel,
        ?array $channelData = null,
    ): void {
        $manager = app(\Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager::class)
            ->for(app(\Webpatser\Resonate\Contracts\ApplicationProvider::class)->all()->first());

        $auth = null;
        $data = null;

        if (str_starts_with($channel, 'private-')) {
            $auth = self::channelAuth($connection->id(), $channel);
        }

        if (str_starts_with($channel, 'presence-')) {
            $data = json_encode($channelData ?? ['user_id' => $connection->id(), 'user_info' => []]);
            $auth = self::channelAuth($connection->id(), $channel, $data);
        }

        $manager->findOrCreate($channel)->subscribe($connection, $auth, $data);
    }

    /**
     * Build a Pusher channel authentication token for a private/presence subscribe.
     */
    public static function channelAuth(string $socketId, string $channel, ?string $data = null): string
    {
        $signature = "{$socketId}:{$channel}";

        if ($data !== null) {
            $signature .= ":{$data}";
        }

        return 'app-key:'.hash_hmac('sha256', $signature, 'app-secret');
    }
}
