# Resonate

Fiber-based drop-in replacement for Laravel Reverb, built on `webpatser/fledge-fiber` and PHP 8.5+.

## Why

Reverb is already async, but it pulls in its own ReactPHP / Ratchet / clue-redis stack. Resonate consolidates the runtime onto Fledge: Revolt + `webpatser/fledge-fiber`, the same async stack that powers `webpatser/torque`, `webpatser/laravel-fiber`, and `webpatser/laravel-resp3-cache`. The wins are practical:

- **PHP 8.5 only.** No polyfills, no `version_compare`, native URI parser, native `array_all` / `array_any`.
- **One async runtime per app.** Fledge's HTTP server gives HTTP/2 and shares the loop with the rest of your async work, with no second event loop competing for the request.
- **Fiber ergonomics.** Channel auth, application providers, and pub/sub callbacks read like synchronous code but yield on I/O. Custom auth backends can hit a database or HTTP API without blocking the tick.

The wire protocol, REST API, and config schema are byte-compatible with Laravel Reverb.

## Install (fresh app)

```bash
composer require webpatser/resonate
php artisan resonate:install
php artisan resonate:start
```

## Install (swap from `laravel/reverb`)

```bash
composer remove laravel/reverb
composer require webpatser/resonate
```

That's it. Nothing else changes:

- Same `config/reverb.php`. Resonate reads the existing file.
- New artisan commands: `resonate:start`, `resonate:restart`, `resonate:reload`, `resonate:install`. Update supervisor / systemd / Docker entrypoints accordingly.
- Same `laravel:reverb:restart` cache key. Running servers restart on the same signal.
- Same Pusher wire protocol (byte-exact JSON framing) and the same Pusher-compatible REST API.
- Supervisor / systemd / Docker configs stay as-is.
- Front-end Echo and `pusher-js` configs stay as-is.

## Zero-downtime reload

`resonate:restart` is the legacy hard restart: it sets the `laravel:reverb:restart` cache key, the running server picks it up within 5 seconds, calls `stop()`, and your supervisor respawns it. WebSocket connections drop; the listener is gone for the 0-5 second window between exit and respawn. Fine for development, rough for production deploys.

`resonate:reload` is the production path. The listener is bound with `SO_REUSEPORT` so the new process can hold the port while the old one drains.

```bash
# Default: spawn a replacement, wait for /up, then drain the old PID.
php artisan resonate:reload

# Drain only (for systemd ExecReload=, Kubernetes preStop, Supervisor).
php artisan resonate:reload --drain
```

Tune the drain window with `REVERB_DRAIN_TIMEOUT` (default `30` seconds). Existing WebSocket clients stay connected to the old process until they disconnect naturally or the timeout fires.

## Horizontal scaling

Set `REVERB_SCALING_ENABLED=true` along with your `REDIS_*` variables. Multiple Resonate instances coordinate via Redis pub/sub on `fledge-fiber`'s async Redis client; `message`, `terminate`, and `metrics` events propagate across nodes.

Resonate uses a **pure JSON** envelope for cross-node messages, with no `serialize()` on the wire. This means a cluster cannot run mixed Resonate and `laravel/reverb` nodes; migration is all-at-once.

## Server-side plugins

Resonate is a product-agnostic Pusher relay, but the fiber runtime makes it a natural host for stateful, server-side application logic - periodic timers, custom message types, connection bookkeeping - without a second process. The plugin API exposes that without coupling Resonate to any one product.

A plugin implements `ServerPlugin` plus any of three capability interfaces:

