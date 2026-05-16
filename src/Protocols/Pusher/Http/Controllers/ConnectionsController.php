<?php

namespace Webpatser\Resonate\Protocols\Pusher\Http\Controllers;

use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Server\Request;
use Webpatser\Resonate\Server\Response;

class ConnectionsController extends Controller
{
    /**
     * Handle the request: GET /apps/{appId}/connections.
     *
     * @param  array<string, string>  $parameters
     */
    protected function handle(Request $request, array $parameters): Response
    {
        $connections = app(MetricsHandler::class)->gather($this->application, 'connections');

        return Response::json(['connections' => count($connections)]);
    }
}
