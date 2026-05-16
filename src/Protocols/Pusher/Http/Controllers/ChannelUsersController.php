<?php

namespace Webpatser\Resonate\Protocols\Pusher\Http\Controllers;

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
        $channel = $this->channels->find($parameters['channel'] ?? '');

        if (! $channel) {
            throw new HttpException(404, 'Channel not found.');
        }

        if (! $this->isPresenceChannel($channel)) {
            throw new HttpException(400, 'Users can only be retrieved for presence channels.');
        }

        $users = app(MetricsHandler::class)->gather($this->application, 'channel_users', [
            'channel' => $channel->name(),
        ]);

        return Response::json(['users' => $users]);
    }
}