- **`MessageInterceptor`** - `onMessage(Connection, array $event): MessageDisposition`. Runs before the standard `pusher:` / `client-*` routing. Return `Handled` or `Rejected` to consume a custom event type, or `Relay` (the default for traffic you don't own) to leave ordinary Pusher messages untouched.
- **`ConnectionLifecycle`** - `onOpen` / `onClose` / `onSubscribe` / `onUnsubscribe`. Observe connection transitions to maintain your own registries.
- **`TickScheduler`** - `ticks()` returns `[{interval, callback}]`. Each callback is scheduled on the event loop inside a fiber, so async DB/Redis calls suspend the fiber rather than blocking the loop.

Plugins receive a `PluginContext` at `boot()` with `sendTo()`, `broadcast()` (scaling-aware), `terminate()`, `unsubscribe()`, and `connectionsOn()`. `broadcast()` and `connectionsOn()` take an `Application`, an app id string, or `null` for the sole configured app, and the context resolves one itself via `application()` / `applications()` - so a `TickScheduler` callback, which has no connection to derive an app from, can still broadcast. Per-connection state lives on the `Connection` via `setState()` / `state()`. Register plugin classes in `config/reverb.php`:

```php
'servers' => [
    'reverb' => [
        // ...
        'plugins' => [
            App\Resonate\ChatPlugin::class,
        ],
    ],
],
```

Plugin classes are resolved through the container (so their dependencies inject), booted once at server start, and every hook call is exception-isolated - a misbehaving plugin can never break the core connection lifecycle.

### First-party plugins

A small family of plugins ships under `webpatser/*`. Pick the ones you need; each is opt-in, each has its own README with the full setup.

| Package | What it does |
|---------|--------------|
| [`webpatser/resonate-roster`](https://github.com/webpatser/resonate-roster) | Cluster-wide presence and channel-occupancy state in Redis. Restart-safe, self-healing, queryable from the backend without a metrics round-trip. |
| [`webpatser/resonate-webhooks`](https://github.com/webpatser/resonate-webhooks) | Pusher-style HTTP webhooks (`channel_occupied`/`channel_vacated`, `member_added`/`member_removed`, `client_event`). Signed, exactly-once per cluster via the roster. |
| [`webpatser/resonate-user-cap`](https://github.com/webpatser/resonate-user-cap) | Per-user connection cap with cluster-correct enforcement. Terminates over-cap connections with a Pusher error frame. |
| [`webpatser/resonate-token-auth`](https://github.com/webpatser/resonate-token-auth) | Token-based subscribe auth (JWT by default, pluggable). Lets mobile and S2S clients skip `/broadcasting/auth`. |
| [`webpatser/resonate-delivery`](https://github.com/webpatser/resonate-delivery) | At-least-once message delivery within a retention window: every broadcast logged to a Redis Stream, replayed to reconnecting subscribers. |
| [`webpatser/resonate-pulse`](https://github.com/webpatser/resonate-pulse) | Laravel Pulse cards for the suite: roster occupancy, webhook deliveries, user-cap terminations, token-auth rejections. |

A companion Laravel-side package, **not** a Resonate plugin, that consumes the webhooks:

| Package | What it does |
|---------|--------------|
| [`webpatser/resonate-channel-meter`](https://github.com/webpatser/resonate-channel-meter) | Records billable and observable channel occupancy periods from `resonate-webhooks` events as Eloquent models in your Laravel app. |

See [`docs/plugins.md`](docs/plugins.md) for the same list with framing notes, plus a full setup walkthrough with a worked plugin you can build yourself.

## Requirements

- PHP `^8.5`
- Laravel `^13.0`

Optional integrations:

- **`laravel/pulse`**: Resonate registers the `reverb.connections` and `reverb.messages` Livewire dashboard components automatically.
- **`laravel/telescope`**: entry storage for inspecting connections, channels, and messages.

## Acknowledgements

Resonate is a clean-room port of [`laravel/reverb`](https://github.com/laravel/reverb) (MIT, © Taylor Otwell, Joe Dixon). Several files (notably the Pusher protocol layer and the Pulse dashboard cards) are direct ports of Reverb's MIT-licensed code. See [`LICENSE.md`](LICENSE.md) for the full attribution.

## License

MIT. See [`LICENSE.md`](LICENSE.md).
