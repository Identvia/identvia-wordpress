# Identvia Login for WordPress

[![Latest release](https://img.shields.io/github/v/release/Identvia/identvia-wordpress?label=release&sort=semver)](https://github.com/Identvia/identvia-wordpress/releases/latest)
[![CI](https://github.com/Identvia/identvia-wordpress/actions/workflows/ci.yml/badge.svg)](https://github.com/Identvia/identvia-wordpress/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

This plugin adds **Sign in with Identvia** to WordPress. It uses OpenID Connect with PKCE and works as a confidential client, so the client secret stays on your server.

- Puts the button on `wp-login.php`. There is also a `[identvia_login_button]` shortcode and an `identvia-login/sign-in-button` block, with no build step.
- Its settings page is under **Settings → Identvia Login**. There you set the issuer, client ID and secret (or use the `IDENTVIA_CLIENT_ID` and `IDENTVIA_CLIENT_SECRET` constants), and see the redirect URI to register.
- Users are linked by Identvia's pairwise `sub`, stored as the user meta `identvia_sub` plus `identvia_issuer`. You can create accounts on first sign-in, link existing accounts by verified email (never for administrators), and set domain→role rules such as `example.com=editor` or `*=subscriber`, with optional role sync.
- Security: PKCE S256, 256-bit `state` and `nonce`, and the RFC 9207 `iss` check. The transaction is stored server-side (transient, single use, 10 minutes) and bound to the browser by an HttpOnly cookie, which blocks login CSRF and callback replay. The ID token is verified locally: RS256/ES256 against JWKS (with rate-limited refetch on key rotation), plus iss, aud/azp, exp, iat, nonce and at_hash. Redirect targets go through `wp_validate_redirect`.
- Optional federated logout through `end_session_endpoint`, with `id_token_hint`.

## Quickstart

1. Download `identvia-login-<version>.zip` from the [latest release](https://github.com/Identvia/identvia-wordpress/releases/latest) and upload it on **Plugins → Add New → Upload Plugin** (or copy this folder to `wp-content/plugins/identvia-login`), then activate it. It is also submitted to the WordPress.org plugin directory as `identvia-login`.
2. In the Identvia developer portal, create a confidential client. Register `https://YOUR-SITE/wp-login.php?action=identvia_callback` as its redirect URI. For federated logout, also register `https://YOUR-SITE/wp-login.php?loggedout=true` as a sign-out URI.
3. On **Settings → Identvia Login**, enter the client ID and secret. The issuer defaults to `https://id.identvia.com`.

```php
// wp-config.php (optional; keeps the secret out of the database)
define( 'IDENTVIA_CLIENT_ID', 'oid_…' );
define( 'IDENTVIA_CLIENT_SECRET', '…' );
```

Put a button anywhere:

```
[identvia_login_button text="continue" theme="dark" size="large" redirect="/members"]
```

Example of a theme `functions.php` hook:

```php
// Only let people from our company in.
add_filter( 'identvia_login_authenticate', function ( $user, $claims ) {
	$domain = substr( strrchr( $claims['email'] ?? '', '@' ), 1 );
	return ( ! empty( $claims['email_verified'] ) && 'example.com' === $domain )
		? $user
		: new WP_Error( 'identvia_no_account', 'Company accounts only.' );
}, 10, 2 );
```

See `readme.txt` for the wordpress.org listing.

## Development

```bash
composer install
composer test   # PHPUnit: OIDC client (PKCE, state, iss, nonce, ID token checks, JWKS rotation) and role mapping
composer lint   # PHPCS with WordPress Coding Standards + PHPCompatibilityWP
```

The end-to-end test (`tests/e2e/run.sh`) runs a real WordPress (`php -S` plus MySQL/MariaDB) against a real Identvia server. It covers sign-in, account creation, role mapping, replay, login CSRF, forged state, denied consent, open-redirect handling, shortcode, block, admin protection and federated logout. It needs the Identvia live-test helper and a dev server, so CI doesn't run it.

This plugin does not request `offline_access` and does not keep refresh tokens. WordPress sessions are managed by WordPress.

## License

MIT
