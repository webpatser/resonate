# Changelog

All notable changes to `webpatser/resonate` are documented here.

## v0.1.1 - 2026-05-16

### Changed (breaking)
- Artisan commands renamed from `reverb:start` / `reverb:restart` / `reverb:install` to `resonate:start` / `resonate:restart` / `resonate:install`. Update supervisor / systemd / Docker entrypoints accordingly. The `laravel:reverb:restart` cache key is unchanged, so running Resonate servers still pick up restart signals from any existing tooling.

## v0.1.0 - 2026-05-16

Initial public release. Fiber-based drop-in replacement for Laravel Reverb on `webpatser/fledge-fiber`.

- Pusher wire protocol with byte-exact JSON framing (public, private, presence, cache, private-cache, presence-cache channels; client events; rate limiting; connection limits; origin verification).
- Full Pusher-compatible HTTP REST API (events, batch events, channels, channel, channel users, connections, user termination, health check) with the original HMAC signature canonicalization.
- Horizontal scaling via Redis pub/sub on fledge-fiber's async Redis. Pure JSON envelopes, no `serialize()`. Cross-node `message` / `terminate` / `metrics` propagation.
- `reverb:start`, `reverb:restart`, `reverb:install` artisan commands; PID file; signal handling; restart-cache poll; periodic prune / ping; Pulse and Telescope ingest timers.
- Pulse recorders + Livewire dashboard components (`reverb.connections`, `reverb.messages`).
