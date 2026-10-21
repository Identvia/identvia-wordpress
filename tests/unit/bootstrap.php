<?php
/**
 * Unit-test bootstrap: loads the WordPress-independent classes with tiny stubs.
 *
 * @package Identvia_Login
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['identvia_test_roles']   = array( 'subscriber', 'author', 'editor', 'administrator' );
$GLOBALS['identvia_test_filters'] = array();

function get_role( $r ) { // phpcs:ignore
	return in_array( $r, $GLOBALS['identvia_test_roles'], true ) ? (object) array( 'name' => $r ) : null;
}
function apply_filters( $hook, $value, ...$args ) { // phpcs:ignore
	return isset( $GLOBALS['identvia_test_filters'][ $hook ] ) ? call_user_func( $GLOBALS['identvia_test_filters'][ $hook ], $value, ...$args ) : $value;
}
function __( $t ) { // phpcs:ignore
	return $t;
}

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../includes/class-identvia-login-exception.php';
require __DIR__ . '/../../includes/class-identvia-login-oidc.php';
require __DIR__ . '/../../includes/class-identvia-login-settings.php';
require __DIR__ . '/../../includes/class-identvia-login-users.php';
require __DIR__ . '/class-fake-identvia.php';
