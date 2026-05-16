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
        $channels = app(MetricsHandler::class)->gather($this->application, 'channels', [
            'filter' => $this->query['filter_by_prefix'] ?? null,
            'info' => $this->query['info'] ?? null,
        ]);

        return Response::json([
            'channels' => (object) array_map(fn ($item) => (object) $item, $channels),
        ]);
    }
}
