<?php

namespace Webpatser\Resonate\Protocols\Pusher\Http\Controllers;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Webpatser\Resonate\Protocols\Pusher\EventDispatcher;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Server\Request;
use Webpatser\Resonate\Server\Response;

class EventsController extends Controller
{
    /**
     * Handle the request: POST /apps/{appId}/events.
     *
     * @param  array<string, string>  $parameters
     */
    protected function handle(Request $request, array $parameters): Response
    {
        $payload = json_decode($this->body, associative: true, flags: JSON_THROW_ON_ERROR);

        $validator = $this->validator($payload);

        if ($validator->fails()) {
            return Response::json($validator->errors(), 422);
        }

        $channels = Arr::wrap($payload['channels'] ?? $payload['channel'] ?? []);

        $except = null;

        if ($socketId = $payload['socket_id'] ?? null) {
            $except = $this->channels->findConnection($socketId);
        }

        EventDispatcher::dispatch(
            $this->application,
            [
                'event' => $payload['name'],
                'channels' => $channels,
                'data' => $payload['data'],
            ],
            $except ? $except->connection() : null,
        );

        if (isset($payload['info'])) {
            $channels = app(MetricsHandler::class)->gather(
                $this->application,
                'channels',
                ['info' => $payload['info'], 'channels' => $channels],
            );

            return Response::json([
                'channels' => array_map(fn ($channel) => (object) $channel, $channels),
            ]);
        }

        return Response::json((object) []);
    }

    /**
     * Create a validator for the incoming request payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function validator(array $payload): Validator
    {
        return ValidatorFacade::make($payload, [
            'name' => ['required', 'string'],
            'data' => ['required', 'string'],
            'channels' => ['required_without:channel', 'array'],
            'channel' => ['required_without:channels', 'string'],
            'socket_id' => ['string'],
            'info' => ['string'],
        ]);
    }
}
