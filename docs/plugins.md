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
