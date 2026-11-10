=== Identvia Login ===
Contributors: identvia
Tags: login, sso, openid connect, oauth, single sign-on
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.3
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
2. In the Identvia developer portal (https://id.identvia.com/developers), create a **confidential** (server) client.
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

When a visitor uses the button, it talks to the Identvia issuer you configured (by default `https://id.identvia.com`) for discovery, keys and the token exchange. Nothing is sent otherwise. See the section External services below.

== External services ==

This plugin connects to Identvia, an OpenID Connect identity provider, so that visitors can sign in with their Identvia account. It is needed for the plugin's only feature, and it talks to the issuer URL set on **Settings → Identvia Login** (by default `https://id.identvia.com`). Nothing is sent to Identvia until an administrator saves the settings (the plugin then checks that the issuer answers its discovery document) or a visitor clicks the Sign in with Identvia button.

* **Discovery and keys:** the plugin fetches the issuer's OpenID configuration (`/.well-known/openid-configuration`) and public signing keys (JWKS). No personal data is sent.
* **Sign-in:** the visitor's browser is redirected to the Identvia authorization endpoint with your client ID, the redirect URI, the requested scopes and one-time `state`, `nonce` and PKCE values. The visitor signs in at Identvia, not on your site.
* **Token exchange:** your server sends the one-time authorization code, the PKCE verifier and your client credentials to the Identvia token endpoint, and receives an ID token with the visitor's pairwise subject identifier and, if the scopes allow it, their name and verified email address.
* **Sign-out (optional):** when federated logout is on, signing out of WordPress redirects the browser to Identvia's end-session endpoint with the last ID token as `id_token_hint`.

Identvia is provided by Identvia: [Terms of Service](https://id.identvia.com/terms), [Privacy Policy](https://id.identvia.com/privacy).

== Screenshots ==

1. The Sign in with Identvia button on the login page.
2. The settings page.

== Changelog ==

= 0.1.3 =
* First release in the WordPress.org plugin directory.
* Plugin URI now points to the plugin's own page (its GitHub repository), separate from the Author URI.

= 0.1.2 =
* Readme: External services section, Tested up to 7.1, links to the Identvia developer portal, terms and privacy policy.
* Plugin URI now points to https://identvia.com. The release zip is named after the plugin version.
* Translations load automatically; the plugin no longer calls load_plugin_textdomain().

= 0.1.1 =
* Repository hygiene. No code changes.

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.1.3 =
First release in the WordPress.org plugin directory.
