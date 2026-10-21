<?php
/**
 * Role mapping tests.
 *
 * @package Identvia_Login
 */

// phpcs:disable

use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase {

	private function settings( $rules ) {
		return array( 'role_rules' => $rules, 'default_role' => 'subscriber' );
	}

	public function test_parse_rules() {
		$this->assertSame( array( 'acme.com' => 'editor', '*' => 'author' ), Identvia_Login_Settings::parse_rules( "# comment\n@ACME.com = editor\r\n\nbad line\n* = author" ) );
	}

	public function test_domain_rule_needs_verified_email() {
		$s = $this->settings( "acme.com=editor\n*=author" );
		$this->assertSame( 'editor', Identvia_Login_Users::role_for( array( 'email' => 'Ana@Acme.com', 'email_verified' => true ), $s ) );
		$this->assertSame( 'author', Identvia_Login_Users::role_for( array( 'email' => 'bo@other.org', 'email_verified' => true ), $s ) );
		$this->assertSame( 'subscriber', Identvia_Login_Users::role_for( array( 'email' => 'ana@acme.com', 'email_verified' => false ), $s ) );
		$this->assertSame( 'subscriber', Identvia_Login_Users::role_for( array(), $s ) );
	}

	public function test_unknown_role_falls_back_and_filter_applies() {
		$this->assertSame( 'subscriber', Identvia_Login_Users::role_for( array( 'email' => 'a@acme.com', 'email_verified' => true ), $this->settings( 'acme.com=ghost' ) ) );
		$GLOBALS['identvia_test_filters']['identvia_login_role'] = function ( $role, $claims ) {
			return 'author';
		};
		$this->assertSame( 'author', Identvia_Login_Users::role_for( array(), $this->settings( '' ) ) );
		unset( $GLOBALS['identvia_test_filters']['identvia_login_role'] );
	}
}
