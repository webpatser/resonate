<?php

namespace Webpatser\Resonate\Protocols\Pusher\Http\Controllers;

use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Server\Request;
use Webpatser\Resonate\Server\Response;

class ChannelController extends Controller
{
    /**
     * Handle the request: GET /apps/{appId}/channels/{channel}.
     *
     * @param  array<string, string>  $parameters
     */
    protected function handle(Request $request, array $parameters): Response
    {
        $query = $request->query();

        $channel = app(MetricsHandler::class)->gather($request->application(), 'channel', [
            'channel' => $parameters['channel'] ?? '',
            'info' => isset($query['info']) ? $query['info'].',occupied' : 'occupied',
        ]);

        return Response::json((object) $channel);
    }
}
