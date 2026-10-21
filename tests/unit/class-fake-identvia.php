<?php
/**
 * In-memory Identvia: discovery, JWKS and a token endpoint, with real RS256/ES256 signing.
 *
 * @package Identvia_Login
 */

// phpcs:disable

class Fake_Identvia {
	const ISS = 'https://id.identvia.com';
	public $rsa;
	public $ec;
	public $kid = 'k1';
	public $now = 1800000000;
	public $requests = array();
	public $claims = array();
	public $token_status = 200;
	public $token_body = null;
	public $jwks_fetches = 0;
	public $disc_extra = array();
	public $access_token = 'at-123';
	public $cache = array();

	public function __construct() {
		$this->rsa = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		$this->ec  = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
	}

	public function client( $secret = 'sec' ) {
		$self = $this;
		return new Identvia_Login_OIDC(
			self::ISS . '/',
			'client-1',
			$secret,
			array( $this, 'http' ),
			array( $this, 'cache' ),
			function () use ( $self ) {
				return $self->now;
			}
		);
	}

	public function cache( $key, $compute, $ttl, $refresh ) {
		if ( ! $refresh && array_key_exists( $key, $this->cache ) ) {
			return $this->cache[ $key ];
		}
		if ( null === $compute ) {
			return false;
		}
		return $this->cache[ $key ] = call_user_func( $compute );
	}

	public function jwks() {
		$r = openssl_pkey_get_details( $this->rsa )['rsa'];
		$e = openssl_pkey_get_details( $this->ec )['ec'];
		return array(
			'keys' => array(
				array( 'kty' => 'RSA', 'kid' => $this->kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => Identvia_Login_OIDC::b64url( $r['n'] ), 'e' => Identvia_Login_OIDC::b64url( $r['e'] ) ),
				array( 'kty' => 'EC', 'kid' => 'ec1', 'use' => 'sig', 'alg' => 'ES256', 'crv' => 'P-256', 'x' => Identvia_Login_OIDC::b64url( $e['x'] ), 'y' => Identvia_Login_OIDC::b64url( $e['y'] ) ),
			),
		);
	}

	public function http( $method, $url, $headers, $body ) {
		$this->requests[] = compact( 'method', 'url', 'headers', 'body' );
		if ( self::ISS . '/.well-known/openid-configuration' === $url ) {
			return array( 200, json_encode( array_merge( array(
				'issuer' => self::ISS,
				'authorization_endpoint' => self::ISS . '/oauth/authorize',
				'token_endpoint' => self::ISS . '/oauth/token',
				'jwks_uri' => self::ISS . '/oauth/jwks.json',
				'end_session_endpoint' => self::ISS . '/oauth/logout',
				'code_challenge_methods_supported' => array( 'S256' ),
				'authorization_response_iss_parameter_supported' => true,
			), $this->disc_extra ) ) );
		}
		if ( self::ISS . '/oauth/jwks.json' === $url ) {
			$this->jwks_fetches++;
			return array( 200, json_encode( $this->jwks() ) );
		}
		if ( self::ISS . '/oauth/token' === $url ) {
			if ( null !== $this->token_body ) {
				return array( $this->token_status, $this->token_body );
			}
			return array( $this->token_status, json_encode( array( 'access_token' => $this->access_token, 'token_type' => 'Bearer', 'expires_in' => 3600, 'id_token' => $this->id_token() ) ) );
		}
		return array( 404, '{}' );
	}

	public function sign( $header, $claims, $key = null ) {
		$input = Identvia_Login_OIDC::b64url( json_encode( $header ) ) . '.' . Identvia_Login_OIDC::b64url( json_encode( $claims ) );
		$alg   = isset( $header['alg'] ) ? $header['alg'] : '';
		if ( 'ES256' === $alg ) {
			openssl_sign( $input, $der, $this->ec, OPENSSL_ALGO_SHA256 );
			$sig = self::der_to_raw( $der );
		} elseif ( 'none' === $alg ) {
			$sig = '';
		} else {
			openssl_sign( $input, $sig, $key ? $key : $this->rsa, OPENSSL_ALGO_SHA256 );
		}
		return $input . '.' . Identvia_Login_OIDC::b64url( $sig );
	}

	private static function der_to_raw( $der ) {
		$o = 2;
		$out = '';
		for ( $i = 0; $i < 2; $i++ ) {
			$len = ord( $der[ $o + 1 ] );
			$int = ltrim( substr( $der, $o + 2, $len ), "\0" );
			$out .= str_pad( $int, 32, "\0", STR_PAD_LEFT );
			$o += 2 + $len;
		}
		return $out;
	}

	public function base_claims() {
		return array(
			'iss' => self::ISS,
			'sub' => 'pairwise-sub-1',
			'aud' => 'client-1',
			'exp' => $this->now + 300,
			'iat' => $this->now,
			'nonce' => 'NONCE',
			'email' => 'ana@example.com',
			'email_verified' => true,
			'at_hash' => Identvia_Login_OIDC::b64url( substr( hash( 'sha256', $this->access_token, true ), 0, 16 ) ),
		);
	}

	public function id_token( $over = array(), $header = array() ) {
		$claims = array_merge( $this->base_claims(), $this->claims, $over );
		return $this->sign( array_merge( array( 'alg' => 'RS256', 'kid' => $this->kid, 'typ' => 'JWT' ), $header ), $claims );
	}
}
