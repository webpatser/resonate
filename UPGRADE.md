# Upgrade Guide

## From 0.7 to 1.0

There are no breaking changes. Resonate 1.0.0 is functionally identical to v0.7.4; it marks the start of the semantic versioning promise for the 1.x line.

1. Require the new major: `composer require webpatser/resonate:^1.0`.
2. Upgrade first-party plugins to their 1.x releases (`webpatser/resonate-*:^1.0`). Plugins that still require `^0.7` will not install next to Resonate 1.x.
3. Restart the server with `php artisan resonate:restart`.

The escape hatches added in the 0.7 line stay supported and are not deprecated:

- `REVERB_VALIDATE_CHANNEL_NAMES` (`servers.reverb.validate_channel_names`): set it to `false` if your application uses channel names outside Pusher's charset.
- `servers.reverb.trusted_proxies` (`REVERB_TRUSTED_PROXIES`): list your proxy CIDR ranges so the rate limiter keys on the real client address.

The `fallbackMessageSize` fallback (`Factory`) and the `resonate:restart` command are unchanged.
