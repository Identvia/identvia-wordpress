<?php
/**
 * Links Identvia identities (pairwise sub) to WordPress users and applies role mapping.
 *
 * @package Identvia_Login
 */

defined( 'ABSPATH' ) || exit;

/**
 * User linking.
 */
class Identvia_Login_Users {

	const META_SUB = 'identvia_sub';
	const META_ISS = 'identvia_issuer';

	/**
	 * Finds the user linked to this issuer and sub.
	 *
	 * @param string $issuer Issuer.
	 * @param string $sub    Pairwise sub.
	 * @return WP_User|null
	 */
	public static function find( $issuer, $sub ) {
		$users = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- indexed by meta_key; one lookup per sign-in.
					array(
						'key'   => self::META_SUB,
						'value' => $sub,
					),
					array(
						'key'   => self::META_ISS,
						'value' => $issuer,
					),
				),
				'number'     => 2,
			)
		);
		return 1 === count( $users ) ? $users[0] : null;
	}

	/**
	 * Links a user.
	 *
	 * @param int    $user_id User.
	 * @param string $issuer  Issuer.
	 * @param string $sub     Sub.
	 */
	public static function link( $user_id, $issuer, $sub ) {
		update_user_meta( $user_id, self::META_SUB, $sub );
		update_user_meta( $user_id, self::META_ISS, $issuer );
	}

	/**
	 * Unlinks a user.
	 *
	 * @param int $user_id User.
	 */
	public static function unlink( $user_id ) {
		delete_user_meta( $user_id, self::META_SUB );
		delete_user_meta( $user_id, self::META_ISS );
	}

	/**
	 * The role for these claims: first matching domain rule (verified emails only), else the default role.
	 *
	 * @param array $claims   ID token claims.
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function role_for( $claims, $settings ) {
		$email = isset( $claims['email'] ) ? strtolower( (string) $claims['email'] ) : '';
		if ( ! empty( $claims['email_verified'] ) && false !== strpos( $email, '@' ) ) {
			$domain = substr( strrchr( $email, '@' ), 1 );
			$rules  = Identvia_Login_Settings::parse_rules( $settings['role_rules'] );
			if ( isset( $rules[ $domain ] ) && get_role( $rules[ $domain ] ) ) {
				return $rules[ $domain ];
			}
			if ( isset( $rules['*'] ) && get_role( $rules['*'] ) ) {
				return $rules['*'];
			}
		}
		/**
		 * Filters the role given to an Identvia user.
		 *
		 * @param string $role   Role slug.
		 * @param array  $claims ID token claims.
		 */
		return apply_filters( 'identvia_login_role', $settings['default_role'], $claims );
	}

	/**
	 * Resolves the WordPress user for a sign-in: by linked sub, else by verified email (never for admins), else creates one.
	 *
	 * @param string $issuer   Issuer.
	 * @param array  $claims   Claims.
	 * @param array  $settings Settings.
	 * @return WP_User|WP_Error
	 */
	public static function resolve( $issuer, $claims, $settings ) {
		$sub  = (string) $claims['sub'];
		$user = self::find( $issuer, $sub );
		if ( $user ) {
			if ( $settings['sync_roles'] && ! user_can( $user, 'manage_options' ) ) {
				$role = self::role_for( $claims, $settings );
				if ( ! in_array( $role, (array) $user->roles, true ) ) {
					$user->set_role( $role );
				}
			}
			return $user;
		}

		$email    = isset( $claims['email'] ) ? sanitize_email( $claims['email'] ) : '';
		$verified = ! empty( $claims['email_verified'] ) && is_email( $email );
		if ( $verified && $settings['link_by_email'] ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				if ( user_can( $existing, 'manage_options' ) ) {
					return new WP_Error( 'identvia_admin_link', __( 'An administrator account uses this email. Sign in with your password and connect Identvia from your profile.', 'identvia-login' ) );
				}
				if ( get_user_meta( $existing->ID, self::META_SUB, true ) ) {
					return new WP_Error( 'identvia_linked_elsewhere', __( 'This account is already connected to a different Identvia identity.', 'identvia-login' ) );
				}
				self::link( $existing->ID, $issuer, $sub );
				return $existing;
			}
		}

		if ( ! $settings['create_users'] ) {
			return new WP_Error( 'identvia_no_account', __( 'No account on this site is connected to your Identvia identity.', 'identvia-login' ) );
		}
		if ( ! $verified ) {
			return new WP_Error( 'identvia_unverified', __( 'Your Identvia email address is not verified.', 'identvia-login' ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'identvia_email_taken', __( 'An account with this email already exists. Sign in with your password and connect Identvia from your profile.', 'identvia-login' ) );
		}
		$base  = sanitize_user( strstr( $email, '@', true ), true );
		$login = '' !== $base ? $base : 'identvia';
		$base  = $login;
		$i     = 2;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			++$i;
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'display_name' => isset( $claims['name'] ) ? sanitize_text_field( $claims['name'] ) : $login,
				'first_name'   => isset( $claims['given_name'] ) ? sanitize_text_field( $claims['given_name'] ) : '',
				'last_name'    => isset( $claims['family_name'] ) ? sanitize_text_field( $claims['family_name'] ) : '',
				'role'         => self::role_for( $claims, $settings ),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		self::link( $user_id, $issuer, $sub );
		/**
		 * Fires after an account is created from an Identvia sign-in.
		 *
		 * @param int   $user_id User ID.
		 * @param array $claims  ID token claims.
		 */
		do_action( 'identvia_login_user_created', $user_id, $claims );
		return get_user_by( 'id', $user_id );
	}
}
