<?php
/**
 * Settings → Identvia.
 *
 * @package Identvia_Login
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage and admin page.
 */
class Identvia_Login_Settings {

	const OPTION = 'identvia_login_settings';
	const PAGE   = 'identvia-login';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'issuer'           => 'https://id.identvia.com',
			'client_id'        => '',
			'client_secret'    => '',
			'show_on_login'    => 1,
			'create_users'     => 1,
			'link_by_email'    => 1,
			'default_role'     => get_option( 'default_role', 'subscriber' ),
			'role_rules'       => '',
			'sync_roles'       => 0,
			'federated_logout' => 0,
			'button_theme'     => 'light',
		);
	}

	/**
	 * All settings, merged with defaults. The IDENTVIA_CLIENT_SECRET constant (wp-config.php) overrides the stored secret.
	 *
	 * @return array
	 */
	public static function get() {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( defined( 'IDENTVIA_CLIENT_SECRET' ) && IDENTVIA_CLIENT_SECRET ) {
			$s['client_secret'] = IDENTVIA_CLIENT_SECRET;
		}
		if ( defined( 'IDENTVIA_CLIENT_ID' ) && IDENTVIA_CLIENT_ID ) {
			$s['client_id'] = IDENTVIA_CLIENT_ID;
		}
		return $s;
	}

	/**
	 * True once client ID and secret are set.
	 *
	 * @return bool
	 */
	public static function configured() {
		$s = self::get();
		return '' !== $s['client_id'] && '' !== $s['client_secret'];
	}

	/**
	 * The redirect URI to register on the Identvia client.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return site_url( 'wp-login.php?action=identvia_callback', 'login' );
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( IDENTVIA_LOGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'identvia-login' ) . '</a>' );
		return $links;
	}

	/**
	 * Adds the page.
	 */
	public function menu() {
		add_options_page( __( 'Identvia Login', 'identvia-login' ), __( 'Identvia', 'identvia-login' ), 'manage_options', self::PAGE, array( $this, 'render' ) );
	}

	/**
	 * Registers the setting.
	 */
	public function settings() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Parses "domain=role" lines.
	 *
	 * @param string $text Rules text.
	 * @return array<string,string> Domain (or *) to role.
	 */
	public static function parse_rules( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $domain, $role ) = array_map( 'trim', explode( '=', $line, 2 ) );
			$domain                = strtolower( ltrim( $domain, '@' ) );
			if ( '' !== $domain && '' !== $role ) {
				$out[ $domain ] = $role;
			}
		}
		return $out;
	}

	/**
	 * Sanitizes the form.
	 *
	 * @param array $in Raw input.
	 * @return array
	 */
	public function sanitize( $in ) {
		$old = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$in  = is_array( $in ) ? $in : array();
		$out = $old;

		try {
			$out['issuer'] = Identvia_Login_OIDC::normalize_issuer( isset( $in['issuer'] ) ? esc_url_raw( wp_unslash( $in['issuer'] ) ) : $old['issuer'] );
		} catch ( Identvia_Login_Exception $e ) {
			add_settings_error( self::OPTION, 'issuer', __( 'The issuer must be an https:// URL.', 'identvia-login' ) );
		}
		$out['client_id'] = isset( $in['client_id'] ) ? sanitize_text_field( wp_unslash( $in['client_id'] ) ) : $old['client_id'];
		// An empty secret field keeps the stored secret (the field is never pre-filled).
		if ( ! empty( $in['client_secret'] ) ) {
			$out['client_secret'] = sanitize_text_field( wp_unslash( $in['client_secret'] ) );
		}
		foreach ( array( 'show_on_login', 'create_users', 'link_by_email', 'sync_roles', 'federated_logout' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		$roles               = array_keys( wp_roles()->roles );
		$role                = isset( $in['default_role'] ) ? sanitize_key( $in['default_role'] ) : $old['default_role'];
		$out['default_role'] = ( in_array( $role, $roles, true ) && 'administrator' !== $role ) ? $role : 'subscriber';

		$rules = isset( $in['role_rules'] ) ? sanitize_textarea_field( wp_unslash( $in['role_rules'] ) ) : '';
		foreach ( self::parse_rules( $rules ) as $domain => $r ) {
			if ( ! in_array( $r, $roles, true ) ) {
				/* translators: %s: role slug. */
				add_settings_error( self::OPTION, 'role', sprintf( __( 'Unknown role "%s" in the role rules.', 'identvia-login' ), $r ) );
			}
		}
		$out['role_rules']   = $rules;
		$theme               = isset( $in['button_theme'] ) ? sanitize_key( $in['button_theme'] ) : 'light';
		$out['button_theme'] = in_array( $theme, array( 'light', 'dark', 'brand' ), true ) ? $theme : 'light';

		// Check that the issuer answers discovery.
		if ( $out['issuer'] !== $old['issuer'] || $out['client_id'] !== $old['client_id'] ) {
			delete_transient( 'identvia_disc_' . md5( $out['issuer'] ) );
			$res = wp_remote_get( $out['issuer'] . '/.well-known/openid-configuration', array( 'timeout' => 10 ) );
			if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
				add_settings_error( self::OPTION, 'discovery', __( 'Could not reach the Identvia discovery document at that issuer.', 'identvia-login' ), 'warning' );
			}
		}
		return $out;
	}

	/**
	 * Renders the page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::get();
		$name  = self::OPTION;
		$const = defined( 'IDENTVIA_CLIENT_SECRET' ) && IDENTVIA_CLIENT_SECRET;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Identvia Login', 'identvia-login' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: link to the Identvia developer portal. */
					esc_html__( 'Create a confidential client in the %s, then register this redirect URI:', 'identvia-login' ),
					'<a href="https://id.identvia.com/developers" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Identvia developer portal', 'identvia-login' ) . '</a>'
				);
				?>
			</p>
			<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( self::redirect_uri() ); ?>" onfocus="this.select()" aria-label="<?php esc_attr_e( 'Redirect URI', 'identvia-login' ); ?>" /></p>
			<?php if ( $s['federated_logout'] ) : ?>
				<p><?php esc_html_e( 'Post-logout redirect URI to register:', 'identvia-login' ); ?> <code><?php echo esc_html( wp_login_url() . '?loggedout=true' ); ?></code></p>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="identvia-issuer"><?php esc_html_e( 'Issuer', 'identvia-login' ); ?></label></th>
						<td><input id="identvia-issuer" name="<?php echo esc_attr( $name ); ?>[issuer]" type="url" class="regular-text code" value="<?php echo esc_attr( $s['issuer'] ); ?>" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="identvia-client-id"><?php esc_html_e( 'Client ID', 'identvia-login' ); ?></label></th>
						<td><input id="identvia-client-id" name="<?php echo esc_attr( $name ); ?>[client_id]" type="text" class="regular-text code" value="<?php echo esc_attr( $s['client_id'] ); ?>" <?php disabled( defined( 'IDENTVIA_CLIENT_ID' ) && IDENTVIA_CLIENT_ID ); ?> /></td>
					</tr>
					<tr>
						<th scope="row"><label for="identvia-client-secret"><?php esc_html_e( 'Client secret', 'identvia-login' ); ?></label></th>
						<td>
							<?php if ( $const ) : ?>
								<p><?php esc_html_e( 'Set by the IDENTVIA_CLIENT_SECRET constant in wp-config.php.', 'identvia-login' ); ?></p>
							<?php else : ?>
								<input id="identvia-client-secret" name="<?php echo esc_attr( $name ); ?>[client_secret]" type="password" class="regular-text code" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $s['client_secret'] ? __( '(saved — leave empty to keep)', 'identvia-login' ) : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'More secure: define IDENTVIA_CLIENT_SECRET in wp-config.php instead.', 'identvia-login' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Login page', 'identvia-login' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_on_login]" value="1" <?php checked( $s['show_on_login'] ); ?> /> <?php esc_html_e( 'Show "Sign in with Identvia" on wp-login.php', 'identvia-login' ); ?></label><br />
							<label for="identvia-theme"><?php esc_html_e( 'Button style', 'identvia-login' ); ?></label>
							<select id="identvia-theme" name="<?php echo esc_attr( $name ); ?>[button_theme]">
								<?php
								foreach ( array(
									'light' => __( 'Light', 'identvia-login' ),
									'dark'  => __( 'Dark', 'identvia-login' ),
									'brand' => __( 'Brand', 'identvia-login' ),
								) as $v => $label ) :
									?>
									<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $s['button_theme'], $v ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Accounts', 'identvia-login' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[create_users]" value="1" <?php checked( $s['create_users'] ); ?> /> <?php esc_html_e( 'Create a WordPress account on first sign-in', 'identvia-login' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[link_by_email]" value="1" <?php checked( $s['link_by_email'] ); ?> /> <?php esc_html_e( 'Link an existing account with the same verified email (never for administrators; they connect from their profile)', 'identvia-login' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="identvia-default-role"><?php esc_html_e( 'Default role', 'identvia-login' ); ?></label></th>
						<td>
							<select id="identvia-default-role" name="<?php echo esc_attr( $name ); ?>[default_role]">
								<?php
								foreach ( array_reverse( get_editable_roles() ) as $slug => $role ) :
									if ( 'administrator' === $slug ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['default_role'], $slug ); ?>><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="identvia-rules"><?php esc_html_e( 'Role mapping', 'identvia-login' ); ?></label></th>
						<td>
							<textarea id="identvia-rules" name="<?php echo esc_attr( $name ); ?>[role_rules]" rows="4" class="large-text code" placeholder="example.com=editor"><?php echo esc_textarea( $s['role_rules'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One rule per line: verified email domain = role. Only applies to verified emails. The first matching rule wins; otherwise the default role.', 'identvia-login' ); ?></p>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[sync_roles]" value="1" <?php checked( $s['sync_roles'] ); ?> /> <?php esc_html_e( 'Re-apply the mapping at every sign-in (never demotes administrators)', 'identvia-login' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Logout', 'identvia-login' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[federated_logout]" value="1" <?php checked( $s['federated_logout'] ); ?> /> <?php esc_html_e( 'Also end the Identvia session when logging out of WordPress', 'identvia-login' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<h2><?php esc_html_e( 'Add the button elsewhere', 'identvia-login' ); ?></h2>
			<p><?php esc_html_e( 'Use the "Sign in with Identvia" block, or the shortcode:', 'identvia-login' ); ?> <code>[identvia_login_button redirect="/members"]</code></p>
		</div>
		<?php
	}
}
