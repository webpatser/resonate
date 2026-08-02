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
- **HTTP REST API:** HMAC-SHA256 of `METHOD\nPATH\n<ksort'd query incl body digest>`, compared with `hash_equals`. After the signature check passes, `auth_timestamp` is verified to be within `auth_timestamp_grace` seconds of the server clock (default `600`; set via `REVERB_AUTH_TIMESTAMP_GRACE`, or `0` to disable). Implementation: `src/Protocols/Pusher/Http/Controllers/Controller.php`.

Both schemes match the Pusher protocol and the `pusher/pusher-php-server` SDK's signing. The timestamp window is a Resonate-side hardening Reverb does not enforce; clients that re-send historic signed requests for hours will be rejected. NTP drift between the broadcaster and Resonate matters when the grace is tightened.

#### Canonicalization

The canonical string is unescaped `key=value` pairs joined with `&`, which is what the SDK signs (`Pusher::array_implode('=', '&', $params)`), so the form itself cannot change without rejecting every stock client. Inputs the form cannot represent unambiguously are rejected with `400` before the HMAC is computed:

- a non-scalar (array or nested array) signed query parameter. `a[]=x&a[]=y` used to canonicalize identically to `a=x,y`, and a nested array stringified to the literal `Array`, so its values were never signed.
- a signed key or value containing `&`, `=`, `\n` or `\r`. Without escaping these are indistinguishable from the separators, so a caller who influences one signed value could smuggle in or delete other signed parameters and land on the same signed string.

Neither shape is produced by `pusher/pusher-php-server`.

#### Body binding

`body_md5` is a Pusher wire-protocol field and is still accepted, because every stock client sends it. A client that also (or instead) signs `body_sha256` gets that bound; when both are present both are bound, so the stronger digest has to hold too. Whichever digest the request carries is recomputed from the body actually received, so a swapped body fails verification.

### Origin verification

When `apps.apps[].allowed_origins` does not contain the string `'*'`, every WebSocket upgrade is checked against the configured list with `Str::is()` (fnmatch-style). Both pattern and origin are lowercased before matching, so `example.com` matches `EXAMPLE.com`. Patterns worth knowing:

- `example.com` matches only the host `example.com`, whatever scheme or port the client connected from. It does not match subdomains.
- `*.example.com` matches `sub.example.com` but **does not** match `example.com` itself.
- `https://example.com` matches the full origin, so `http://example.com` and `https://example.com:8443` are rejected. Use this form when the distinction matters. Default ports are normalized away on both sides, so `https://example.com:443` and `https://example.com` are the same pattern.
- IDN hosts are not normalized. Configure the punycode form (`xn--exmple-cua.com`) and clients must send the same.

A missing or empty `Origin` header is rejected when `*` is not in the allow-list. The `*` check is strict: an allow-list built from a config expression that yields a non-string truthy value (`[env('X', true)]`) no longer reads as `*` and disables verification.

For production, list the explicit hosts you expect to serve. The default config ships `['*']` (permissive) so a fresh `resonate:install` works out of the box; tighten it. `resonate:install` emits a hardening reminder at the end of its run.

### Horizontal scaling (Redis pub/sub)

When `scaling.enabled` is true, multiple Resonate instances exchange broadcasts through a Redis channel. **Cross-node envelopes are not individually authenticated.** Anyone with `PUBLISH` access to the configured Redis channel can address any configured `app_id` and broadcast arbitrary payloads to its subscribers.

The trust boundary is therefore Redis itself: deploy with `requirepass` (or ACL) and a private network. The Redis URL in `reverb.servers.reverb.scaling.server` is the only authentication.

Envelopes are pure JSON. The `Application` is carried as its `app_id` string and re-resolved through `ApplicationProvider` on the receiving node; no `serialize()`/`unserialize()` of untrusted data is ever performed. Malformed envelopes (bad JSON, missing fields, unknown app id, oversized payloads) are caught, logged via `Log::error`, and dropped without disrupting the receive loop. Implementation: `src/Scaling/PusherPubSubIncomingMessageHandler.php`.

### Logging

`StandardLogger` and `CliLogger` decode and pretty-print every incoming WebSocket frame to aid debugging. Sensitive fields are redacted before logging by `Webpatser\Resonate\Loggers\Sanitizer`:

