<?php
/**
 * Sign-in error.
 *
 * @package Identvia_Login
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never rendered; logged only when WP_DEBUG is on.

/**
 * Raised for every sign-in failure. Visitors only see a message chosen by error_code; detail is for logs.
 */
class Identvia_Login_Exception extends Exception {

	/**
	 * OAuth error code, for example access_denied or invalid_grant.
	 *
	 * @var string
	 */
	public $error_code;

	/**
	 * Technical detail for logs (never shown to visitors).
	 *
	 * @var string
	 */
	public $detail;

	/**
	 * Constructor.
	 *
	 * @param string $error_code Machine-readable code.
	 * @param string $detail     Technical detail.
	 */
	public function __construct( $error_code, $detail = '' ) {
		parent::__construct( $error_code . ( '' !== $detail ? ': ' . $detail : '' ) );
		$this->error_code = $error_code;
		$this->detail     = $detail;
	}
}
