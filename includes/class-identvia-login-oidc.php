<?php
/**
 * OpenID Connect client for Identvia: discovery, PKCE, code exchange and ID token validation.
 * It has no WordPress dependencies, so it can be unit-tested; HTTP and caching are injected.
 *
 * @package Identvia_Login
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never rendered; they are logged only when WP_DEBUG is on.

/**
 * The OIDC client.
 */
class Identvia_Login_OIDC {

	const ALGORITHMS = array( 'RS256', 'ES256' );
	const LEEWAY     = 60;

	/**
	 * Issuer, without a trailing slash.
	 *
	 * @var string
	 */
	public $issuer;

	/**
	 * Client ID.
	 *
	 * @var string
	 */
	public $client_id;

	/**
	 * Client secret (confidential client, stays on the server).
	 *
	 * @var string
	 */
	private $client_secret;

	/**
	 * HTTP function: fn( string $method, string $url, array $headers, ?string $body ): array{0:int,1:string}.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Cache: fn( string $key, ?callable $compute, int $ttl, bool $refresh ): mixed.
	 *
	 * @var callable
	 */
	private $cache;

	/**
	 * Clock: fn(): int.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param string   $issuer        Issuer URL.
	 * @param string   $client_id     Client ID.
	 * @param string   $client_secret Client secret.
	 * @param callable $http          HTTP function.
	 * @param callable $cache         Cache function.
	 * @param callable $clock         Clock.
	 * @throws Identvia_Login_Exception When the issuer is not https (loopback http is allowed for development).
	 */
	public function __construct( $issuer, $client_id, $client_secret, $http, $cache, $clock = 'time' ) {
		$this->issuer        = self::normalize_issuer( $issuer );
		$this->client_id     = (string) $client_id;
		$this->client_secret = (string) $client_secret;
		$this->http          = $http;
		$this->cache         = $cache;
		$this->clock         = $clock;
		if ( '' === $this->client_id || '' === $this->client_secret ) {
			throw new Identvia_Login_Exception( 'not_configured', 'Client ID and secret are required.' );
		}
	}

