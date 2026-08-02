<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Resonate to handle
    | incoming messages as well as broadcasting messages to all your
    | connected clients. At this time only "reverb" is supported.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    | `drain_timeout` is the upper bound (in seconds) the server waits for
    | in-flight WebSocket connections to close after a `resonate:reload` or a
    | SIGUSR2. The listener is always bound with SO_REUSEPORT so the
    | replacement process can accept new connections during the swap; on
    | Linux the kernel load-balances new accepts across both processes,
    | which is why drain is graceful.
    |
    */

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'auth_timestamp_grace' => env('REVERB_AUTH_TIMESTAMP_GRACE', 600),

            /*
            | Resource limits on what a single connection may ask the server to
            | allocate. `max_channel_name_length` bounds the name a client can
            | subscribe to (Pusher itself caps names at 164 characters, so the
            | default leaves room and still bounds the allocation), and
            | `max_subscriptions_per_connection` bounds how many distinct
            | channels one connection may hold at once. Set either to 0 to
            | disable the check.
            */
            'max_channel_name_length' => env('REVERB_MAX_CHANNEL_NAME_LENGTH', 255),
            'max_subscriptions_per_connection' => env('REVERB_MAX_SUBSCRIPTIONS_PER_CONNECTION', 250),

            /*
            | Every connection has its own outbound queue drained by a single
            | writer fiber, so one client that stops reading no longer stalls a
            | channel broadcast. `max_outbound_queue_size` bounds how many
            | messages may wait on one connection before it is closed with
            | WebSocket code 1013 (try again later); without a bound, a client
            | that never reads is a memory exhaustion vector. Budget for it as
            | this number multiplied by your average payload multiplied by the
            | connections that can fall behind at once. Set to 0 to disable the
            | bound, which is only safe when every client is trusted.
            */
            'max_outbound_queue_size' => env('REVERB_MAX_OUTBOUND_QUEUE_SIZE', 1_000),
            'drain_timeout' => env('REVERB_DRAIN_TIMEOUT', 30),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),

                /*
                | Incoming pub/sub envelopes are queued and handled by a single
                | fiber, so a slow envelope cannot stall the subscriber pump and
                | with it every other node's broadcasts, terminate requests and
                | metrics replies. `max_queued_messages` bounds that queue;
                | envelopes arriving while it is full are dropped and logged
                | rather than buffered without limit. Set to 0 to disable.
                */
                'max_queued_messages' => env('REVERB_SCALING_MAX_QUEUED_MESSAGES', 10_000),

                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),

            /*
            | Server-side plugins. Each entry is the class name of a
            | Webpatser\Resonate\Plugins\Contracts\ServerPlugin implementation.
            | Plugins are resolved through the container, booted once at server
            | start, and may intercept inbound messages, observe the connection
            | lifecycle, and register periodic ticks on the event loop.
            */
            'plugins' => [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                'allowed_origins' => ['*'],
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'members'),
                'rate_limiting' => [
                    'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', false),
                    'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                    'decay_seconds' => env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                    'terminate_on_limit' => env('REVERB_APP_RATE_LIMIT_TERMINATE', false),
                ],
            ],
        ],

    ],

];
