<?php

namespace Webpatser\Resonate\Protocols\Pusher\Http\Controllers;

use Fledge\Async\Http\Server\Request as FledgeRequest;
use Fledge\Async\Http\Server\RequestHandler;
use Fledge\Async\Http\Server\Response as FledgeResponse;
use Webpatser\Resonate\Server\Response;

/**
 * Health check endpoint: GET /up.
 *
 * Unauthenticated, so it does not extend the signature-verifying base
 * {@see Controller}; it implements the fledge-fiber handler directly.
 */
class HealthCheckController implements RequestHandler
{
    /**
     * Handle the request.
     */
    public function handleRequest(FledgeRequest $request): FledgeResponse
    {
        return Response::json((object) ['health' => 'OK'])->toFledgeResponse();
    }
}
