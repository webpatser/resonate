<?php

namespace Webpatser\Resonate\Protocols\Pusher\Http\Controllers;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Webpatser\Resonate\Protocols\Pusher\EventDispatcher;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Server\Request;
use Webpatser\Resonate\Server\Response;

class EventsBatchController extends Controller
{
    /**
     * Handle the request: POST /apps/{appId}/batch_events.
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

        $items = collect($payload['batch'])->map(function ($item) {
            EventDispatcher::dispatch(
                $this->application,
                [
                    'event' => $item['name'],
                    'channel' => $item['channel'],
                    'data' => $item['data'],
                ],
                isset($item['socket_id'])
                    ? $this->channels->findConnection($item['socket_id'])?->connection()
                    : null,
            );

            return isset($item['info']) ? app(MetricsHandler::class)->gather(
                $this->application,
                'channel',
                ['channel' => $item['channel'], 'info' => $item['info']],
            ) : [];
        });

        if ($items->contains(fn ($item) => ! empty($item))) {
            return Response::json([
                'batch' => $items->map(fn ($item) => (object) $item)->all(),
            ]);
        }

        return Response::json(['batch' => (object) []]);
    }

    /**
     * Create a validator for the incoming request payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function validator(array $payload): Validator
    {
        return ValidatorFacade::make($payload, [
            'batch' => ['required', 'array'],
            'batch.*.name' => ['required', 'string'],
            'batch.*.data' => ['required', 'string'],
            'batch.*.channel' => ['required_without:channels', 'string'],
            'batch.*.socket_id' => ['string'],
            'batch.*.info' => ['string'],
        ]);
    }
}
