# Changelog

All notable changes to `webpatser/resonate` are documented here.

## v0.1.2 - 2026-05-17

Pre-1.0 security audit and hardening. Closes 10 audit findings (1 High, 4 Medium, 3 Low, 2 Info) and adds 45 adversarial test cases (212 to 257 tests). See `SECURITY.md` for the updated threat model.

### Added

- HTTP REST API now verifies `auth_timestamp` is within `auth_timestamp_grace` seconds of the server clock (default `600`). Tune via `REVERB_AUTH_TIMESTAMP_GRACE`, or set to `0` to disable. Bounds the replay window for captured signed requests (e.g., `users/terminate_connections`).
- `max_message_size` is now enforced on incoming WebSocket frames. Oversized frames are dropped with pusher error code `4019` before reaching the logger, the rate limiter, or the JSON parser. The config key was decorative in v0.1.0 and v0.1.1.
- `resonate:install` emits a production hardening reminder (tighten `allowed_origins`, set `REVERB_SERVER_HOST=127.0.0.1` if behind a reverse proxy, rotate the generated `REVERB_APP_SECRET` for non-dev environments).
- Origin verification now matches case-insensitively. `example.com` matches `EXAMPLE.com`.
- Scaling: malformed pub/sub envelopes (bad JSON, missing fields, unknown app id, oversized payload) are caught and logged via `Log::error`. A single bad envelope can no longer disrupt the receive loop.

### Changed (breaking only for non-default configurations)

- `accept_client_events_from = 'all'` now applies the same subscription check and `private-` / `presence-` channel-type check as `'members'`. The two modes only differ in how the membership claim is sourced; channel isolation is enforced in both. Apps that relied on `'all'` to broadcast `client-*` events on public channels or from non-subscribed clients will need to redesign. This diverges from Laravel Reverb's behaviour.
- `max_connections` now counts open WebSocket connections, not only connections subscribed to a channel. Unsubscribed clients occupy a slot. Operators who tuned the limit against subscriber load may need to revisit it.
- `Log::message` for incoming WebSocket frames now runs inside the rate-limit-guarded try block. Frames rejected by the rate limiter are no longer decoded, sanitized, pretty-printed, or written to the log.
- WebSocket app-key fallback regex (`WebSocketHandler::appKey()`) is now anchored and length-capped at 128 characters. The primary route-attribute path is unchanged.

### Tests

- New `tests/Unit/Protocols/Pusher/OriginVerificationTest.php` (13 cases). The audit found zero existing coverage for `Server::verifyOrigin`.
- New `tests/Unit/Server/WebSocketHandlerTest.php` covers app-key extraction adversarial cases.
- New `tests/Feature/Console/InstallCommandTest.php`.
- Expanded `ControllerTest`, `PrivateChannelTest`, `PresenceChannelTest`, `PusherPubSubIncomingMessageHandlerTest`, `ServerTest`, and `ClientEventTest` with adversarial cases (wrong secret, missing auth, malformed auth, socket-id binding, tampered channel_data, method/path substitution, body tampering, oversized scaling payloads, public-channel client-event rejection, user_id spoofing).

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