- `data.auth` (private/presence channel auth tokens) → `[redacted]`
- `data.channel_data` (presence user data, may contain PII) → `[redacted: presence channel_data]`

Anything not in those two fields will still appear in the log; treat the log file with the access controls your other application logs already have. Frame logging runs *after* the rate-limit check, so messages rejected by the limiter never reach the log.

### Rate limiting

Counted in the in-memory `array` cache store, so the counter cannot be tampered with through a shared cache. Two dimensions are counted, each with the configured quota:

- **client:** `resonate:message:{app_id}:client:{remote_address}`. Survives a reconnect, so a client that trips the limit cannot get a fresh quota by reconnecting (which `terminate_on_limit` actively invites). This entry is deliberately not released on close.
- **connection:** `resonate:message:{app_id}:connection:{socket_id}`. Released when the connection closes, so a process serving many short-lived connections does not accumulate limiter entries.

The remote address is the TCP peer: behind a reverse proxy every connection reports the proxy's address, and the client dimension then applies to the proxy as a whole. Resonate does not read `X-Forwarded-For`. When the transport reports no address at all, only the connection dimension exists and a reconnect does start over.

`max_attempts` and `decay_seconds` are validated at boot when `enabled` is true. A missing value used to be read as `null`, which rejected every message after the second one.

Rate limits are **per server instance**, so in a horizontally scaled setup, a client can spend its budget on each node independently. Configure conservatively if the difference matters to you.

### Connection limits

`apps.apps[].max_connections` is enforced at `open()` time per application, per node. The limit counts open WebSocket connections (not just connections subscribed to a channel) so an unsubscribed client still occupies a slot. Set it for production deployments.

### Message size

`apps.apps[].max_message_size` (default `10_000` bytes) caps the size of any individual incoming WebSocket message. Oversized frames are rejected with pusher error code `4019` and never reach the logger, the rate limiter, or the JSON parser. Set to `0` for unlimited.

### Channel and subscription limits

`servers.reverb.max_channel_name_length` (default `255`) bounds the channel name a client may subscribe to; Pusher itself caps names at 164 characters, so the default leaves room while still bounding the allocation. An over-long name is rejected with pusher error code `4200` and the channel is never created.

`servers.reverb.max_subscriptions_per_connection` (default `250`) bounds how many distinct channels one connection may hold. An over-cap subscribe is rejected with pusher error code `4302` (the 43xx range means "rejected, do not retry"; the connection stays open and its existing subscriptions keep working) and, again, the channel is never created. Re-subscribing to a channel the connection already holds is idempotent and never counts against the cap.

Both checks run before `findOrCreate()`, which otherwise allocates a Channel for any name it is handed. Set either to `0` to disable.

### Client events

The Pusher protocol restricts client events (`client-*`) to private and presence channels, and only allows subscribed clients to whisper. Resonate enforces both rules in `'members'` mode (default) AND in `'all'` mode; the only difference between the two is the source of the membership claim. A sender-supplied `user_id` in the event payload is overridden with the channel-authenticated value, never echoed.

### Out of scope

- A compromised Redis: anyone with `PUBLISH` access on the configured channel can broadcast to any configured app.
- A compromised application server: anyone holding the host app's `APP_SECRET` can forge any HTTP API request or channel auth token. Rotate the secret as you would any other credential.
- Distributed rate limiting (per-app, cluster-wide); counters are per-server-instance only.

### Cryptography

| Where | Algorithm |
|-------|-----------|
| WS / HTTP signature | HMAC-SHA256, compared with `hash_equals` |
| HTTP API body bind | `sha256(body)` when the request carries `body_sha256` |
| HTTP API body bind (compatibility) | `md5(body)` when the request carries `body_md5`, which every stock Pusher client does |
| Connection identifiers | `random_int(1, 1_000_000_000)` for both halves of the `socket_id` |

The MD5 field is locked by the Pusher wire protocol and cannot be dropped without breaking every stock client, so it is accepted rather than required: a client that signs `body_sha256` binds its body with SHA-256 instead, and a client that signs both binds it with both. Every cryptographic comparison uses constant-time `hash_equals`.
