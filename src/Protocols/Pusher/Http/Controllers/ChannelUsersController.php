<?php

namespace Webpatser\Resonate\Protocols\Pusher\Http\Controllers;

use Illuminate\Support\Str;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Protocols\Pusher\ChannelName;
use Webpatser\Resonate\Protocols\Pusher\Concerns\InteractsWithChannelInformation;
use Webpatser\Resonate\Protocols\Pusher\Http\Exceptions\HttpException;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Server\Request;
use Webpatser\Resonate\Server\Response;

class ChannelUsersController extends Controller
{
    use InteractsWithChannelInformation;

    /**
     * Handle the request: GET /apps/{appId}/channels/{channel}/users.
     *
     * @param  array<string, string>  $parameters
     *
     * @throws HttpException
     */
    protected function handle(Request $request, array $parameters): Response
    {
        $name = $parameters['channel'] ?? '';

        if (! ChannelName::isValid($name)) {
            throw new HttpException(400, 'Invalid channel name.');
        }

        $channel = $request->requireChannels()->find($name);

        if ($channel) {
            if (! $this->isPresenceChannel($channel)) {
                throw new HttpException(400, 'Users can only be retrieved for presence channels.');
            }
        } elseif (! $this->isScaled()) {
            throw new HttpException(404, 'Channel not found.');
        } elseif (! Str::startsWith($name, 'presence-')) {
            // Under scaling a local miss says nothing about the fleet, so the
            // channel type is decided from its name instead of the registry.
            throw new HttpException(400, 'Users can only be retrieved for presence channels.');
        }

        $users = app(MetricsHandler::class)->gather($request->requireApplication(), 'channel_users', [
            'channel' => $name,
        ]);

        // A channel with no members on any node does not exist anywhere.
        if (! $channel && $users === []) {
            throw new HttpException(404, 'Channel not found.');
        }

        return Response::json(['users' => $users]);
    }

    /**
     * Determine whether this node is one of a scaled fleet.
     */
    protected function isScaled(): bool
    {
        return app()->bound(ServerProvider::class)
            && app(ServerProvider::class)->shouldPublishEvents();
    }
}
