# Changelog

All notable changes to `webpatser/resonate` are documented here.

## Unreleased

### Changed

- Require `webpatser/fledge-fiber` `^13.29` (was `^13.4`). The floor is where `RedisConfig::fromParameters()` landed, and the range below it is where the Redis client learned to percent-decode URI credentials, send a two-argument `AUTH` for ACL usernames, accept `rediss://`, and back off instead of storming a reconnect after a wire parse failure.
- `RedisPubSubProvider` builds its connection with `RedisConfig::fromParameters()` instead of assembling a `redis://user:pass@host:port/db` string. The hand-built URI could not carry a `tls` or `rediss` scheme, a unix socket path, `read_timeout`, the retry settings, a client name or tcp keepalive, so every one of those was dropped on the way to the connection. A `url` in the scaling server config is still honoured, since that form is a URI already.

### Fixed

- `Plugins\Contracts\TickScheduler` and `docs/plugins.md` told plugin authors that the loop fires the next tick whether or not the previous one finished, and that a slow callback must guard against overlap itself. v0.6.0 made the scheduler serialise runs per registration; both now describe what the server actually does.

## v0.6.1 - 2026-08-27

Patch release porting the one substantive fix from Reverb v1.11.1 (2026-08-11).

### Fixed
- `ArrayChannelManager::connections()` now prefers a connection wrapper that carries a `user_id` over an anonymous wrapper for the same socket when flattening channels (Reverb #399). A socket subscribed to several channels appears once per channel under the same identifier, and the previous `+=` merge kept whichever channel was iterated first; since only some subscriptions carry the user identity, `terminate_connections` could report success while the socket stayed connected, on both the HTTP path and the cross-node pub/sub path, and app-wide metrics could under-count identified users. Reverb's companion `data('user_id')` accessor hardening was already in place here since v0.4.1.

### Parity
- Reverb v1.11.1 reviewed in full: #399 ported (above), #396 is a CI checkout-action bump with nothing to port. No unreleased upstream commits beyond v1.11.1 at the time of this release.

## v0.6.0 - 2026-08-02

Correctness and hardening release from a full audit of the server and its plugin family. Several changes touch public API or what the server accepts, which is why this is a minor bump. Read Upgrading first.

### Upgrading

#### API changes

| Before | After | Action |
|--------|-------|--------|
| `ChannelManager::incrementConnectionCount()`, `decrementConnectionCount()` | `addConnection(Connection $connection)`, `removeConnection(Connection $connection)` | Pass the connection. `connectionCount(): int` is unchanged; the new `openConnections(): array` returns every open connection keyed by socket id, including those that never subscribed. |
| `ChannelManager::for()` re-scoped the manager and returned `$this` | Returns an immutable per-application view | Code that captured the manager and relied on re-scoping must pass the application explicitly. An unscoped manager throws `RuntimeException`. |
| `Concerns\InteractsWithApplications` | Removed | The trait only supplied the mutable `for()`. Implementations declare `for()` themselves. |
| `ConnectionPruned->connection` was a `ChannelConnection` | Is the `Connection` | Listeners calling `data()` or `connection()` on the payload must change. Those calling `id()`, `app()` or `lastSeenAt()` are unaffected. |
| `PubSubProvider::publish(): void` | `publish(): int`, the delivered subscriber count | Custom providers must return a count. Return `0` when the backend cannot report one; metrics gathering then falls back to the full collection window. |
| `Factory::make()` and `makeRouter()` took `$maxMessageSize` | Take `$fallbackMessageSize`, plus a trailing `$maxOutboundQueueSize` | Positional callers are unaffected. Anyone passing `maxMessageSize:` as a named argument gets a fatal. |
| `ArrayChannelManager` had no constructor | `__construct(ChannelRegistry $registry = new ChannelRegistry, ?Application $application = null)` | Both defaulted, so `new ArrayChannelManager` still works. `for()` returns `self`, not `static`, so a subclass must override it to keep its own type. |
| `StartServer::handle(): void` | `handle(): int` | Subclasses overriding `handle()` must return an exit code. |
| `ReloadServer::$spawner` was `callable(): ?int` | `callable(array{host, port, path}): ?int` | Test stubs must accept the server options. |
| `ReloadServer::$probe` returned `bool` | Returns the answering PID as `?int`, and takes the path as a third argument | Test stubs must return the PID they represent. |
| `Connection::send()` wrote to the socket before returning | Queues and returns | Callers can no longer treat a returned `send()` as "delivered". `close()` is ordered behind the queue, so pending frames still flush first. |

#### Behaviour changes

| Area | Change | Action |
|------|--------|--------|
| Signed REST requests | A signed query parameter that is not scalar, or whose key or value contains `&`, `=`, `\n` or `\r`, is rejected with `400` before the HMAC is computed. | None for `pusher/pusher-php-server`, which never produces either shape. Hand-rolled signers passing arrays or ambiguous values must drop the parameter. |
| Request body binding | `body_sha256` is accepted and bound alongside `body_md5`. | None. Additive; no client must change. |
| Empty request bodies | A `body_md5` or `body_sha256` carried on a request with an empty body is now bound to the digest of the empty string; it used to be dropped. | Hand-rolled signers that send a body digest on a bodyless request must sign the empty-string digest. `pusher/pusher-php-server` omits the field. |
| Origin allow-list | An entry carrying a scheme (`https://example.com`) now matches scheme, host and port, with default ports normalized away. | None for bare entries (`example.com`, `*.example.com`), which still match host only. Add a scheme where the distinction matters. |
| Origin wildcard | The `'*'` check is strict, so a config expression yielding a non-string truthy value no longer disables origin verification. | Configure `'*'` as a string if you intend permissive matching. |
| Rate limiting config | With `rate_limiting.enabled` true, `max_attempts` and `decay_seconds` must each be a positive integer, validated at boot. | Supply both keys. A server missing either now fails to start and names the offending key. |
| Rate limiting keys | Counted per client address as well as per socket id, under a `resonate:message:` prefix (was `reverb:message:`). | A client that trips the limit no longer resets its quota by reconnecting. Behind a reverse proxy the client dimension keys on the proxy's address and so applies to all traffic through it: review your quota before upgrading. |
| Subscriptions | An over-cap subscribe is rejected with pusher code `4302`, an over-long channel name with `4200`. The connection and its existing subscriptions stay intact. | Raise `max_subscriptions_per_connection` or `max_channel_name_length` if your application exceeds the 250 and 255 defaults. |
| Message size | Each connection's frame parser is sized from its own application's `max_message_size` at the handshake; an oversized frame is refused on its header with close code `1009`. | The global limit is now the smallest configured limit, not the largest, and applies only to an upgrade whose app key resolves to nothing. A client relying on a larger app's limit now gets a `1009` close instead of a `pusher:error` frame. |
| Outbound queue | A connection whose outbound queue exceeds `max_outbound_queue_size` is closed with WebSocket code `1013`. | Raise the bound, or set it to `0`, if you fan out bursts larger than 1000 queued messages per connection. |
| `resonate:start` | Refuses to start when the PID file names a live process. | Pass `--force` to start anyway. `resonate:reload` passes it for you. |
| `resonate:reload` | Returns a failure exit code when the old server is still alive after SIGTERM and `--term-timeout`. It previously returned success unconditionally. | Deploy pipelines will surface wedged old processes that were silently tolerated before. |
| Scaled metrics | `GET /apps/{id}/channels` and `/apps/{id}/channels/{channel}` no longer count the requesting node twice. | `user_count` and `subscription_count` drop to their true values in any scaled deployment. Re-baseline dashboards and alerts. |
| Scheduled tasks | A tick is skipped and logged when the previous run of the same registration has not finished. | Tick counts drop for tasks that outlive their interval. Plugin `ticks()` callbacks no longer overlap themselves. |
| `GET /up` | The response body gains `pid` beside the existing `health` field. | None for monitors reading the status code or `health`. Exact-body matching on `{"health":"OK"}` breaks. |

#### New configuration keys

Published `config/reverb.php` files without these keys get the defaults.

| Key (under `servers.reverb`) | Environment variable | Default |
|------|----------------------|---------|
| `max_channel_name_length` | `REVERB_MAX_CHANNEL_NAME_LENGTH` | `255` |
| `max_subscriptions_per_connection` | `REVERB_MAX_SUBSCRIPTIONS_PER_CONNECTION` | `250` |
| `max_outbound_queue_size` | `REVERB_MAX_OUTBOUND_QUEUE_SIZE` | `1000` |
| `scaling.max_queued_messages` | `REVERB_SCALING_MAX_QUEUED_MESSAGES` | `10000` |

Set any of them to `0` to disable the check.

### Security

- Give every connection a bounded outbound queue drained by a single writer fiber, so a peer that stops reading no longer stalls the channel fan-out or the fiber that issued the broadcast. Ordering per connection is unchanged.
- Handle inbound pub/sub envelopes on a bounded queue behind a single worker, so a slow envelope no longer holds up every other node's broadcasts, terminate requests and metrics replies.
- Reject signed REST query parameters the canonical string cannot represent unambiguously with `400`: non-scalars, and keys or values containing `&`, `=`, `\n` or `\r`. Two different queries could otherwise share one signed string.
- Accept and bind `body_sha256` alongside `body_md5`, so a client can bind its body with SHA-256 rather than a digest with practical chosen-prefix collisions.
- Match origin allow-list entries that carry a scheme against scheme, host and port; `parse_url()` previously discarded both, so an HTTPS entry admitted `http://` and alternate ports.
- Compare the `'*'` origin wildcard strictly, so an allow-list built from a config expression yielding `true` no longer disables origin verification.
- Count message rate limiting per client address as well as per socket id, so tripping the limit and reconnecting no longer grants a fresh quota.
- Release connection-scoped rate limiter entries on close and sweep expired ones, so the in-memory store no longer accumulates two entries per connection for the process lifetime.
- Validate `max_attempts` and `decay_seconds` at boot when rate limiting is enabled. A missing value was read as `null`, which rejected every message after the second.
- Bound subscribe requests with `max_channel_name_length` and `max_subscriptions_per_connection`, so one connection is no longer an unbounded allocation primitive.
- Size each connection's frame parser from its own application's `max_message_size`, so one tenant's generous limit is no longer every tenant's buffering budget on a shared process.
- Move the inbound `Message Received` log below the rate-limit check, so a throttled flood no longer costs a log write per message.

### Fixed

- Track open connections in `ChannelRegistry` rather than deriving them from channel membership, so a client that completed the handshake and never subscribed is pinged, marked stale and pruned.
- Isolate broadcast failures per subscriber: a peer that dropped mid-fan-out threw from the transport, aborting the loop so later subscribers missed the event and the publisher got a misleading `4200` reply.
- Report the answering process's PID from `GET /up`, so a reload cannot mistake the still-listening old server for a replacement that died during boot and end with zero servers on the node.
- Record the running server's effective host, port and path in `storage/resonate.json` and thread them through a reload, which previously respawned a bare `resonate:start` on the configured port.
- Poll for the old server's exit after SIGTERM (`--term-timeout`, five seconds by default) and report failure when it is still alive, instead of returning success unconditionally.
- Fail `resonate:start` when the PID file names a live process. `SO_REUSEPORT` let a second start bind the same port and split the node into two processes with separate channel state.
- Publish the PID file from a new `HttpServer::onListening` hook rather than during boot, so a replacement that dies at startup cannot leave the file pointing at a dead PID.
- Complete a drain as soon as the last client disconnects, instead of always burning the full `drain_timeout` on an idle node.
- Verify the reflected `SocketHttpServer::$servers` property at runtime and fall through to the hard-stop path when it is absent, rather than reporting success having closed nothing.
- Stamp a per-process node identity into metrics request envelopes and drop self-addressed requests, so scaled `CHANNEL` and `CHANNELS` no longer count the requesting node twice.
- Complete metrics gathering when the last expected reply lands, using the `PUBLISH` subscriber count to know how many to expect, instead of always waiting out the one second collection window.
- Serialise scheduled task runs per registration, so a task that outlives its interval no longer overlaps itself and emits duplicate events.
- Scope the `client-*` event channel lookup to the connection's own application, which an unscoped `find()` did not do.
- Release the connection slot as `PruneStaleConnections` sweeps, instead of leaving it held until the transport noticed.

### Added

- `--force` on `resonate:start`, to start when the PID file names a live process. `resonate:reload` passes it during a swap.
- `--term-timeout` on `resonate:reload` (default `5` seconds), bounding the wait for the old server to exit after SIGTERM.
- Runtime metadata file at `storage/resonate.json`, beside the PID file, recording the server's PID and effective host, port and path. Metadata belonging to another PID is ignored.
- Config keys `max_channel_name_length`, `max_subscriptions_per_connection`, `max_outbound_queue_size` and `scaling.max_queued_messages` under `servers.reverb`. See Upgrading for defaults.
- `Concurrency\SerialQueue`: a bounded FIFO drained by a single worker fiber, used for connection outbound queues and inbound pub/sub envelopes.
- `Server\ApplicationClientFactory`, which sizes each connection's RFC 6455 parser from the application resolved during the handshake.
- `Exceptions\InvalidConfiguration` and `Protocols\Pusher\Exceptions\SubscriptionLimitExceeded` (pusher code `4302`).
- `ChannelManager::openConnections(): array` and `Connection::remoteAddress(): ?string`. Custom `Connection` implementations inherit the `null` default.
- `MetricsHandler::nodeId()`, and `ResolvesApplicationKey`, the trait `WebSocketHandler::appKey()` moved to.
- `HttpServer::onListening()`, which runs after the listening sockets are bound, unlike `onStart()`, whose callbacks are awaited before the bind.
- `Scheduler::isRunning()`, and `StartServer::runtimeFilePath()` / `readRuntime()`.
- `RawConnection::outbound()` and `RedisPubSubProvider::envelopes()`, which expose the queues for inspection.

### Changed

- `ChannelManager::for()` returns an immutable per-application view instead of re-scoping and returning `$this`; an unscoped manager throws.
- `ConnectionPruned` carries the `Connection` rather than a `ChannelConnection` wrapper, because a connection that never subscribed has none.
- `PubSubProvider::publish()` returns the number of subscribers the backend delivered to, this node's own included, rather than `void`.
- The global websocket message size limit is now the smallest configured limit and governs only an upgrade whose app key resolves to nothing.
- `GET /up` reports the answering process's PID alongside the existing `health` field.
- `RawConnection::__construct()` takes the outbound bound as its second argument, `WebSocketHandler::__construct()` as its third, `Factory::make()` and `makeRouter()` as a trailing `$maxOutboundQueueSize`, and `RedisPubSubProvider::__construct()` as its fourth. All are defaulted, so positional call sites keep working.
- `Factory::make()` and `makeRouter()` rename `$maxMessageSize` to `$fallbackMessageSize`, matching what the value now governs.
- `Connection::send()` and `ping()` enqueue and return rather than awaiting the socket; `close()` is ordered behind the queue so pending frames flush first.
- `MetricsHandler::__construct()` takes the collection window as a second argument (default `1.0` seconds, unchanged).
- Raise PHPStan from level 5 to level 7, with accurate array shapes and generics throughout, still with no baseline and no ignores.

### Removed

- `Concerns\InteractsWithApplications`. The trait supplied only the mutable `for()` that immutable scoping replaces.
- `ChannelManager::incrementConnectionCount()` and `decrementConnectionCount()`, replaced by `addConnection()` and `removeConnection()`.

## v0.5.1 - 2026-07-30

Three defects that broke or defeated something in every deployment, plus the CI gates that keep them from returning.

### Fixed

- Defer shutdown to the event loop instead of returning an exit code from the signal handler, which terminated the process before the drain window elapsed and left the PID file behind. Every reload and every SIGTERM severed live connections with no close handshake.
- Close connections rejected by the origin allow-list or `max_connections` instead of leaving them fully usable, and decrement the per-application counter only for admitted connections. Opening and dropping rejected connections walked the count below the live total and reset the quota.
- Retry the Redis pub/sub subscription with exponential backoff (0.5s to a 10s ceiling), so a Redis restart no longer leaves the node publishing while silently receiving nothing until restarted.

### Changed

- `Protocols\Pusher\Server::open()` returns `bool` rather than `void`, reporting whether the connection was admitted. Rejected connections are terminated before the handler's receive loop.
- CI runs Pint and PHPStan (level 5, no baseline and no ignores) and starts a Redis service, so the previously self-skipping scaling integration tests run.
- `ChannelConnection` documents its proxied methods with `@method` tags, and several docblocks were corrected, including the `$applications` shape in `ArrayChannelManager`.

## v0.5.0 - 2026-07-22

### Added

- Dev command registration: `resonate:start` now shows up in the Laravel 13.18+ `php artisan dev` UI alongside `serve`, `queue:listen`, and friends, ported from Reverb v1.11.0. `Resonate::registerDevCommands()` calls `Illuminate\Foundation\DevCommands::artisan('resonate:start', 'resonate')` when that class exists, so older Laravel versions without the dev command runner are unaffected. Wired in at the end of `ResonateServiceProvider::register()`.

### Changed

- Verify against Laravel 13.21.1. Reverb v1.11.0 (2026-07-21) was checked for parity: its socket-id-through-pub/sub fix already shipped in Resonate v0.4.1 (see below), the dev command registration is the one substantive change carried into this release (above), and the rest of that Reverb release is CI and dependabot housekeeping with nothing left to port. The 13.20.0 to 13.21.1 window also touches nothing else Resonate depends on: Broadcasting and Illuminate Redis are untouched and the Queue changes are cosmetic. Dependencies refreshed to `laravel/framework v13.21.1` and `webpatser/fledge-fiber v13.21.1.0`; suite green.

## v0.4.1 - 2026-07-07

Security and correctness release. Both fixes matter for multi-app and multi-node deployments.

### Security

- Isolated per-request controller state. The Pusher REST controllers are router singletons, but the base controller stored request-scoped state (application, channels, body, query) on instance properties; under concurrent fiber-handled requests that state could bleed across requests for different applications, a cross-app disclosure risk. The resolved application and channel manager now live on the per-request `Request` wrapper and are threaded through `verify()`, `verifySignature()` and `verifyTimestamp()`.
- Enforced the configured `max_message_size` at the websocket parser level (`Rfc6455ParserFactory(messageSizeLimit:)`), so oversized frames are rejected with close code 1009 during buffering instead of after full message assembly. The precise per-app check in `Pusher\Server::message()` is retained.

### Fixed

- `toOthers` now works across servers. When an HTTP API event carried a `socket_id` belonging to a connection on another node, the local connection lookup returned null and the `socket_id` was dropped from the pub/sub envelope, so other nodes echoed the event back to the sender. `EventDispatcher::dispatch()` accepts the raw socket id and carries it into the envelope regardless of local resolvability. Mirrors laravel/reverb #389.

### Changed

- Dependencies refreshed: `webpatser/fledge-fiber` v13.19.0.1, verified against Laravel 13.19.0.

### Tests

- New `ControllerStateIsolationTest` covering the structural guarantee, request wrapper isolation, and fresh app resolution on a reused controller instance.
- Four new cases in `EventDispatcherScalingTest`: explicit socket id in the envelope, local connection id fallback, and cross-server `toOthers` regression coverage for single and batch HTTP events. Suite grows to 324 tests.

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
