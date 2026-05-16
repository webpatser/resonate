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
- New artisan commands: `resonate:start`, `resonate:restart`, `resonate:install`. Update supervisor / systemd / Docker entrypoints accordingly.
- Same `laravel:reverb:restart` cache key. Running servers restart on the same signal.
- Same Pusher wire protocol (byte-exact JSON framing) and the same Pusher-compatible REST API.
- Supervisor / systemd / Docker configs stay as-is.
- Front-end Echo and `pusher-js` configs stay as-is.

## Horizontal scaling

Set `REVERB_SCALING_ENABLED=true` along with your `REDIS_*` variables. Multiple Resonate instances coordinate via Redis pub/sub on `fledge-fiber`'s async Redis client; `message`, `terminate`, and `metrics` events propagate across nodes.

Resonate uses a **pure JSON** envelope for cross-node messages, with no `serialize()` on the wire. This means a cluster cannot run mixed Resonate and `laravel/reverb` nodes; migration is all-at-once.

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
