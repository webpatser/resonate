# Security Policy

## Reporting a vulnerability

Email **christoph@downsized.nl** with the details. Please do not open a public GitHub issue for a suspected security bug.

You can expect an acknowledgement within seven days. Once a fix is available it will be tagged as a patch release; the changelog will reference the report (without details) and credit the reporter unless they ask otherwise.

## Supported versions

| Version | Supported |
|---------|-----------|
| 0.1.x   | yes       |

## Threat model

Resonate is a Pusher-protocol WebSocket server. The security model is identical to Laravel Reverb's; this document makes the assumptions explicit.

### Trust boundaries

- **The `apps.apps[].secret` in `config/reverb.php`.** All channel auth (WebSocket private/presence subscribes) and all HTTP REST API requests are signed with this secret. Treat it like a database password.
- **The Redis pub/sub channel** (only when `scaling.enabled` is true). See "Horizontal scaling" below.

### Authentication

- **WebSocket private/presence channels:** HMAC-SHA256 of `{socket_id}:{channel}[:{channel_data}]`, compared with `hash_equals`. Implementation: `src/Protocols/Pusher/Channels/Concerns/InteractsWithPrivateChannels.php`.
- **HTTP REST API:** HMAC-SHA256 of `METHOD\nPATH\n<ksort'd query incl body_md5>`, compared with `hash_equals`. Implementation: `src/Protocols/Pusher/Http/Controllers/Controller.php`.

Both schemes match the Pusher protocol and the `pusher/pusher-php-server` SDK's signing.

### Origin verification

When `apps.apps[].allowed_origins` does not contain `'*'`, every WebSocket upgrade is checked against the configured list with `Str::is($pattern, $host)` (fnmatch-style). Two patterns worth knowing:

- `example.com` matches only `example.com`. It does not match subdomains.
- `*.example.com` matches `sub.example.com` but **does not** match `example.com` itself.

For production, list the explicit hosts you expect to serve. The default config ships `['*']` (permissive) so a fresh `resonate:install` works out of the box; tighten it.

### Horizontal scaling (Redis pub/sub)

When `scaling.enabled` is true, multiple Resonate instances exchange broadcasts through a Redis channel. **Cross-node envelopes are not individually authenticated.** Anyone with `PUBLISH` access to the configured Redis channel can address any configured `app_id` and broadcast arbitrary payloads to its subscribers.

The trust boundary is therefore Redis itself: deploy with `requirepass` (or ACL) and a private network. The Redis URL in `reverb.servers.reverb.scaling.server` is the only authentication.

Envelopes are pure JSON. The `Application` is carried as its `app_id` string and re-resolved through `ApplicationProvider` on the receiving node; no `serialize()`/`unserialize()` of untrusted data is ever performed. Implementation: `src/Scaling/PusherPubSubIncomingMessageHandler.php`.

### Logging

`StandardLogger` and `CliLogger` decode and pretty-print every incoming WebSocket frame to aid debugging. Sensitive fields are redacted before logging by `Webpatser\Resonate\Loggers\Sanitizer`:

- `data.auth` (private/presence channel auth tokens) → `[redacted]`
- `data.channel_data` (presence user data, may contain PII) → `[redacted: presence channel_data]`

Anything not in those two fields will still appear in the log; treat the log file with the access controls your other application logs already have.

### Rate limiting

Per-connection (`'reverb:message:'.$connection->id()`) and stored in the in-memory `array` cache store, so the counter cannot be tampered with through a shared cache. Rate limits are **per server instance**, so in a horizontally scaled setup, a client can spend its budget on each node independently. Configure conservatively if the difference matters to you.

### Connection limits

`apps.apps[].max_connections` is enforced at `open()` time per application, per node. Set it for production deployments.

### Out of scope

- A compromised Redis: anyone with `PUBLISH` access on the configured channel can broadcast to any configured app.
- A compromised application server: anyone holding the host app's `APP_SECRET` can forge any HTTP API request or channel auth token. Rotate the secret as you would any other credential.
- Distributed rate limiting (per-app, cluster-wide); counters are per-server-instance only.

### Cryptography

| Where | Algorithm |
|-------|-----------|
| WS / HTTP signature | HMAC-SHA256, compared with `hash_equals` |
| HTTP API request fingerprint | `md5(body)` (used as an identity bind inside the HMAC payload, not as a preimage-resistant hash; matches the Pusher protocol spec) |
| Connection identifiers | `random_int(1, 1_000_000_000)` for both halves of the `socket_id` |

The MD5 usage is locked by the Pusher wire protocol and is not a primitive Resonate gets to change. Every cryptographic comparison uses constant-time `hash_equals`.
