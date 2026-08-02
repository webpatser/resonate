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
 *
 * The response carries the PID of the process that answered. During a
 * `resonate:reload` both the old and the new server are bound to the same port
 * via SO_REUSEPORT, so the kernel is free to hand a probe connection to either
 * one. Without an identity in the body a probe cannot tell them apart, and a
 * replacement that died during boot would be reported healthy by the very
 * process it was meant to replace. `health` is kept so external monitors that
 * only look at the status code and that field are unaffected.
 */
class HealthCheckController implements RequestHandler
{
    /**
     * Handle the request.
     */
    public function handleRequest(FledgeRequest $request): FledgeResponse
    {
        return Response::json((object) [
            'health' => 'OK',
            'pid' => getmypid(),
        ])->toFledgeResponse();
    }
}
