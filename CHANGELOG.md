# Changelog

## 0.1.2 (2026-11-09)

- Ready for the WordPress.org plugin directory: `readme.txt` gains an **External services** section, `Tested up to: 7.1`, and links to the developer portal, terms and privacy policy on id.identvia.com.
- Plugin URI is now `https://identvia.com`.
- Removed the `load_plugin_textdomain()` call. WordPress 4.6+ loads translations for the `identvia-login` text domain automatically (Plugin Check flags the call as discouraged).
- Fixed the release zip name: it was hard-coded as `identvia-login-0.1.0.zip` even for 0.1.1. CI now reads the version from the plugin header and fails if the header, `IDENTVIA_LOGIN_VERSION`, `Stable tag` or the git tag disagree.
- New `release.yml`: a `v*` tag builds `identvia-login-<version>.zip`, attaches it to a GitHub release, and deploys to the WordPress.org SVN repository once `SVN_USERNAME` / `SVN_PASSWORD` secrets exist.

## 0.1.1 (2026-10-21)

- Repository hygiene: `.gitignore` now excludes `dist/` and zip files. No code changes.

## 0.1.0 (2026-10-21)

- First release: Sign in with Identvia on wp-login.php, `[identvia_login_button]` shortcode and block.
- OIDC code flow with PKCE S256, state, nonce and RFC 9207 iss check. The transaction is server-side and browser-bound.
- Local ID token validation (RS256/ES256, JWKS with rate-limited refetch).
- User linking by pairwise sub, creation on first sign-in, linking by verified email (never admins), and domain→role mapping with optional sync.
- Profile connect/disconnect, and federated logout via end_session_endpoint.
