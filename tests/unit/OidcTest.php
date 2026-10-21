<?php
/**
 * Tests for Identvia_Login_OIDC.
 *
 * @package Identvia_Login
 */

// phpcs:disable

use PHPUnit\Framework\TestCase;

final class OidcTest extends TestCase {

	/** @var Fake_Identvia */
	private $idv;

	protected function setUp(): void {
		$this->idv = new Fake_Identvia();
	}

	private function reason( callable $fn ) {
		try {
			$fn();
		} catch ( Identvia_Login_Exception $e ) {
			return $e->error_code . ': ' . $e->detail;
		}
		$this->fail( 'expected an Identvia_Login_Exception' );
	}

	private function tx( $over = array() ) {
		return array_merge( array( 'state' => 'STATE', 'nonce' => 'NONCE', 'code_verifier' => str_repeat( 'v', 43 ), 'redirect_uri' => 'https://wp.example/wp-login.php?action=identvia_callback', 'max_age' => null ), $over );
	}

	public function test_pkce_rfc7636_appendix_b_vector() {
		$this->assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Identvia_Login_OIDC::pkce_challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ) );
	}

	public function test_pkce_rejects_short_verifier() {
		$this->assertStringContainsString( 'bad_verifier', $this->reason( function () { Identvia_Login_OIDC::pkce_challenge( 'short' ); } ) );
	}

	public function test_random_values_are_unique_and_url_safe() {
		$a = Identvia_Login_OIDC::random( 32 );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $a );
		$this->assertNotSame( $a, Identvia_Login_OIDC::random( 32 ) );
	}

	public function test_issuer_must_be_https_except_loopback() {
		$this->assertSame( 'https://id.identvia.com', Identvia_Login_OIDC::normalize_issuer( 'https://id.identvia.com/' ) );
		$this->assertSame( 'http://localhost:8180', Identvia_Login_OIDC::normalize_issuer( 'http://localhost:8180' ) );
		$this->assertStringContainsString( 'bad_issuer', $this->reason( function () { Identvia_Login_OIDC::normalize_issuer( 'http://id.identvia.com' ); } ) );
	}

	public function test_requires_secret() {
		$this->assertStringContainsString( 'not_configured', $this->reason( function () { $this->idv->client( '' ); } ) );
	}

	public function test_authorization_request_has_pkce_state_nonce() {
		$r = $this->idv->client()->authorization_request( 'https://wp.example/cb' );
		$p = parse_url( $r['url'] );
		parse_str( $p['query'], $q );
		$this->assertSame( 'id.identvia.com', $p['host'] );
		$this->assertSame( '/oauth/authorize', $p['path'] );
		$this->assertSame( 'code', $q['response_type'] );
		$this->assertSame( 'S256', $q['code_challenge_method'] );
		$this->assertSame( Identvia_Login_OIDC::pkce_challenge( $r['tx']['code_verifier'] ), $q['code_challenge'] );
		$this->assertSame( $r['tx']['state'], $q['state'] );
		$this->assertSame( $r['tx']['nonce'], $q['nonce'] );
		$this->assertSame( 'openid email profile', $q['scope'] );
		$this->assertArrayNotHasKey( 'code_verifier', $q );
		$r2 = $this->idv->client()->authorization_request( 'https://wp.example/cb' );
		$this->assertNotSame( $r['tx']['state'], $r2['tx']['state'] );
		$this->assertNotSame( $r['tx']['nonce'], $r2['tx']['nonce'] );
	}

	public function test_discovery_issuer_mismatch_rejected() {
		$this->idv->disc_extra = array( 'issuer' => 'https://evil.example' );
		$this->assertStringContainsString( 'issuer does not match', $this->reason( function () { $this->idv->client()->discovery(); } ) );
	}

	public function test_callback_happy_path_uses_basic_auth_and_verifier() {
		$res = $this->idv->client()->handle_callback( array( 'code' => 'C', 'state' => 'STATE', 'iss' => Fake_Identvia::ISS ), $this->tx() );
		$this->assertSame( 'pairwise-sub-1', $res['claims']['sub'] );
		$tok = array_values( array_filter( $this->idv->requests, function ( $r ) { return 'POST' === $r['method']; } ) )[0];
		parse_str( $tok['body'], $form );
		$this->assertSame( 'Basic ' . base64_encode( 'client-1:sec' ), $tok['headers']['Authorization'] );
		$this->assertSame( str_repeat( 'v', 43 ), $form['code_verifier'] );
		$this->assertSame( 'authorization_code', $form['grant_type'] );
		$this->assertArrayNotHasKey( 'client_secret', $form );
	}

	public function test_state_mismatch() {
		$this->assertStringContainsString( 'state does not match', $this->reason( function () { $this->idv->client()->handle_callback( array( 'code' => 'C', 'state' => 'OTHER', 'iss' => Fake_Identvia::ISS ), $this->tx() ); } ) );
		$this->assertCount( 1, $this->idv->requests ); // discovery only; the code was never redeemed
	}

	public function test_iss_mismatch_and_missing() {
		$this->assertStringContainsString( 'iss does not match', $this->reason( function () { $this->idv->client()->handle_callback( array( 'code' => 'C', 'state' => 'STATE', 'iss' => 'https://evil.example' ), $this->tx() ); } ) );
		$this->assertStringContainsString( 'iss is missing', $this->reason( function () { $this->idv->client()->handle_callback( array( 'code' => 'C', 'state' => 'STATE' ), $this->tx() ); } ) );
	}

	public function test_error_response() {
		$this->assertStringStartsWith( 'access_denied', $this->reason( function () { $this->idv->client()->handle_callback( array( 'error' => 'access_denied', 'state' => 'STATE', 'iss' => Fake_Identvia::ISS ), $this->tx() ); } ) );
	}

	public function test_token_error_is_surfaced() {
		$this->idv->token_status = 400;
		$this->idv->token_body   = '{"error":"invalid_grant","error_description":"code reused"}';
		$this->assertSame( 'invalid_grant: code reused', $this->reason( function () { $this->idv->client()->handle_callback( array( 'code' => 'C', 'state' => 'STATE', 'iss' => Fake_Identvia::ISS ), $this->tx() ); } ) );
	}

	private function validate( $jwt, $nonce = 'NONCE', $max_age = null ) {
		return $this->reason( function () use ( $jwt, $nonce, $max_age ) { $this->idv->client()->validate_id_token( $jwt, $nonce, $max_age, $this->idv->access_token ); } );
	}

	public function test_valid_rs256_and_es256() {
		$c = $this->idv->client();
		$this->assertSame( 'pairwise-sub-1', $c->validate_id_token( $this->idv->id_token(), 'NONCE', null, 'at-123' )['sub'] );
		$es = $this->idv->sign( array( 'alg' => 'ES256', 'kid' => 'ec1' ), $this->idv->base_claims() );
		$this->assertSame( 'pairwise-sub-1', $c->validate_id_token( $es, 'NONCE', null, 'at-123' )['sub'] );
	}

	public function test_wrong_issuer() {
		$this->assertStringContainsString( 'issuer', $this->validate( $this->idv->id_token( array( 'iss' => 'https://evil.example' ) ) ) );
	}

	public function test_wrong_audience_and_azp() {
		$this->assertStringContainsString( 'aud', $this->validate( $this->idv->id_token( array( 'aud' => 'other' ) ) ) );
		$this->assertStringContainsString( 'azp', $this->validate( $this->idv->id_token( array( 'aud' => array( 'client-1', 'x' ) ) ) ) );
		$this->assertStringContainsString( 'azp', $this->validate( $this->idv->id_token( array( 'azp' => 'x' ) ) ) );
	}

	public function test_expired_and_future() {
		$this->assertStringContainsString( 'expired', $this->validate( $this->idv->id_token( array( 'exp' => $this->idv->now - 61 ) ) ) );
		$this->assertStringContainsString( 'future', $this->validate( $this->idv->id_token( array( 'iat' => $this->idv->now + 3600 ) ) ) );
		// Within the 60 s leeway.
		$this->idv->client()->validate_id_token( $this->idv->id_token( array( 'exp' => $this->idv->now - 30 ) ), 'NONCE' );
		$this->addToAssertionCount( 1 );
	}

	public function test_nonce() {
		$this->assertStringContainsString( 'nonce', $this->validate( $this->idv->id_token( array( 'nonce' => 'other' ) ) ) );
		$this->assertStringContainsString( 'nonce', $this->validate( $this->idv->id_token(), 'EXPECTED' ) );
	}

	public function test_alg_none_and_hs256_rejected() {
		$this->assertStringContainsString( 'none', $this->validate( $this->idv->sign( array( 'alg' => 'none' ), $this->idv->base_claims() ) ) );
		$this->assertStringContainsString( 'not allowed', $this->validate( $this->idv->sign( array( 'alg' => 'HS256', 'kid' => 'k1' ), $this->idv->base_claims() ) ) );
	}

	public function test_bad_signature() {
		$other = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		$this->assertStringContainsString( 'signature', $this->validate( $this->idv->sign( array( 'alg' => 'RS256', 'kid' => 'k1' ), $this->idv->base_claims(), $other ) ) );
		$parts    = explode( '.', $this->idv->id_token() );
		$tampered = $this->idv->base_claims();
		$tampered['sub'] = 'admin';
		$parts[1] = Identvia_Login_OIDC::b64url( json_encode( $tampered ) );
		$this->assertStringContainsString( 'signature', $this->validate( implode( '.', $parts ) ) );
	}

	public function test_at_hash_and_max_age() {
		$this->assertStringContainsString( 'at_hash', $this->validate( $this->idv->id_token( array( 'at_hash' => 'AAAA' ) ) ) );
		$this->assertStringContainsString( 'max_age', $this->validate( $this->idv->id_token( array( 'auth_time' => $this->idv->now - 1000 ) ), 'NONCE', 60 ) );
	}

	public function test_unknown_kid_refetches_jwks_rate_limited() {
		$c = $this->idv->client();
		$c->validate_id_token( $this->idv->id_token(), 'NONCE' );
		$this->assertSame( 1, $this->idv->jwks_fetches );
		// Key rotation: new kid -> one refetch, then it validates.
		$this->idv->rsa = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		$this->idv->kid = 'k2';
		$c->validate_id_token( $this->idv->id_token(), 'NONCE' );
		$this->assertSame( 2, $this->idv->jwks_fetches );
		// Another unknown kid within 30 s: no refetch.
		$this->assertStringContainsString( 'unknown key', $this->validate( $this->idv->id_token( array(), array( 'kid' => 'k9' ) ) ) );
		$this->assertSame( 2, $this->idv->jwks_fetches );
		$this->idv->now += 31;
		$this->validate( $this->idv->id_token( array(), array( 'kid' => 'k9' ) ) );
		$this->assertSame( 3, $this->idv->jwks_fetches );
	}

	public function test_end_session_url() {
		$u = $this->idv->client()->end_session_url( 'IDT', 'https://wp.example/wp-login.php?loggedout=true' );
		parse_str( parse_url( $u, PHP_URL_QUERY ), $q );
		$this->assertStringStartsWith( Fake_Identvia::ISS . '/oauth/logout?', $u );
		$this->assertSame( 'IDT', $q['id_token_hint'] );
		$this->assertSame( 'client-1', $q['client_id'] );
	}

	public function test_insecure_endpoints_rejected() {
		$this->idv->disc_extra = array( 'token_endpoint' => 'http://id.identvia.com/oauth/token' );
		$this->assertStringContainsString( 'not https', $this->reason( function () { $this->idv->client()->discovery(); } ) );
	}
}
