# Changelog

## 0.1.1 (2026-10-21)

- Repository hygiene: `.gitignore` now excludes `dist/` and zip files. No code changes.

## 0.1.0 (2026-10-21)

- First release: Sign in with Identvia on wp-login.php, `[identvia_login_button]` shortcode and block.
- OIDC code flow with PKCE S256, state, nonce and RFC 9207 iss check. The transaction is server-side and browser-bound.
- Local ID token validation (RS256/ES256, JWKS with rate-limited refetch).
- User linking by pairwise sub, creation on first sign-in, linking by verified email (never admins), and domain→role mapping with optional sync.
- Profile connect/disconnect, and federated logout via end_session_endpoint.
