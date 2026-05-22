# Changelog

All notable changes to `webpatser/resonate` are documented here.

## v0.4.0 - 2026-05-22

Completes the plugin connection lifecycle. v0.3.0 shipped `onSubscribe` but no `onUnsubscribe`, and `PluginContext::connectionsOn()` (read) with no write-side counterpart. A plugin could see a connection join a channel but not leave one short of a full disconnect, and could not evict a connection from a single channel without terminating the whole socket. Both gaps are now closed.

### Added

- `ConnectionLifecycle::onUnsubscribe(Connection, Channel)` - fires when a connection leaves a channel via the explicit `pusher:unsubscribe` event.
- `PluginManager::notifyUnsubscribe()` - exception-isolated fan-out for the new hook.
- `PluginContext::unsubscribe(Connection, string $channel)` - removes a connection from one channel, leaving the socket and its other subscriptions intact. A direct action; it does not itself emit `onUnsubscribe`.

### Changed

- `ConnectionLifecycle` gains a fourth method, `onUnsubscribe`. Breaking for existing implementers of the interface; the plugin system is one release old, so the contract is completed before it settles.
- `EventHandler::unsubscribe()` fires the `onUnsubscribe` lifecycle hook after removing the connection from the channel. A closing connection is still reported once through `onClose`, not as one unsubscribe per channel.

### Tests

- New `tests/Unit/Plugins/` cases: `onUnsubscribe` fires on `pusher:unsubscribe`, a throwing `onUnsubscribe` is isolated, a closing connection reports `onClose` and not `onUnsubscribe`, and `PluginContext::unsubscribe()` removes a connection from a channel without firing the hook. Suite grows to 317 tests.

## v0.3.0 - 2026-05-21

Server-side plugin API. Resonate stays a product-agnostic Pusher relay, but a host application can now load `ServerPlugin` classes into the server process to intercept messages, observe the connection lifecycle, and run periodic ticks on the event loop. Ordinary Pusher traffic is unaffected: when every plugin returns `Relay`, routing is byte-identical to before.

### Added

- `Webpatser\Resonate\Plugins` namespace: `ServerPlugin` marker contract plus the `MessageInterceptor`, `ConnectionLifecycle`, and `TickScheduler` capability interfaces.
- `MessageDisposition` enum (`Handled` / `Rejected` / `Relay`) returned by interceptors.
- `PluginManager` - capability-indexed registry; every fan-out call (`interceptMessage`, `notifyOpen/Close/Subscribe`, `boot`, `ticks`) is exception-isolated so a plugin error cannot break the core.
- `PluginContext` - the API handed to plugins at boot: `sendTo()`, `broadcast()` (routes via `EventDispatcher` so it is scaling-aware), `terminate()`, `connectionsOn()`, plus `application()` / `applications()` so a `TickScheduler` callback (which has no connection to derive an app from) can still resolve an `Application`. `broadcast()` and `connectionsOn()` accept an `Application`, an app id string, or `null` for the sole configured app.
- Per-connection plugin state bag on the `Connection` contract: `setState()`, `state()`, `hasState()`, `forgetState()` - per-socket state that outlives presence `channel_data`.
- New config key `reverb.servers.reverb.plugins`, an array of `ServerPlugin` class names resolved through the container.
- `Scheduler` (`Webpatser\Resonate\Scheduling\Scheduler`) - a thin layer over the Revolt event loop for the server's periodic tasks. Every task (`repeat()` / `delay()`) runs inside a fiber and is exception-isolated, named for logging, and cancellable (`cancel()` / `cancelAll()`).

### Changed

- `Server::message()` runs the interceptor chain after validation and before the `pusher:` / `client-*` split; `Server::open()` / `close()` and `EventHandler::afterSubscribe()` fire the lifecycle hooks.
- A message a plugin marks `Handled` or `Rejected` no longer fires the `MessageReceived` event or logs `Message Handled`; both are now scoped to traffic the core actually routed (`Relay`).
- `StartServer` registers all periodic work (restart poll, cyclic GC, connection maintenance, Pulse/Telescope ingest, plugin ticks) through the `Scheduler` instead of bare `EventLoop::repeat` calls, so every task is fiber-wrapped and exception-isolated, not only plugin ticks. Periodic tasks are cancelled when the server begins to drain or stop.

### Tests

- New `tests/Unit/Plugins/` suite (22 cases) plus the `tests/Fakes/FakeServerPlugin.php` reference plugin: relay/handled/rejected dispositions, lifecycle hook firing, throwing-plugin isolation (message, boot, and lifecycle paths), tick collection, connection state, `MessageReceived` scoping, `PluginContext` (`sendTo` / `broadcast` / `terminate` / `connectionsOn` / application resolution), and config-driven plugin registration.
- New `tests/Unit/Scheduling/SchedulerTest.php` (6 cases) plus `tests/Fakes/RecordingLogger.php`: task registration / introspection, single and bulk cancellation, throwing-task isolation, and one-shot run-once semantics. Suite grows to 313 tests.

### Docs

- New `docs/plugins.md` - a full setup walkthrough with a worked plugin implementing all three capabilities.

## v0.2.0 - 2026-05-17

Zero-downtime reload for CI/CD deployments. Existing `resonate:restart` (hard restart, drops connections, 0-5s listener gap) is unchanged; the new flow lets a replacement process take over the port via `SO_REUSEPORT` while the outgoing process finishes its in-flight WebSocket connections.

### Added

- `resonate:reload` artisan command. Default mode spawns a detached `resonate:start` child, polls `/up` until it answers `200`, then signals the old PID to drain. `--drain` skips the spawn step for setups where systemd / Kubernetes / Supervisor brings up the replacement (`ExecReload=`, preStop hook, parallel unit).
- `HttpServer::drain(int $timeout)`: closes only the listening sockets and lets in-flight HTTP / WebSocket connections finish on their own. A watchdog hard-stops the loop after the configured timeout if clients refuse to disconnect.
- `SIGUSR2` handler in `resonate:start` routes to `drain()` with the timeout from `reverb.servers.reverb.drain_timeout`. Existing `SIGINT` / `SIGTERM` / `SIGTSTP` continue to call the hard `stop()`.
- New config key `reverb.servers.reverb.drain_timeout` (`REVERB_DRAIN_TIMEOUT`, default `30`).
- The HTTP listener is now bound with `SO_REUSEPORT` so the replacement process can hold the port at the same time as the outgoing one during a reload. No-op with a single listener.

### Changed

- `resonate:start` only unlinks `storage/resonate.pid` on shutdown when the file still points at its own PID. After a reload swap, the new server has already rewritten the file with its PID; the draining old server must not clobber it.

### Tests

- New `tests/Feature/Console/ReloadServerTest.php` (5 cases): no PID, drain-only signal delivery, spawner failure, health-timeout escalation, full reload.
- New `tests/Unit/Server/HttpServerDrainTest.php` (3 cases): drain primitive (early-return on a never-started server, idempotence, public method shape).
- New `tests/Feature/Console/RestartServerTest.php` (3 cases) and `tests/Unit/Console/StartServerSignalRoutingTest.php` (7 cases) and `tests/Unit/Console/StartServerPidFileTest.php` (10 cases) backfill coverage gaps the audit surfaced. Total suite grows from 257 to 285 tests.

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
