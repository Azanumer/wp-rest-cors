# REST CORS

Control Cross-Origin Resource Sharing for the WordPress REST API — an allow-list of origins, optional credentials, and proper OPTIONS preflight handling, so your decoupled front end or mobile app can call the API safely.

## Features

- **Origin allow-list** — one origin per line under **Settings → REST CORS**. Exact matches, the catch-all `*` for a public API, and subdomain wildcards like `https://*.example.com`.
- **Credentials done right** — `Access-Control-Allow-Credentials` is only sent for specific origins, never for `*` (browsers ignore credentials with a wildcard anyway).
- **Preflight handling** — OPTIONS requests from allowed origins are answered directly from `rest_pre_dispatch` with Allow-Methods, Allow-Headers and Max-Age, before WordPress routes them.
- **No CORS for anyone else** — requests without an Origin header, or from an origin not on the list, get no CORS headers at all.
- **Vary: Origin** — sent with every CORS response so shared caches don't serve one origin's headers to another.
- **Filter** — `wrc_allowed_origins` overrides the allow-list in code.

## Installation

1. Upload the `wp-rest-cors` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins** in WordPress admin.
3. Add your front-end origin(s) under **Settings → REST CORS**.

## Notes

- CORS is a browser protection, not authentication: this plugin does not make the REST API public or private — it only tells browsers which origins may read responses. Keep using nonces / application passwords for auth.
- `X-WP-Nonce` is in the default allowed-headers list so cookie-authenticated requests from your front end keep working.

## Uninstall

Deleting the plugin removes the `wrc_settings` option.

## License

MIT — see [LICENSE](LICENSE).
