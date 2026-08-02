# Changelog

All notable changes to `webpatser/resonate` are documented here.

## Unreleased

Correctness release from a full audit of the server and its plugin family. Three of these break or defeat something in every deployment; the CI changes exist so they cannot come back silently. A second pass over the signature and limit paths follows in Security below; two of those change what the server accepts, so read the wire-compatibility notes before upgrading.

### Security

- **The signed canonical string had no escaping or type tagging.** `Controller::formatQueryParametersForVerification()` joined query pairs with a raw `&` and `=` and flattened arrays with `implode(',')`, so `a[]=x&a[]=y` canonicalized identically to `a=x,y`, a nested array stringified to the literal `Array` (leaving its values entirely unsigned, plus a PHP notice), and a `&` or `=` inside a value was indistinguishable from a separator. Two different query strings could therefore share one signed string, which let a caller who influences any signed value smuggle in or delete other signed parameters.

  The canonical form itself is not ours to change: `pusher/pusher-php-server` signs exactly this string (`Pusher::array_implode('=', '&', $params)` after `ksort`), so percent-encoding here would reject every request the stock Laravel `pusher` broadcaster sends. The fix rejects the input the form cannot represent instead. A signed query parameter that is not a scalar, or whose key or value contains `&`, `=`, `\n` or `\r`, is rejected with `400` before the HMAC is computed. Ordinary scalar queries are byte-identical on the wire and keep verifying unchanged.

  Wire compatibility: nothing the SDK produces is affected. It only ever signs scalars, and in the one place it accepts an array parameter it signs `info=a,b` while Guzzle sends `info[0]=a&info[1]=b`, which never verified in the first place. Operators who hand-roll a signer and pass array query parameters, or who put a literal `&` or `=` inside a signed value (a `filter_by_prefix` containing `=`, say, which Pusher channel names do permit), will now get a `400` and need to drop the ambiguous parameter.
- **Request bodies were bound to the signature by MD5 alone.** `body_md5` is the only body binding the Pusher protocol defines, and chosen-prefix collisions against MD5 are practical. The field stays accepted, because every stock client sends it and dropping it would break all of them; a client that also (or instead) signs `body_sha256` now binds its body with SHA-256, and when both are present both are bound, so the stronger digest has to hold too. Whichever digest a request carries is recomputed from the body actually received, so a swapped body still fails verification.

  Wire compatibility: additive. No client is required to change, and `body_sha256` is understood by no other Pusher server, so a client that adopts it is tied to Resonate.
- **The origin allow-list compared only the host.** `parse_url(..., PHP_URL_HOST)` discarded the scheme and port, so an entry meaning "our HTTPS origin" also admitted `http://` and alternate-port origins on the same host. An allow-list entry that carries a scheme (`https://example.com`) is now matched against the full scheme, host and port, with default ports normalized away on both sides. Bare entries (`example.com`, `*.example.com`) are the documented configuration format and are unchanged: they still match on host alone, whatever scheme or port the client used.

  Separately, `in_array('*', $allowedOrigins)` was not strict, so an allow-list built from a config expression that yields `true` (`[env('REVERB_ALLOWED_ORIGINS', true)]`) matched `'*' == true` and silently disabled origin verification altogether. The check is strict now.
- **The message rate limiter reset on reconnect and never released its keys.** The key was `'reverb:message:'.$connection->id()`, and the socket id is fresh random per connection, so a client that tripped the limit reset its quota simply by reconnecting, which `terminate_on_limit` actively invites. Nothing cleared the key on disconnect either, and the array cache store only evicts an expired entry when that same key is read again, so two entries per connection accumulated for the lifetime of the process. And `max_attempts` was read with no fallback, so a config missing that key handed `null` to `tooManyAttempts()`, which rejected every message after the second one with nothing anywhere to explain it.

  Two dimensions are counted now, each with the configured quota: the client (`{app_id}:client:{remote_address}`), which survives a reconnect and is deliberately not released on close, and the connection (`{app_id}:connection:{socket_id}`), which is released in `Server::close()`. Expired keys are swept at most once a second, so the store no longer holds entries for addresses that never come back. `max_attempts` and `decay_seconds` are validated when the `Application` is built and again at server boot from the raw config, so `resonate:start` fails with the offending key named rather than starting a bricked application.

  Caveat: the remote address is the TCP peer. Resonate does not read `X-Forwarded-For`, so behind a reverse proxy every connection reports the proxy's address and the client dimension applies to the proxy as a whole. When the transport reports no address at all, only the connection dimension exists and a reconnect does start over.

  Inbound `Log::info('Message Received')` moved below the rate-limit check as well, so a throttled flood no longer costs a log write per message. Frame body logging was already below it.
