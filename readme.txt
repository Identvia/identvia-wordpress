=== Identvia Login ===
Contributors: identvia
Tags: login, sso, openid connect, oauth, single sign-on
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Let visitors sign in to WordPress with their Identvia account. OpenID Connect with PKCE, private by default.

== Description ==

Identvia Login adds a **Sign in with Identvia** button to the WordPress login page, and gives you a shortcode and a block to put the button anywhere on your site.

* Standard OpenID Connect authorization code flow with PKCE (S256), `state`, `nonce` and the RFC 9207 `iss` check.
* ID tokens are verified on your server: signature (RS256/ES256, keys from the issuer's JWKS), issuer, audience, expiry and nonce.
* Accounts are linked by Identvia's pairwise subject identifier, which is unique to your site, so your site can't be used to track people elsewhere.
* Optional: create accounts on first sign-in, link existing accounts by verified email, and map email domains to roles.
* Administrator accounts are never linked automatically. Administrators connect Identvia from their profile after signing in with their password.
* Optional federated logout: signing out of WordPress also signs out of Identvia.
* The client secret stays on your server. It is never shown again after saving, and it can be set as a constant in `wp-config.php` instead.

= Shortcode =

`[identvia_login_button]`

Attributes:

* `text`: `signin` (default), `continue` or `signup`.
* `theme`: `light`, `dark`, `brand` or `auto`.
* `size`: `small`, `medium` or `large`.
* `shape`: `rect`, `rounded` or `pill`.
* `width`: `full`.
* `redirect`: a local URL. By default visitors return to the current page.

The button is hidden for visitors who are already signed in.

= Block =

Add the **Sign in with Identvia** block (Widgets category). It has the same options as the shortcode.

= Hooks =

* `identvia_login_role` (filter): the role for a new or synced user. Receives `$role, $claims`.
* `identvia_login_authenticate` (filter): return a `WP_Error` to refuse a sign-in. Receives `$user, $claims`.
* `identvia_login_user_created` (action): fires after an account is created. Receives `$user_id, $claims`.

== Installation ==

1. Install and activate the plugin.
2. In the Identvia developer portal (https://identvia.com/developers), create a **confidential** (server) client.
3. Register the redirect URI that is shown on **Settings → Identvia Login**. It looks like `https://example.com/wp-login.php?action=identvia_callback`. Redirect URIs must match exactly.
4. If you use federated logout, also register `https://example.com/wp-login.php?loggedout=true` as a sign-out redirect URI.
5. Enter the client ID and secret on the settings page and save. To keep the secret out of the database, set it in `wp-config.php` instead: `define( 'IDENTVIA_CLIENT_SECRET', '...' );`

== Frequently Asked Questions ==

= Does my site need HTTPS? =

Yes. Identvia only accepts `https://` redirect URIs, except `http://localhost` for development.

= Why can't an existing administrator sign in with Identvia straight away? =

Linking by email is turned off for administrators so that nobody can take over an admin account through a different identity provider account. Sign in with your password, then click **Connect Identvia** on your profile.

= What data does the plugin store? =

For each linked user, it stores the pairwise subject (`identvia_sub`) and the issuer in user meta. When federated logout is on, it also stores the last ID token so that it can be sent as `id_token_hint`. Deleting the plugin removes all of this.

= Does this plugin send data to third parties? =

When a visitor uses the button, it talks to the Identvia issuer you configured (by default `https://id.identvia.com`) for discovery, keys and the token exchange. Nothing is sent otherwise. See the Identvia privacy policy: https://identvia.com/privacy

== Screenshots ==

1. The Sign in with Identvia button on the login page.
2. The settings page.

== Changelog ==

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.1.0 =
First release.
