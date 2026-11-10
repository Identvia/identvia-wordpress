<?php
/**
 * Plugin Name:       Identvia Login
 * Plugin URI:        https://identvia.com
 * Description:       Adds "Sign in with Identvia": a privacy-first single sign-on button on the login page, plus a shortcode and a block. Uses OpenID Connect with PKCE.
 * Version:           0.1.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Identvia
 * Author URI:        https://identvia.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       identvia-login
 * Domain Path:       /languages
 *
 * @package Identvia_Login
 */

defined( 'ABSPATH' ) || exit;

define( 'IDENTVIA_LOGIN_VERSION', '0.1.2' );
define( 'IDENTVIA_LOGIN_FILE', __FILE__ );
define( 'IDENTVIA_LOGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'IDENTVIA_LOGIN_URL', plugin_dir_url( __FILE__ ) );

require_once IDENTVIA_LOGIN_DIR . 'includes/class-identvia-login-exception.php';
require_once IDENTVIA_LOGIN_DIR . 'includes/class-identvia-login-oidc.php';
require_once IDENTVIA_LOGIN_DIR . 'includes/class-identvia-login-settings.php';
require_once IDENTVIA_LOGIN_DIR . 'includes/class-identvia-login-users.php';
require_once IDENTVIA_LOGIN_DIR . 'includes/class-identvia-login.php';

Identvia_Login::instance()->register();
