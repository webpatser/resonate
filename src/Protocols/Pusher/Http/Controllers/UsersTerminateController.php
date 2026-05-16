<?php

namespace Webpatser\Resonate\Protocols\Pusher\Http\Controllers;

use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;
use Webpatser\Resonate\Server\Request;
use Webpatser\Resonate\Server\Response;

class UsersTerminateController extends Controller
{
    /**
     * Handle the request: POST /apps/{appId}/users/{userId}/terminate_connections.
     *
     * @param  array<string, string>  $parameters
     */
    protected function handle(Request $request, array $parameters): Response
    {
        $userId = $parameters['userId'] ?? null;

        // When the server subscribes to pub/sub events, publish a `terminate`
        // envelope so sibling nodes drop the user's connections too. Mirrors
        // Reverb's UsersTerminateController, but with a pure-JSON envelope
        // (the application is its `id()` string, never a serialized object)
        // and no React promise; fledge publishes are fiber-blocking.
        if (app()->bound(ServerProvider::class) && app(ServerProvider::class)->subscribesToEvents()) {
            app(PubSubProvider::class)->publish([
                'type' => 'terminate',
                'application' => $this->application->id(),
                'payload' => ['user_id' => $userId],
            ]);
        }

        $connections = collect($this->channels->connections());

        $connections->each(function ($connection) use ($userId) {
            if ((string) $connection->data('user_id') === $userId) {
                $connection->disconnect();
            }
        });

        return Response::json((object) []);
    }
}
