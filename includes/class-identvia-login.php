<?php
/**
 * Sign-in flow, button, shortcode, block, profile connect and logout.
 *
 * @package Identvia_Login
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
class Identvia_Login {

	const COOKIE    = 'identvia_login_binding';
	const TX_TTL    = 600;
	const TX_PREFIX = 'identvia_tx_';

	/**
	 * Singleton.
	 *
	 * @var Identvia_Login|null
	 */
	private static $instance = null;

	/**
	 * ID token of the session being logged out.
	 *
	 * @var string|null
	 */
	private $logout_id_token = null;

	/**
	 * Instance.
	 *
	 * @return Identvia_Login
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		( new Identvia_Login_Settings() )->register();
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'login_form', array( $this, 'login_form_button' ) );
		add_action( 'login_form_identvia_start', array( $this, 'start' ) );
		add_action( 'login_form_identvia_callback', array( $this, 'callback' ) );
		add_filter( 'wp_login_errors', array( $this, 'login_errors' ) );
		add_action( 'show_user_profile', array( $this, 'profile' ) );
		add_action( 'admin_post_identvia_disconnect', array( $this, 'disconnect' ) );
		add_action( 'clear_auth_cookie', array( $this, 'capture_logout' ) );
		add_filter( 'logout_redirect', array( $this, 'logout_redirect' ), 20 );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allowed_hosts' ) );
	}

	/**
	 * Text domain, shortcode and block.
	 */
	public function init() {
		load_plugin_textdomain( 'identvia-login', false, dirname( plugin_basename( IDENTVIA_LOGIN_FILE ) ) . '/languages' );
		wp_register_style( 'identvia-login-button', IDENTVIA_LOGIN_URL . 'assets/button.css', array(), IDENTVIA_LOGIN_VERSION );
		add_shortcode( 'identvia_login_button', array( $this, 'shortcode' ) );
		register_block_type( IDENTVIA_LOGIN_DIR . 'blocks/sign-in-button', array( 'render_callback' => array( $this, 'render_block' ) ) );
	}

	/**
	 * Login page styles.
	 */
	public function enqueue() {
		wp_enqueue_style( 'identvia-login-button' );
	}

	/**
	 * The OIDC client for the current settings.
	 *
	 * @return Identvia_Login_OIDC
	 * @throws Identvia_Login_Exception When not configured.
	 */
	public function oidc() {
		$s = Identvia_Login_Settings::get();
		return new Identvia_Login_OIDC( $s['issuer'], $s['client_id'], $s['client_secret'], array( __CLASS__, 'http' ), array( __CLASS__, 'cache' ) );
	}

	/**
	 * HTTP through the WordPress HTTP API, no redirects.
	 *
	 * @param string      $method  Method.
	 * @param string      $url     URL.
	 * @param array       $headers Headers.
	 * @param string|null $body    Body.
	 * @return array{0:int,1:string}
	 * @throws Identvia_Login_Exception On a network error.
	 */
	public static function http( $method, $url, $headers, $body ) {
		$res = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => 10,
				'redirection' => 0,
				'user-agent'  => 'identvia-login/' . IDENTVIA_LOGIN_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new Identvia_Login_Exception( 'server_error', $res->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- logged only.
		}
		return array( (int) wp_remote_retrieve_response_code( $res ), (string) wp_remote_retrieve_body( $res ) );
	}

	/**
	 * Transient cache.
	 *
	 * @param string        $key     Key.
	 * @param callable|null $compute Producer; null to only read.
	 * @param int           $ttl     TTL.
	 * @param bool          $refresh Recompute even when cached.
	 * @return mixed
	 */
	public static function cache( $key, $compute, $ttl, $refresh ) {
		if ( ! $refresh ) {
			$v = get_transient( $key );
			if ( false !== $v || null === $compute ) {
				return $v;
			}
		} elseif ( null === $compute ) {
			return false;
		}
		$v = call_user_func( $compute );
		set_transient( $key, $v, $ttl );
		return $v;
	}

	/**
	 * URL that starts sign-in.
	 *
	 * @param string $redirect_to Where to land afterwards (local URLs only).
	 * @return string
	 */
	public static function start_url( $redirect_to = '' ) {
		$args = array( 'action' => 'identvia_start' );
		if ( $redirect_to ) {
			$args['redirect_to'] = rawurlencode( $redirect_to );
		}
		return add_query_arg( $args, site_url( 'wp-login.php', 'login' ) );
	}

	/**
	 * Button HTML.
	 *
	 * @param array $a text, redirect, theme, size, shape, width.
	 * @return string
	 */
	public static function button( $a = array() ) {
		$s     = Identvia_Login_Settings::get();
		$a     = wp_parse_args(
			$a,
			array(
				'text'     => 'signin',
				'redirect' => '',
				'theme'    => $s['button_theme'],
				'size'     => 'medium',
				'shape'    => 'rounded',
				'width'    => '',
			)
		);
		$text  = array(
			'signin'   => __( 'Sign in with Identvia', 'identvia-login' ),
			'continue' => __( 'Continue with Identvia', 'identvia-login' ),
			'signup'   => __( 'Sign up with Identvia', 'identvia-login' ),
		);
		$label = isset( $text[ $a['text'] ] ) ? $text[ $a['text'] ] : $text['signin'];
		$theme = in_array( $a['theme'], array( 'light', 'dark', 'brand', 'auto' ), true ) ? $a['theme'] : 'light';
		$size  = in_array( $a['size'], array( 'small', 'medium', 'large' ), true ) ? $a['size'] : 'medium';
		$shape = in_array( $a['shape'], array( 'rect', 'rounded', 'pill' ), true ) ? $a['shape'] : 'rounded';
		$mark  = '<svg viewBox="0 0 28 28" class="idv-mark" aria-hidden="true" focusable="false"><rect width="28" height="28" rx="8" fill="#4B4ACF"/><circle cx="9.25" cy="8.25" r="2.1" fill="#fff"/><path d="M8.6 13.4l3.9 6.1L20.4 8" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		return sprintf(
			'<a class="identvia-btn" href="%1$s" data-theme="%2$s" data-size="%3$s" data-shape="%4$s"%5$s rel="nofollow">%6$s<span class="idv-label">%7$s</span></a>',
			esc_url( self::start_url( $a['redirect'] ) ),
			esc_attr( $theme ),
			esc_attr( $size ),
			esc_attr( $shape ),
			'full' === $a['width'] ? ' data-width="full"' : '',
			$mark,
			esc_html( $label )
		);
	}

	/**
	 * Button on wp-login.php.
	 */
	public function login_form_button() {
		$s = Identvia_Login_Settings::get();
		if ( ! $s['show_on_login'] || ! Identvia_Login_Settings::configured() ) {
			return;
		}
		$redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a destination, validated on use.
		$html     = self::button(
			array(
				'redirect' => $redirect,
				'width'    => 'full',
			)
		);
		echo '<div class="identvia-login-wrap">' . $html . '</div><div class="identvia-login-or">' . esc_html__( 'or', 'identvia-login' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes every part.
	}

	/**
	 * [identvia_login_button text="continue" redirect="/members" theme="dark" size="large" shape="pill" width="full"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'text'     => 'signin',
				'redirect' => '',
				'theme'    => Identvia_Login_Settings::get()['button_theme'],
				'size'     => 'medium',
				'shape'    => 'rounded',
				'width'    => '',
			),
			$atts,
			'identvia_login_button'
		);
		return $this->render_block( $atts );
	}

	/**
	 * Block and shortcode output: the button, or nothing when signed in.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function render_block( $atts ) {
		if ( is_user_logged_in() || ! Identvia_Login_Settings::configured() ) {
			return '';
		}
		wp_enqueue_style( 'identvia-login-button' );
		$atts = is_array( $atts ) ? $atts : array();
		if ( empty( $atts['redirect'] ) ) {
			$atts['redirect'] = get_permalink() ? get_permalink() : home_url( '/' );
		}
		return '<div class="wp-block-identvia-login-button">' . self::button( $atts ) . '</div>';
	}

	/**
	 * Redirects to wp-login.php with an error code.
	 *
	 * @param string $code Error code.
	 * @param string $detail Detail for the debug log.
	 */
	private function fail( $code, $detail = '' ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && '' !== $detail ) {
			error_log( 'identvia-login: ' . $code . ': ' . $detail ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
		}
		wp_safe_redirect( add_query_arg( 'identvia_error', rawurlencode( $code ), wp_login_url() ) );
		exit;
	}

	/**
	 * Key for a transaction.
	 *
	 * @param string $state State.
	 * @return string
	 */
	private static function tx_key( $state ) {
		return self::TX_PREFIX . hash( 'sha256', $state );
	}

	/**
	 * Sets the browser-binding cookie.
	 *
	 * @param string $value Value ('' clears).
	 */
	private static function set_binding_cookie( $value ) {
		$opts = array(
			'expires'  => '' === $value ? time() - YEAR_IN_SECONDS : time() + self::TX_TTL,
			'path'     => SITECOOKIEPATH ? SITECOOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		setcookie( self::COOKIE, $value, $opts ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- binding the sign-in to this browser.
	}

	/**
	 * Starts sign-in (or "connect" for a signed-in user, with a nonce).
	 */
	public function start() {
		if ( ! Identvia_Login_Settings::configured() ) {
			$this->fail( 'not_configured' );
		}
		$mode    = 'login';
		$user_id = 0;
		if ( isset( $_GET['connect'] ) ) {
			check_admin_referer( 'identvia_connect' );
			if ( ! is_user_logged_in() ) {
				$this->fail( 'invalid_state' );
			}
			$mode    = 'connect';
			$user_id = get_current_user_id();
		}
		$redirect_to = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), admin_url() ) : admin_url(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- starting a login needs no nonce; the result is bound to this browser.
		try {
			$req = $this->oidc()->authorization_request( Identvia_Login_Settings::redirect_uri() );
		} catch ( Identvia_Login_Exception $e ) {
			$this->fail( $e->error_code, $e->detail );
		}
		$binding = Identvia_Login_OIDC::random( 32 );
		set_transient(
			self::tx_key( $req['tx']['state'] ),
			array(
				'tx'          => $req['tx'],
				'binding'     => hash( 'sha256', $binding ),
				'redirect_to' => $redirect_to,
				'mode'        => $mode,
				'user_id'     => $user_id,
			),
			self::TX_TTL
		);
		self::set_binding_cookie( $binding );
		nocache_headers();
		wp_redirect( $req['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the configured Identvia issuer.
		exit;
	}

	/**
	 * Handles the redirect back from Identvia.
	 */
	public function callback() {
		nocache_headers();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth state + browser-binding cookie protect this request.
		$params = array();
		foreach ( array( 'code', 'state', 'iss', 'error' ) as $k ) {
			// Opaque protocol values: compared or forwarded byte-for-byte, so only checked to be printable ASCII.
			if ( isset( $_GET[ $k ] ) && is_string( $_GET[ $k ] ) && preg_match( '/^[\x21-\x7e]{1,2048}$/', wp_unslash( $_GET[ $k ] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by the pattern.
				$params[ $k ] = wp_unslash( $_GET[ $k ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated above.
			}
		}
		if ( isset( $_GET['error_description'] ) && is_string( $_GET['error_description'] ) ) {
			$params['error_description'] = sanitize_text_field( wp_unslash( $_GET['error_description'] ) );
		}
		// phpcs:enable
		$state = isset( $params['state'] ) ? $params['state'] : '';
		$entry = '' !== $state ? get_transient( self::tx_key( $state ) ) : false;
		if ( $entry ) {
			delete_transient( self::tx_key( $state ) ); // Single use.
		}
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		self::set_binding_cookie( '' );
		if ( ! is_array( $entry ) || '' === $cookie || ! hash_equals( $entry['binding'], hash( 'sha256', $cookie ) ) ) {
			$this->fail( 'invalid_state', 'Unknown, expired or replayed state, or another browser.' );
		}

		$oidc = null;
		try {
			$oidc   = $this->oidc();
			$result = $oidc->handle_callback( $params, $entry['tx'] );
		} catch ( Identvia_Login_Exception $e ) {
			$this->fail( $e->error_code, $e->detail );
		}
		$claims   = $result['claims'];
		$settings = Identvia_Login_Settings::get();

		if ( 'connect' === $entry['mode'] ) {
			if ( ! is_user_logged_in() || get_current_user_id() !== (int) $entry['user_id'] ) {
				$this->fail( 'invalid_state' );
			}
			$other = Identvia_Login_Users::find( $oidc->issuer, $claims['sub'] );
			if ( $other && $other->ID !== $entry['user_id'] ) {
				wp_safe_redirect( add_query_arg( 'identvia', 'in_use', admin_url( 'profile.php' ) ) );
				exit;
			}
			Identvia_Login_Users::link( $entry['user_id'], $oidc->issuer, $claims['sub'] );
			wp_safe_redirect( add_query_arg( 'identvia', 'connected', admin_url( 'profile.php' ) ) );
			exit;
		}

		$user = Identvia_Login_Users::resolve( $oidc->issuer, $claims, $settings );
		if ( is_wp_error( $user ) ) {
			$this->fail( $user->get_error_code() );
		}
		/**
		 * Filters whether this Identvia user may sign in. Return a WP_Error to refuse.
		 *
		 * @param WP_User|WP_Error $user   User.
		 * @param array            $claims ID token claims.
		 */
		$user = apply_filters( 'identvia_login_authenticate', $user, $claims );
		if ( is_wp_error( $user ) ) {
			$this->fail( $user->get_error_code() );
		}
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		wp_set_current_user( $user->ID );
		if ( $settings['federated_logout'] && ! empty( $result['tokens']['id_token'] ) ) {
			update_user_meta( $user->ID, 'identvia_id_token', $result['tokens']['id_token'] );
		}
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		$to = apply_filters( 'login_redirect', $entry['redirect_to'], $entry['redirect_to'], $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		wp_safe_redirect( $to ? $to : admin_url() );
		exit;
	}

	/**
	 * User-facing messages on wp-login.php.
	 *
	 * @param WP_Error $errors Errors.
	 * @return WP_Error
	 */
	public function login_errors( $errors ) {
		if ( empty( $_GET['identvia_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return $errors;
		}
		$code = sanitize_key( wp_unslash( $_GET['identvia_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$map  = array(
			'access_denied'             => __( 'Sign-in with Identvia was cancelled.', 'identvia-login' ),
			'invalid_state'             => __( 'That sign-in link expired or was already used. Please try again.', 'identvia-login' ),
			'not_configured'            => __( 'Identvia sign-in is not configured yet.', 'identvia-login' ),
			'identvia_admin_link'       => __( 'An administrator account uses this email. Sign in with your password and connect Identvia from your profile.', 'identvia-login' ),
			'identvia_linked_elsewhere' => __( 'This account is already connected to a different Identvia identity.', 'identvia-login' ),
			'identvia_no_account'       => __( 'No account on this site is connected to your Identvia identity.', 'identvia-login' ),
			'identvia_unverified'       => __( 'Your Identvia email address is not verified.', 'identvia-login' ),
			'identvia_email_taken'      => __( 'An account with this email already exists. Sign in with your password and connect Identvia from your profile.', 'identvia-login' ),
		);
		$errors->add( 'identvia', isset( $map[ $code ] ) ? $map[ $code ] : __( 'Sign-in with Identvia failed. Please try again.', 'identvia-login' ) );
		return $errors;
	}

	/**
	 * Profile section: connect or disconnect.
	 *
	 * @param WP_User $user User.
	 */
	public function profile( $user ) {
		if ( ! Identvia_Login_Settings::configured() ) {
			return;
		}
		$linked = (bool) get_user_meta( $user->ID, Identvia_Login_Users::META_SUB, true );
		$notice = isset( $_GET['identvia'] ) ? sanitize_key( wp_unslash( $_GET['identvia'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		?>
		<h2><?php esc_html_e( 'Identvia', 'identvia-login' ); ?></h2>
		<?php if ( 'connected' === $notice ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Identvia is connected.', 'identvia-login' ); ?></p></div>
		<?php elseif ( 'in_use' === $notice ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'That Identvia identity is connected to another account.', 'identvia-login' ); ?></p></div>
		<?php endif; ?>
		<table class="form-table" role="presentation"><tr><th scope="row"><?php esc_html_e( 'Sign in with Identvia', 'identvia-login' ); ?></th><td>
		<?php if ( $linked ) : ?>
			<p><?php esc_html_e( 'Connected.', 'identvia-login' ); ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=identvia_disconnect' ), 'identvia_disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect', 'identvia-login' ); ?></a></p>
		<?php else : ?>
			<a class="button" href="
			<?php
			echo esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'connect'     => 1,
							'redirect_to' => rawurlencode( admin_url( 'profile.php' ) ),
						),
						site_url( 'wp-login.php?action=identvia_start', 'login' )
					),
					'identvia_connect'
				)
			);
			?>
									"><?php esc_html_e( 'Connect Identvia', 'identvia-login' ); ?></a>
		<?php endif; ?>
		</td></tr></table>
		<?php
	}

	/**
	 * Disconnects the current user.
	 */
	public function disconnect() {
		check_admin_referer( 'identvia_disconnect' );
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be signed in.', 'identvia-login' ), 403 );
		}
		Identvia_Login_Users::unlink( get_current_user_id() );
		wp_safe_redirect( admin_url( 'profile.php' ) );
		exit;
	}

	/**
	 * Remembers the ID token before the session is cleared.
	 */
	public function capture_logout() {
		$uid = get_current_user_id();
		if ( $uid && Identvia_Login_Settings::get()['federated_logout'] ) {
			$t = get_user_meta( $uid, 'identvia_id_token', true );
			if ( $t ) {
				$this->logout_id_token = $t;
				delete_user_meta( $uid, 'identvia_id_token' );
			}
		}
	}

	/**
	 * Sends the browser to Identvia's end_session_endpoint after a WordPress logout.
	 *
	 * @param string $to Redirect.
	 * @return string
	 */
	public function logout_redirect( $to ) {
		if ( null === $this->logout_id_token ) {
			return $to;
		}
		try {
			$url = $this->oidc()->end_session_url( $this->logout_id_token, wp_login_url() . '?loggedout=true' );
			return $url ? $url : $to;
		} catch ( Identvia_Login_Exception $e ) {
			return $to;
		}
	}

	/**
	 * Lets wp_safe_redirect go to the Identvia issuer (logout).
	 *
	 * @param array $hosts Hosts.
	 * @return array
	 */
	public function allowed_hosts( $hosts ) {
		$h = wp_parse_url( Identvia_Login_Settings::get()['issuer'], PHP_URL_HOST );
		if ( $h ) {
			$hosts[] = $h;
		}
		return $hosts;
	}
}
