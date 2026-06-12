<?php

namespace Webpatser\Resonate\Protocols\Pusher\Http\Controllers;

use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Server\Request;
use Webpatser\Resonate\Server\Response;

class ChannelsController extends Controller
{
    /**
     * Handle the request: GET /apps/{appId}/channels.
     *
     * @param  array<string, string>  $parameters
     */
    protected function handle(Request $request, array $parameters): Response
    {
        $query = $request->query();

        $channels = app(MetricsHandler::class)->gather($request->application(), 'channels', [
            'filter' => $query['filter_by_prefix'] ?? null,
            'info' => $query['info'] ?? null,
        ]);

        return Response::json([
            'channels' => (object) array_map(fn ($item) => (object) $item, $channels),
        ]);
    }
}
