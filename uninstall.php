<?php
/**
 * Removes settings, cached data and Identvia links when the plugin is deleted.
 *
 * @package Identvia_Login
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'identvia_login_settings' );
delete_metadata( 'user', 0, 'identvia_sub', '', true );
delete_metadata( 'user', 0, 'identvia_issuer', '', true );
delete_metadata( 'user', 0, 'identvia_id_token', '', true );