	/**
	 * Validates and normalizes an issuer URL.
	 *
	 * @param string $issuer Issuer.
	 * @return string
	 * @throws Identvia_Login_Exception When invalid.
	 */
	public static function normalize_issuer( $issuer ) {
		$i  = rtrim( trim( (string) $issuer ), '/' );
		$p  = parse_url( $i ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- also runs outside WordPress.
		$h  = isset( $p['host'] ) ? strtolower( $p['host'] ) : '';
		$s  = isset( $p['scheme'] ) ? strtolower( $p['scheme'] ) : '';
		$ok = $h && empty( $p['query'] ) && empty( $p['fragment'] ) && ( 'https' === $s || ( 'http' === $s && in_array( $h, array( 'localhost', '127.0.0.1', '[::1]' ), true ) ) );
		if ( ! $ok ) {
			throw new Identvia_Login_Exception( 'bad_issuer', 'The issuer must be an https:// URL.' );
		}
		return $i;
	}

	/**
	 * Base64url encode.
	 *
	 * @param string $data Bytes.
	 * @return string
	 */
	public static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JOSE encoding.
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $data Text.
	 * @return string|false
	 */
	public static function b64url_decode( $data ) {
		if ( ! is_string( $data ) || ! preg_match( '/^[A-Za-z0-9_-]*$/', $data ) ) {
			return false;
		}
		return base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JOSE encoding.
	}

	/**
	 * Random base64url token from the CSPRNG.
	 *
	 * @param int $bytes Entropy in bytes.
	 * @return string
	 */
	public static function random( $bytes = 32 ) {
		return self::b64url( random_bytes( $bytes ) );
	}

	/**
	 * PKCE S256 challenge (RFC 7636).
	 *
	 * @param string $verifier Verifier.
	 * @return string
	 * @throws Identvia_Login_Exception When the verifier is malformed.
	 */
	public static function pkce_challenge( $verifier ) {
		if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) {
			throw new Identvia_Login_Exception( 'bad_verifier', 'A PKCE verifier must be 43-128 unreserved characters.' );
		}
		return self::b64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * HTTP GET returning decoded JSON.
	 *
	 * @param string $url URL.
	 * @return array
	 * @throws Identvia_Login_Exception On failure.
	 */
	private function get_json( $url ) {
		list( $status, $body ) = call_user_func( $this->http, 'GET', $url, array( 'Accept' => 'application/json' ), null );
		$data                  = json_decode( $body, true );
		if ( 200 !== $status || ! is_array( $data ) ) {
			throw new Identvia_Login_Exception( 'server_error', "GET $url returned $status" );
		}
		return $data;
	}

	/**
	 * Provider metadata from discovery (cached for an hour).
	 *
	 * @return array
	 * @throws Identvia_Login_Exception On failure or issuer mismatch.
	 */
	public function discovery() {
		$self = $this;
		$m    = call_user_func(
			$this->cache,
			'identvia_disc_' . md5( $this->issuer ),
			function () use ( $self ) {
				return $self->fetch_discovery();
			},
			HOUR_IN_SECONDS,
			false
		);
		return $m;
	}

	/**
	 * Fetches discovery without the cache.
	 *
	 * @return array
	 * @throws Identvia_Login_Exception On failure or issuer mismatch.
	 */
	public function fetch_discovery() {
		$m = $this->get_json( $this->issuer . '/.well-known/openid-configuration' );
		if ( ! isset( $m['issuer'] ) || $m['issuer'] !== $this->issuer ) {
			throw new Identvia_Login_Exception( 'server_error', 'Discovery issuer does not match.' );
		}
		foreach ( array( 'authorization_endpoint', 'token_endpoint', 'jwks_uri' ) as $k ) {
			if ( empty( $m[ $k ] ) || ! self::is_secure_url( $m[ $k ] ) ) {
				throw new Identvia_Login_Exception( 'server_error', "Discovery $k is missing or not https." );
			}
		}
		if ( isset( $m['code_challenge_methods_supported'] ) && ! in_array( 'S256', (array) $m['code_challenge_methods_supported'], true ) ) {
			throw new Identvia_Login_Exception( 'server_error', 'PKCE S256 is not supported.' );
		}
		return $m;
	}

	/**
	 * True for https URLs (http only on loopback).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_secure_url( $url ) {
		$p = parse_url( (string) $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- also runs outside WordPress.
		$s = isset( $p['scheme'] ) ? $p['scheme'] : '';
		$h = isset( $p['host'] ) ? $p['host'] : '';
		return 'https' === $s || ( 'http' === $s && in_array( $h, array( 'localhost', '127.0.0.1', '[::1]' ), true ) );
	}

	/**
	 * Builds the authorization request. Store the returned transaction server-side (never in the URL).
	 *
	 * @param string $redirect_uri Registered redirect URI.
	 * @param array  $opts         Optional: scope, prompt, login_hint, ui_locales, max_age.
	 * @return array{url:string,tx:array}
	 */
	public function authorization_request( $redirect_uri, $opts = array() ) {
		$m        = $this->discovery();
		$verifier = self::random( 32 );
		$tx       = array(
			'state'         => self::random( 32 ),
			'nonce'         => self::random( 32 ),
			'code_verifier' => $verifier,
			'redirect_uri'  => $redirect_uri,
			'max_age'       => isset( $opts['max_age'] ) ? (int) $opts['max_age'] : null,
		);
		$q        = array(
			'response_type'         => 'code',
			'client_id'             => $this->client_id,
			'redirect_uri'          => $redirect_uri,
			'scope'                 => isset( $opts['scope'] ) ? $opts['scope'] : 'openid email profile',
			'state'                 => $tx['state'],
			'nonce'                 => $tx['nonce'],
			'code_challenge'        => self::pkce_challenge( $verifier ),
			'code_challenge_method' => 'S256',
		);
		foreach ( array( 'prompt', 'login_hint', 'ui_locales' ) as $k ) {
			if ( ! empty( $opts[ $k ] ) ) {
				$q[ $k ] = (string) $opts[ $k ];
			}
		}
		if ( null !== $tx['max_age'] ) {
			$q['max_age'] = (string) $tx['max_age'];
		}
		$sep = false === strpos( $m['authorization_endpoint'], '?' ) ? '?' : '&';
		return array(
			'url' => $m['authorization_endpoint'] . $sep . http_build_query( $q, '', '&', PHP_QUERY_RFC3986 ),
			'tx'  => $tx,
		);
	}

	/**
	 * Handles the redirect: checks state, the RFC 9207 iss check and errors, redeems the code and validates the ID token.
	 *
	 * @param array $params Query parameters of the callback.
	 * @param array $tx     Transaction from authorization_request() (already looked up by state and bound to this browser).
	 * @return array{claims:array,tokens:array}
	 * @throws Identvia_Login_Exception On any failure.
	 */
	public function handle_callback( $params, $tx ) {
		$m = $this->discovery();
		if ( empty( $params['state'] ) || ! is_string( $params['state'] ) || ! hash_equals( $tx['state'], $params['state'] ) ) {
			throw new Identvia_Login_Exception( 'invalid_state', 'state does not match.' );
		}
		if ( isset( $params['iss'] ) ) {
			if ( $params['iss'] !== $this->issuer ) {
				throw new Identvia_Login_Exception( 'invalid_state', 'iss does not match (mix-up attack?).' );
			}
		} elseif ( ! empty( $m['authorization_response_iss_parameter_supported'] ) ) {
			throw new Identvia_Login_Exception( 'invalid_state', 'iss is missing.' );
		}
		if ( isset( $params['error'] ) ) {
			throw new Identvia_Login_Exception( (string) $params['error'], isset( $params['error_description'] ) ? (string) $params['error_description'] : '' );
		}
		if ( empty( $params['code'] ) || ! is_string( $params['code'] ) ) {
			throw new Identvia_Login_Exception( 'invalid_request', 'No code.' );
		}
		$tokens = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $params['code'],
				'redirect_uri'  => $tx['redirect_uri'],
				'code_verifier' => $tx['code_verifier'],
			)
		);
		if ( empty( $tokens['id_token'] ) ) {
			throw new Identvia_Login_Exception( 'invalid_token', 'No id_token.' );
		}
		$claims = $this->validate_id_token( $tokens['id_token'], $tx['nonce'], $tx['max_age'], $tokens['access_token'] );
		return array(
			'claims' => $claims,
			'tokens' => $tokens,
		);
	}

	/**
	 * POSTs to the token endpoint with client_secret_basic.
	 *
	 * @param array $fields Form fields.
	 * @return array
	 * @throws Identvia_Login_Exception On error.
	 */
	public function token_request( $fields ) {
		$m                     = $this->discovery();
		$auth                  = base64_encode( rawurlencode( $this->client_id ) . ':' . rawurlencode( $this->client_secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic.
		list( $status, $body ) = call_user_func(
			$this->http,
			'POST',
			$m['token_endpoint'],
			array(
				'Authorization' => 'Basic ' . $auth,
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Accept'        => 'application/json',
			),
			http_build_query( $fields, '', '&' )
		);
		$data                  = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			throw new Identvia_Login_Exception( 'server_error', "Token endpoint returned $status." );
		}
		if ( 200 !== $status ) {
			throw new Identvia_Login_Exception( isset( $data['error'] ) ? (string) $data['error'] : 'server_error', isset( $data['error_description'] ) ? (string) $data['error_description'] : '' );
		}
		if ( empty( $data['access_token'] ) || ! isset( $data['token_type'] ) || 0 !== strcasecmp( $data['token_type'], 'bearer' ) ) {
			throw new Identvia_Login_Exception( 'invalid_token', 'No Bearer access token.' );
		}
		return $data;
	}

	/**
	 * Finds a signing key by kid. An unknown kid refetches the JWKS, at most every 30 s.
	 *
	 * @param string|null $kid Key ID.
	 * @param string      $alg Algorithm.
	 * @return array|null
	 */
	private function find_key( $kid, $alg ) {
		$m     = $this->discovery();
		$self  = $this;
		$key   = 'identvia_jwks_' . md5( $m['jwks_uri'] );
		$load  = function () use ( $self, $m ) {
			return $self->fetch_jwks( $m['jwks_uri'] );
		};
		$pick  = function ( $jwks ) use ( $kid, $alg ) {
			$kty  = 'ES256' === $alg ? 'EC' : 'RSA';
			$keys = array_values(
				array_filter(
					isset( $jwks['keys'] ) ? (array) $jwks['keys'] : array(),
					function ( $k ) use ( $kty ) {
						return isset( $k['kty'] ) && $k['kty'] === $kty && ( ! isset( $k['use'] ) || 'sig' === $k['use'] );
					}
				)
			);
			foreach ( $keys as $k ) {
				if ( null !== $kid && isset( $k['kid'] ) && $k['kid'] === $kid ) {
					return $k;
				}
			}
			return ( null === $kid && 1 === count( $keys ) ) ? $keys[0] : null;
		};
		$found = $pick( call_user_func( $this->cache, $key, $load, HOUR_IN_SECONDS, false ) );
		if ( null !== $found ) {
			return $found;
		}
		$last = (int) call_user_func( $this->cache, $key . '_t', null, 0, false );
		$now  = (int) call_user_func( $this->clock );
		if ( $now - $last < 30 ) {
			return null;
		}
		call_user_func(
			$this->cache,
			$key . '_t',
			function () use ( $now ) {
				return $now;
			},
			60,
			true
		);
		return $pick( call_user_func( $this->cache, $key, $load, HOUR_IN_SECONDS, true ) );
	}

	/**
	 * Fetches a JWKS.
	 *
	 * @param string $uri JWKS URI.
	 * @return array
	 */
	public function fetch_jwks( $uri ) {
		return $this->get_json( $uri );
	}

	/**
	 * DER length.
	 *
	 * @param int $len Length.
	 * @return string
	 */
	private static function der_len( $len ) {
		if ( $len < 0x80 ) {
			return chr( $len );
		}
		$b = ltrim( pack( 'N', $len ), "\0" );
		return chr( 0x80 | strlen( $b ) ) . $b;
	}

	/**
	 * DER INTEGER from unsigned big-endian bytes.
	 *
	 * @param string $bytes Bytes.
	 * @return string
	 */
	private static function der_int( $bytes ) {
		$v = ltrim( $bytes, "\0" );
		if ( '' === $v ) {
			$v = "\0";
		}
		if ( ord( $v[0] ) & 0x80 ) {
			$v = "\0" . $v;
		}
		return "\x02" . self::der_len( strlen( $v ) ) . $v;
	}

	/**
	 * PEM public key from a JWK (RSA or EC P-256).
	 *
	 * @param array $jwk Key.
	 * @return string
	 * @throws Identvia_Login_Exception On a bad key.
	 */
	public static function jwk_to_pem( $jwk ) {
		if ( 'RSA' === $jwk['kty'] ) {
			$n = self::b64url_decode( isset( $jwk['n'] ) ? $jwk['n'] : '' );
			$e = self::b64url_decode( isset( $jwk['e'] ) ? $jwk['e'] : '' );
			if ( ! $n || ! $e || strlen( ltrim( $n, "\0" ) ) * 8 < 2048 ) {
				throw new Identvia_Login_Exception( 'invalid_token', 'RSA key is invalid or shorter than 2048 bits.' );
			}
			$rsa  = "\x30" . self::der_len( strlen( self::der_int( $n ) . self::der_int( $e ) ) ) . self::der_int( $n ) . self::der_int( $e );
			$bits = "\x03" . self::der_len( strlen( $rsa ) + 1 ) . "\0" . $rsa;
			$alg  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
			$spki = "\x30" . self::der_len( strlen( $alg . $bits ) ) . $alg . $bits;
		} elseif ( 'EC' === $jwk['kty'] && isset( $jwk['crv'] ) && 'P-256' === $jwk['crv'] ) {
			$x = self::b64url_decode( isset( $jwk['x'] ) ? $jwk['x'] : '' );
			$y = self::b64url_decode( isset( $jwk['y'] ) ? $jwk['y'] : '' );
			if ( 32 !== strlen( (string) $x ) || 32 !== strlen( (string) $y ) ) {
				throw new Identvia_Login_Exception( 'invalid_token', 'EC key is invalid.' );
			}
			// SEQUENCE { SEQUENCE { id-ecPublicKey, prime256v1 }, BIT STRING 04||x||y }.
			$spki = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00\x04" . $x . $y;
		} else {
			throw new Identvia_Login_Exception( 'invalid_token', 'Unsupported key type.' );
		}
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM.
	}

	/**
	 * JOSE ES256 r||s to DER for openssl_verify.
	 *
	 * @param string $raw 64 bytes.
	 * @return string
	 */
	private static function ecdsa_der( $raw ) {
		$body = self::der_int( substr( $raw, 0, 32 ) ) . self::der_int( substr( $raw, 32, 32 ) );
		return "\x30" . self::der_len( strlen( $body ) ) . $body;
	}

	/**
	 * Validates an ID token (OIDC Core §3.1.3.7).
	 *
	 * @param string      $jwt          Compact JWS.
	 * @param string|null $nonce        Expected nonce.
	 * @param int|null    $max_age      max_age that was requested.
	 * @param string|null $access_token Access token, for at_hash.
	 * @return array Claims.
	 * @throws Identvia_Login_Exception When invalid.
	 */
	public function validate_id_token( $jwt, $nonce, $max_age = null, $access_token = null ) {
		$fail  = function ( $why ) {
			throw new Identvia_Login_Exception( 'invalid_token', $why );
		};
		$parts = explode( '.', (string) $jwt );
		if ( 3 !== count( $parts ) ) {
			$fail( 'ID token is not a compact JWS.' );
		}
		$header = json_decode( (string) self::b64url_decode( $parts[0] ), true );
		$claims = json_decode( (string) self::b64url_decode( $parts[1] ), true );
		$sig    = self::b64url_decode( $parts[2] );
		if ( ! is_array( $header ) || ! is_array( $claims ) || false === $sig ) {
			$fail( 'ID token is malformed.' );
		}
		$alg = isset( $header['alg'] ) ? $header['alg'] : '';
		if ( ! in_array( $alg, self::ALGORITHMS, true ) ) {
			$fail( "ID token alg $alg is not allowed (expected RS256 or ES256; never none or HS*)." );
		}
		if ( isset( $header['crit'] ) ) {
			$fail( 'Unsupported crit header.' );
		}
		$kid = isset( $header['kid'] ) ? (string) $header['kid'] : null;
		$jwk = $this->find_key( $kid, $alg );
		if ( null === $jwk ) {
			$fail( 'ID token is signed with an unknown key.' );
		}
		if ( isset( $jwk['alg'] ) && $jwk['alg'] !== $alg ) {
			$fail( 'Key algorithm mismatch.' );
		}
		$pem = self::jwk_to_pem( $jwk );
		if ( 'ES256' === $alg ) {
			if ( 64 !== strlen( $sig ) ) {
				$fail( 'ID token signature is invalid.' );
			}
			$sig = self::ecdsa_der( $sig );
		}
		if ( 1 !== openssl_verify( $parts[0] . '.' . $parts[1], $sig, $pem, OPENSSL_ALGO_SHA256 ) ) {
			$fail( 'ID token signature is invalid.' );
		}

		$now = (int) call_user_func( $this->clock );
		if ( ! isset( $claims['iss'] ) || $claims['iss'] !== $this->issuer ) {
			$fail( 'ID token issuer does not match.' );
		}
		if ( empty( $claims['sub'] ) || ! is_string( $claims['sub'] ) ) {
			$fail( 'ID token has no sub.' );
		}
		$aud = isset( $claims['aud'] ) ? (array) $claims['aud'] : array();
		if ( ! in_array( $this->client_id, $aud, true ) ) {
			$fail( 'ID token aud does not include this client.' );
		}
		if ( count( $aud ) > 1 && ! isset( $claims['azp'] ) ) {
			$fail( 'ID token with several audiences needs azp.' );
		}
		if ( isset( $claims['azp'] ) && $claims['azp'] !== $this->client_id ) {
			$fail( 'ID token azp is another client.' );
		}
		if ( ! isset( $claims['exp'] ) || ! is_numeric( $claims['exp'] ) || $now >= $claims['exp'] + self::LEEWAY ) {
			$fail( 'ID token expired.' );
		}
		if ( ! isset( $claims['iat'] ) || ! is_numeric( $claims['iat'] ) || $claims['iat'] > $now + self::LEEWAY ) {
			$fail( 'ID token was issued in the future.' );
		}
		if ( null !== $nonce && ( ! isset( $claims['nonce'] ) || ! is_string( $claims['nonce'] ) || ! hash_equals( $nonce, $claims['nonce'] ) ) ) {
			$fail( 'ID token nonce does not match (replay?).' );
		}
		if ( null !== $max_age ) {
			if ( ! isset( $claims['auth_time'] ) || $now > $claims['auth_time'] + $max_age + self::LEEWAY ) {
				$fail( 'auth_time is older than max_age.' );
			}
		}
		if ( isset( $claims['at_hash'] ) && null !== $access_token ) {
			$want = self::b64url( substr( hash( 'sha256', $access_token, true ), 0, 16 ) );
			if ( ! hash_equals( $want, (string) $claims['at_hash'] ) ) {
				$fail( 'ID token at_hash does not match the access token.' );
			}
		}
		return $claims;
	}

	/**
	 * RP-initiated logout URL.
	 *
	 * @param string|null $id_token_hint ID token.
	 * @param string      $post_logout   Registered post-logout redirect URI.
	 * @return string|null
	 */
	public function end_session_url( $id_token_hint, $post_logout ) {
		$m = $this->discovery();
		if ( empty( $m['end_session_endpoint'] ) ) {
			return null;
		}
		$q = array(
			'client_id'                => $this->client_id,
			'post_logout_redirect_uri' => $post_logout,
		);
		if ( $id_token_hint ) {
			$q['id_token_hint'] = $id_token_hint;
		}
		$sep = false === strpos( $m['end_session_endpoint'], '?' ) ? '?' : '&';
		return $m['end_session_endpoint'] . $sep . http_build_query( $q, '', '&', PHP_QUERY_RFC3986 );
	}
}