- **A connection could subscribe to unbounded channels with unbounded names.** The channel name was validated as `nullable|string` with no length bound, and `findOrCreate()` allocates a Channel for any name it is handed, so one connection was an allocation primitive; its eventual disconnect then walked every channel it had created in `unsubscribeFromAll()`. Two configurable limits now run before the lookup: `servers.reverb.max_channel_name_length` (default `255`, rejected with pusher code `4200`) and `servers.reverb.max_subscriptions_per_connection` (default `250`, rejected with pusher code `4302`, in the "rejected, do not retry" range, leaving the connection and its existing subscriptions intact). Re-subscribing to a channel the connection already holds is idempotent and never counts against the cap. Set either to `0` to disable.

  Wire compatibility: applications that legitimately hold more than 250 channels on one connection, or use channel names longer than 255 characters, must raise the new settings. Pusher itself caps channel names at 164 characters, so the default leaves room.

### Fixed

- **Every graceful shutdown was an instant kill.** `StartServer::handleSignal()` returned an exit code on all paths, and Symfony's signal dispatch runs `if (false !== $exitCode) exit($exitCode)`, so the process terminated the moment the handler returned. The drain window (`HttpServer::drain()` only *schedules* a watchdog) never elapsed, so `resonate:reload` and every systemd SIGTERM severed all live connections with no close handshake, and the PID file was left behind because `exit()` skips the `finally`. Both paths now return `false` and hand the work to the event loop, so `start()` unwinds on its own. Deferring also takes the work out of async signal context, where it ran between opcodes of whatever fiber was executing and could interleave with a partially written frame. The previous unit test asserted the returned exit code, which pinned the bug in place rather than catching it; it now pins the `false` contract.
- **Rejected connections stayed fully usable and reset the connection quota.** A connection refused by the origin allow-list or `max_connections` was sent a `pusher:error` frame but never closed, and the handler entered its receive loop regardless, so a client that ignored the frame kept subscribing and whispering normally. Separately, `close()` decremented the per-application counter unconditionally, including for connections that never reached the increment in `open()`, so opening and dropping rejected connections walked the count below the live total and reset the quota for everyone on the node. `open()` now terminates rejected connections and returns `false`, admission is recorded on the connection, and only an admitted connection decrements.
- **A connection that never subscribed lived forever.** `PingInactiveConnections` and `PruneStaleConnections` both enumerated connections with `$channels->for($application)->connections()`, which unions the per-channel connection lists. Channels are only populated by `EventHandler::subscribe()`, so a client that completed the WebSocket handshake and never sent `pusher:subscribe` appeared in no channel: it was never pinged, never marked stale, and never pruned, while still holding a slot against the per-app `max_connections` and the transport's global limit. The transport heartbeat did not save it either, since browsers answer protocol-level pings automatically without the server learning anything about liveness. Holding sockets open and staying silent was therefore the cheapest way to exhaust a node. `ChannelRegistry` now stores the open connections themselves, keyed by application and socket id, and is the single source of truth for who is connected; the separate counter array is gone and `connectionCount()` is derived from that set. Both jobs walk the new open-connection list, and the prune sweep releases the slot as it goes.

  API change: `ChannelManager::incrementConnectionCount()` and `decrementConnectionCount()` are replaced by `addConnection(Connection $connection)` and `removeConnection(Connection $connection)`, and `openConnections(): array` returns every open connection for the scoped application keyed by socket id. `connectionCount(): int` is unchanged. Admission semantics are unchanged: only a connection that passed the origin and limit checks is registered, and only a registered one is removed on close. Removal is keyed by socket id and so is idempotent, so a connection pruned by the sweep and then closed by the transport can no longer drift the count. The `ConnectionPruned` event payload is now the `Connection` itself rather than a `ChannelConnection` wrapper, because a connection that never subscribed has no wrapper; listeners reading connection methods (`id()`, `app()`, `lastSeenAt()`) are unaffected, one calling `data()` or `connection()` on the payload needs updating.
