# Server-side plugins

Resonate is a product-agnostic Pusher relay. A **plugin** lets a host application run its own stateful logic inside the Resonate process - periodic timers, custom message types, connection bookkeeping - without standing up a second service and without Resonate ever depending on that product.

Because the server runs on a fiber runtime, a plugin can do async DB or Redis work from a timer and the loop keeps serving other connections while it waits.

## When to reach for a plugin

| You want to... | Capability |
|----------------|------------|
| Handle a custom client event type yourself | `MessageInterceptor` |
| Track connections in your own registry | `ConnectionLifecycle` |
| Push something to clients on a schedule | `TickScheduler` |

If all you need is to broadcast from your app to clients, you do not need a plugin: use ordinary Laravel broadcasting. Plugins are for logic that has to live *inside* the socket server.

## First-party plugins

Before building your own, check whether one of these already does it. Each is a separate, opt-in `webpatser/*` package with its own README; install only what you need.

### Inside the server (register in `config/reverb.php` `plugins`)

| Package | Fast explainer |
|---------|----------------|
| [`webpatser/resonate-roster`](https://github.com/webpatser/resonate-roster) | Mirrors every presence (or all) channel into Redis so "who is online" survives restarts, stays cluster-correct, and is queryable from the backend. Self-healing per-node keys with TTL; works under scaling. |
| [`webpatser/resonate-webhooks`](https://github.com/webpatser/resonate-webhooks) | Emits Pusher-style HTTP webhooks: `channel_occupied`, `channel_vacated`, `member_added`, `member_removed`, `client_event`. Signed; edges claimed exactly-once per cluster via the roster, so a scaled deployment never double-sends `occupied`. Coalesced async delivery off the connection path. |
| [`webpatser/resonate-user-cap`](https://github.com/webpatser/resonate-user-cap) | Caps the cluster-wide connection count per presence `user_id`. Resonate's built-in `max_connections` caps per *app*; this caps per *user*. Over-cap connections get a Pusher error frame and close. |
| [`webpatser/resonate-token-auth`](https://github.com/webpatser/resonate-token-auth) | Token-based subscribe auth (JWT default, pluggable authenticator + authorizer). Lets mobile and S2S clients skip the `/broadcasting/auth` HMAC round-trip. Coexists with standard HMAC; both flows work side by side on the same server. |
| [`webpatser/resonate-delivery`](https://github.com/webpatser/resonate-delivery) | At-least-once message delivery within a bounded retention window. Logs every broadcast to a per-channel Redis Stream and replays missed messages to a reconnecting subscriber that supplies a `last_event_id`. Solves the universal "I dropped for 20s and missed messages" problem. |
| [`webpatser/resonate-pulse`](https://github.com/webpatser/resonate-pulse) | Laravel Pulse cards visualizing the suite: roster occupancy, webhook delivery/failure throughput, user-cap terminations, token-auth rejections by reason. Recorders are opted in per plugin you have installed. |

### Outside the server (Laravel-side webhook consumer)

| Package | Fast explainer |
|---------|----------------|
| [`webpatser/resonate-channel-meter`](https://github.com/webpatser/resonate-channel-meter) | Receives `resonate-webhooks` deliveries in your Laravel app and writes channel occupancy periods as Eloquent records (`channel_meter_periods`, with a polymorphic `model` relation and a `HasChannelMeter` trait). Drop-in for billing or analytics that needs "how long was this chat occupied". |

A few combinations earn their keep together:

- **roster + webhooks + channel-meter** — push channel activity to the Laravel app and have it bill or audit channel sessions. The original *roster → webhooks → meter* arc.
- **roster + webhooks + pulse** — see the whole cluster's behaviour on the Pulse dashboard. The pulse cards consume the events the webhooks plugin emits.
- **token-auth + user-cap** — let mobile clients authenticate without cookies and cap their device fan-out.
- **delivery + anything** — reconnect-replay is independent and pairs with every other plugin.

For full setup, config, security notes, and protocol details, follow the link to each package's README. The rest of this document is for building your own plugin.

## The contracts

Every plugin implements the `ServerPlugin` marker contract and any mix of three capability interfaces. They all live in `Webpatser\Resonate\Plugins\Contracts`.

```php
interface ServerPlugin
{
    // Booted once at server start, on the loop, before connections are accepted.
    public function boot(PluginContext $context): void;
}

interface MessageInterceptor
{
    // Runs before the standard pusher: / client-* routing.
    public function onMessage(Connection $from, array $event): MessageDisposition;
}

interface ConnectionLifecycle
{
    public function onOpen(Connection $connection): void;
    public function onClose(Connection $connection): void;
    public function onSubscribe(Connection $connection, Channel $channel): void;
    public function onUnsubscribe(Connection $connection, Channel $channel): void;
}

interface TickScheduler
{
    // [['interval' => float seconds, 'callback' => callable(): void], ...]
    public function ticks(): array;
}
```

### Message dispositions

`onMessage()` returns a `MessageDisposition`:

- `Relay`: not your event; fall through to the normal `pusher:` / `client-*` routing. **Return this for anything you do not own** so ordinary Pusher traffic is never disturbed.
- `Handled`: you consumed the message; skip core routing.
- `Rejected`: you refused the message; skip core routing. You are responsible for having sent your own error frame to the connection.

When every plugin returns `Relay`, routing is byte-identical to a server with no plugins at all.

## A worked example

A plugin that answers a custom `app:whoami` event, tags every connection with the time it opened, and broadcasts a server heartbeat every 30 seconds.

```php
<?php

namespace App\Resonate;

use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Plugins\Contracts\ConnectionLifecycle;
use Webpatser\Resonate\Plugins\Contracts\MessageInterceptor;
use Webpatser\Resonate\Plugins\Contracts\ServerPlugin;
use Webpatser\Resonate\Plugins\Contracts\TickScheduler;
use Webpatser\Resonate\Plugins\MessageDisposition;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;

class HeartbeatPlugin implements ConnectionLifecycle, MessageInterceptor, ServerPlugin, TickScheduler
{
    private PluginContext $context;

    private int $online = 0;

    // Dependencies are injected by the container - constructor injection works.
    public function __construct(private readonly \Psr\Log\LoggerInterface $log)
    {
        //
    }

    public function boot(PluginContext $context): void
    {
        // Keep the context; open long-lived resources (DB/Redis pools) here.
        $this->context = $context;
    }

    public function onMessage(Connection $from, array $event): MessageDisposition
    {
        // Only act on the event you own; everything else relays untouched.
        if ($event['event'] !== 'app:whoami') {
            return MessageDisposition::Relay;
        }

        $this->context->sendTo($from, 'app:whoami:result', [
            'socket_id' => $from->id(),
            'connected_at' => $from->state('hb.connected_at'),
        ]);

        return MessageDisposition::Handled;
    }

    public function onOpen(Connection $connection): void
    {
        $this->online++;

        // Per-connection state - namespace your keys to avoid plugin collisions.
        $connection->setState('hb.connected_at', now()->toIso8601String());
    }

    public function onClose(Connection $connection): void
    {
        $this->online--;
    }

    public function onSubscribe(Connection $connection, Channel $channel): void
    {
        $this->log->info('subscribed', ['channel' => $channel->name()]);
    }

    public function onUnsubscribe(Connection $connection, Channel $channel): void
    {
        $this->log->info('unsubscribed', ['channel' => $channel->name()]);
    }

    public function ticks(): array
    {
        return [
            [
                'interval' => 30.0,
                'callback' => function (): void {
                    // No connection here, so let the context resolve the app.
                    $this->context->broadcast(null, 'system', 'app:heartbeat', [
                        'online' => $this->online,
                        'at' => now()->toIso8601String(),
                    ]);
                },
            ],
        ];
    }
}
```

A plugin only implements the interfaces it needs. A timer-only plugin implements `ServerPlugin` + `TickScheduler` and nothing else.

## Registration

Add the plugin class to the `plugins` array of your server in `config/reverb.php`:

```php
'servers' => [
    'reverb' => [
        // ...
        'plugins' => [
            App\Resonate\HeartbeatPlugin::class,
        ],
    ],
],
```

Each entry is a class name. Plugins are:

- **resolved through the container**, so their constructor dependencies inject;
- **booted once** at server start, on the loop, before the first connection;
- loaded in array order, and that order is also the `onMessage()` interceptor order (see below).

Restart the server (`php artisan resonate:start`, or `resonate:reload` for a zero-downtime swap) to pick up plugin changes.

## The `PluginContext` API

`boot()` receives a `PluginContext`, the supported way to reach into the server without touching internals.

| Method | Purpose |
|--------|---------|
| `sendTo(Connection $c, string $event, array $data = [])` | Push one event to one connection. |
| `broadcast($app, string $channel, string $event, array $data = [], ?Connection $except = null)` | Send to every connection on a channel. Routed through the event dispatcher, so with scaling enabled it reaches every node. |
| `terminate(Connection $c, ?string $event = null, array $data = [])` | Optionally send a final event, then close the connection (local node only). |
| `unsubscribe(Connection $c, string $channel)` | Remove a connection from one channel, leaving the socket and its other subscriptions intact. |
| `connectionsOn($app, string $channel): array` | The live `ChannelConnection`s for a channel on this node. |
| `application(?string $id = null): Application` | Resolve one application. |
| `applications(): Collection` | Every configured application. |

### Resolving an application

`broadcast()` and `connectionsOn()` take `$app` as an `Application`, an app id string, or `null`:

- inside `onMessage` / `onOpen` / `onClose` / `onSubscribe` you have a `Connection`, so pass `$connection->app()`;
- inside a `ticks()` callback there is no connection, so pass an app id string, or `null` to use the sole configured app.

On a server with more than one app configured, `null` is ambiguous: `application(null)` throws `InvalidApplication`. Pass the id explicitly.

## Per-connection state

`Connection` carries a plugin-owned state bag. It is per-socket and lives for the whole connection, unlike presence `channel_data` which is per-channel and is lost on unsubscribe.

```php
$connection->setState('hb.connected_at', now());   // store
$connection->state('hb.connected_at');             // read (null if absent)
$connection->state('hb.role', 'guest');            // read with a default
$connection->state();                              // the whole bag
$connection->hasState('hb.connected_at');          // bool
$connection->forgetState('hb.connected_at');       // remove
```

Namespace your keys (`hb.*`, `chat.*`) so two plugins never collide.

## Exception isolation

Every plugin call - `boot()`, `onMessage()`, the lifecycle hooks, `ticks()`, and each scheduled tick body - is wrapped in a `try/catch`. A plugin that throws is logged and skipped; it can never break the core connection lifecycle, the message loop, or another plugin. A throwing `onMessage()` is treated as `Relay`.

## Notes and caveats

- **Ticks are not re-entrant for you.** The loop fires the next tick on schedule whether or not the previous one finished. If a callback can outrun its interval, guard against overlap yourself (for example, a boolean "running" flag in plugin state).
- **`onUnsubscribe` vs `onClose`.** `onUnsubscribe` fires for the explicit `pusher:unsubscribe` event - a connection leaving one channel while staying open. A connection that closes is reported once through `onClose`, not as one `onUnsubscribe` per channel. `PluginContext::unsubscribe()` is a direct server-side action and does not itself emit `onUnsubscribe`.
- **Reading the presence `user_id`.** In `onSubscribe` / `onUnsubscribe` you get the `Connection` and the `Channel`. For a presence channel the subscribing user's id lives in the `ChannelConnection`: `$channel->connections()[$connection->id()]?->data('user_id')`.
- **`terminate()` is local.** It only ends connections on the node that runs it. Cross-node termination needs a pub/sub envelope.
- **Async work belongs in fibers.** Tick callbacks already run inside a fiber, so `await`-style suspending calls are fine. Do not call blocking I/O.
- **Keep plugins product-specific in your app.** Resonate ships the contracts; the plugin classes live in your application, not in this package.