- **A Redis restart permanently deafened the node.** The pub/sub subscriber fiber logged one line and ended, with nothing to resubscribe: `RedisSubscriber` retries once inline but calls `connect()` outside its own catch, so a reconnect attempted while Redis is still down stops it terminally. The node kept publishing while silently never receiving another broadcast, terminate request or metrics reply until restarted. `RedisPubSubProvider` now owns the retry loop, backing off exponentially (0.5s doubling to a 10s ceiling) and building a fresh subscriber each time. `connect()` is idempotent, so a second call can no longer strand a subscription and leave two listener fibers double-dispatching, and `publish()` on a disconnected provider logs instead of silently dropping.
- **A failed reload could leave the node with nothing listening.** `resonate:reload` probed `GET /up` on the same host and port the old server was still listening on, and the listener binds with `SO_REUSEPORT` so both processes hold that port at once. The health check returned a constant body with no identity, so when a spawned replacement died during boot the old server answered the probe on its behalf: the command reported the new server healthy, signalled the old one to drain, and a routine deploy ended with zero servers on the node. `/up` now reports the PID of the process that answered (alongside the existing `health` field, so external monitors are unaffected) and the probe only accepts a response carrying the PID it just spawned. A `posix_kill($pid, 0)` liveness check fails the reload the moment the replacement exits, instead of polling a dead process until the health timeout runs out.
- **Reload discarded the CLI overrides the running server was started with.** The replacement was spawned as a bare `resonate:start`, so a server started with `--port=9000` was replaced by one binding the configured port while the probe polled a third address. `resonate:start` now records its effective host, port and path in `storage/resonate.json` beside the PID file, and `resonate:reload` threads them through to both the spawned command and the probe. Metadata belonging to another PID is ignored, and a server started before the file existed falls back to config with a warning.
- **Reload reported success without confirming the old server had exited.** When the old process outlived the drain window, the command sent SIGTERM and returned exit code 0 immediately, with no second wait and no liveness check. A wedged old process kept sharing the port with the new one while the deploy pipeline saw a clean reload. The command now polls for exit after SIGTERM (`--term-timeout`, five seconds by default) and reports failure when the process is still alive.
- **A second `resonate:start` silently split the node in two.** `handle()` never checked for a running instance, and because the listener always binds with `SO_REUSEPORT` (so reload can overlap two processes deliberately) a second start bound the same port successfully rather than failing with `EADDRINUSE`. The two processes then split accepts while keeping separate in-memory channel state, so with scaling disabled roughly half of every broadcast missed its subscribers, with nothing in the logs to say so. Starting now fails fast when the PID file names a live process. `--force` is the escape hatch, and the reload spawner passes it, since overlapping two servers for the length of a swap is exactly what it is for.
- **The PID file was published before the server was accepting.** It was written during boot, so a replacement that died at startup left the file pointing at a dead PID while the still-serving old server became undiscoverable: the next reload read the dead PID, unlinked the file, and errored with "no running server found". Publication moved to a new `HttpServer::onListening` hook, which runs after the listening sockets are bound and the accept loops are queued. `SocketHttpServer::onStart` is not that hook: those callbacks are awaited before the bind, while the port is still closed.
- **Draining always burned the full timeout.** `HttpServer::drain()` scheduled an unconditional watchdog with no early exit, so a deploy on an idle node sat out the whole `drain_timeout` (30 seconds by default) before the process could exit. A poller now completes the drain as soon as the last client is gone, and the timeout stays as the upper bound for clients that refuse to disconnect.
- **The drain's reflection into fledge-fiber had no guard.** `drain()` reaches for the private `SocketHttpServer::$servers` to close the listeners without firing the `onStop` callbacks (which is what makes it a drain rather than a stop). A rename in a future fledge-fiber would have left the bound closure reading an undefined property: nothing closed, every listener still open, no exception anywhere, and a reported success. The property is now verified at runtime and a miss falls through to the hard-stop path, which uses only public vendor API.
- **Scheduled tasks could overlap themselves.** `Scheduler::repeat()` fired every interval regardless of whether the previous run had finished, so a task that outlived its interval (a maintenance sweep against a slow Redis, say) accumulated fibers, and two sweeps walking the same connections emit duplicate events for the same connection. Runs are now serialised per registration and a skipped tick is logged. Per registration rather than per name, because several distinct tasks legitimately share a name: every plugin tick registers as `plugin:tick`, and one slow plugin must not starve the others.

### Changed

- New configuration keys under `servers.reverb`: `max_channel_name_length` (`REVERB_MAX_CHANNEL_NAME_LENGTH`, default `255`) and `max_subscriptions_per_connection` (`REVERB_MAX_SUBSCRIPTIONS_PER_CONNECTION`, default `250`). Published `config/reverb.php` files without them get the defaults; both are documented in SECURITY.md.
- `Connection::remoteAddress()` reports the TCP peer address when the transport exposes one, and returns `null` otherwise. `RawConnection` reads it from the fledge-fiber websocket client (the bare IP for internet peers, the address string for unix sockets). Custom `Connection` implementations inherit the `null` default and keep working.
- CI runs Pint and PHPStan (level 5, no baseline and no ignores) and starts a Redis service so the previously self-skipping scaling integration tests actually run.
- `ChannelConnection` documents the methods it proxies to the underlying connection with `@method` tags, and several docblocks were corrected to match reality, including the `$applications` shape in `ArrayChannelManager`, which claimed one array level more than the code uses.

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
