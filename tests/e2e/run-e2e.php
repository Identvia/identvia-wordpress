<?php
/**
 * End-to-end test: a real WordPress with this plugin against a real Identvia server.
 *
 *   php tests/e2e/run-e2e.php <wp-url> <wp-cli command> <state.json> <helper.php>
 *
 * state.json comes from the Identvia live helper ("setup"): a confidential client whose redirect URI is
 * <wp-url>/wp-login.php?action=identvia_callback and a signed-in Identvia test user. The helper's
 * "browse" command plays the user on the consent screen. Nothing is mocked.
 *
 * @package Identvia_Login
 */

// phpcs:disable

[ , $wp, $wpcli, $state_file, $helper ] = $argv + array( null, null, null, null, null );
if ( ! $helper ) {
	fwrite( STDERR, "usage: run-e2e.php <wp-url> <wp-cli> <state.json> <helper.php>\n" );
	exit( 1 );
}
$state = json_decode( file_get_contents( $state_file ), true );
$fails = 0;
$n     = 0;

function check( $ok, $name, $extra = '' ) {
	global $fails, $n;
	$n++;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $extra ? '' : "\n     $extra" ) . "\n";
	if ( ! $ok ) {
		$fails++;
	}
}
function wpcli( $args ) {
	global $wpcli;
	exec( $wpcli . ' ' . $args . ' 2>&1', $out, $code );
	return trim( implode( "\n", $out ) );
}
/** Tiny browser with a cookie jar; never follows redirects. */
final class Browser {
	public $jar = array();
	public function req( $url, $method = 'GET', $form = null ) {
		$ch = curl_init( $url );
		$cookie = implode( '; ', array_map( function ( $k, $v ) { return "$k=$v"; }, array_keys( $this->jar ), $this->jar ) );
		curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $cookie ? array( "Cookie: $cookie" ) : array() ) );
		if ( null !== $form ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $form ) );
		}
		$raw  = curl_exec( $ch );
		$hs   = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$head = substr( $raw, 0, $hs );
		$loc  = preg_match( '/^Location:\s*(\S+)/mi', $head, $m ) ? $m[1] : null;
		preg_match_all( '/^Set-Cookie:\s*([^=]+)=([^;]*)/mi', $head, $cs, PREG_SET_ORDER );
		foreach ( $cs as $c ) {
			if ( 'deleted' === $c[2] || '' === $c[2] || preg_match( '/expires=[^;]*19[0-9]{2}|Max-Age=0/i', $c[0] . substr( $head, strpos( $head, $c[0] ), 300 ) ) && '' === $c[2] ) {
				unset( $this->jar[ $c[1] ] );
			} else {
				$this->jar[ $c[1] ] = $c[2];
			}
		}
		return array( 'status' => $code, 'location' => $loc, 'body' => substr( $raw, $hs ), 'head' => $head );
	}
	public function logged_in() {
		foreach ( $this->jar as $k => $v ) {
			if ( 0 === strpos( $k, 'wordpress_logged_in_' ) ) {
				return true;
			}
		}
		return false;
	}
}
function browse( $url, $action = 'approve' ) {
	global $helper, $state_file;
	exec( 'php ' . escapeshellarg( $helper ) . ' browse ' . escapeshellarg( $state_file ) . ' ' . escapeshellarg( $url ) . ' ' . $action . ' 2>&1', $out, $code );
	return 0 === $code ? trim( end( $out ) ) : null;
}
/** Starts sign-in from wp-login.php; returns [browser, authorize URL, start response]. */
function start( $wp ) {
	$b    = new Browser();
	$page = $b->req( "$wp/wp-login.php" );
	preg_match( '/<a class="identvia-btn" href="([^"]+)"/', $page['body'], $m );
	$r = $b->req( html_entity_decode( $m[1] ?? "$wp/wp-login.php?action=identvia_start" ) );
	return array( $b, $r['location'], $r, $page );
}

// Configure the plugin.
$opts = array(
	'issuer'           => $state['base'],
	'client_id'        => $state['confClientId'],
	'client_secret'    => $state['confSecret'],
	'show_on_login'    => 1,
	'create_users'     => 1,
	'link_by_email'    => 1,
	'default_role'     => 'subscriber',
	'role_rules'       => "example.org=author\n",
	'sync_roles'       => 0,
	'federated_logout' => 1,
	'button_theme'     => 'light',
);
wpcli( 'option update identvia_login_settings ' . escapeshellarg( json_encode( $opts ) ) . ' --format=json' );
wpcli( 'transient delete --all' );
$email = $state['userEmail'];
$old = wpcli( 'user get ' . escapeshellarg( $email ) . ' --field=ID' );
if ( ctype_digit( $old ) ) {
	wpcli( "user delete $old --yes" );
}
$admin_id = wpcli( 'user list --role=administrator --field=ID --number=1' );
$admin_email = wpcli( "user get $admin_id --field=user_email" );

// 1. Button on wp-login.php.
list( $b, $authz, $sr, $page ) = start( $wp );
check( false !== strpos( $page['body'], 'Sign in with Identvia' ), 'wp-login.php shows "Sign in with Identvia"' );
check( 302 === $sr['status'] && 0 === strpos( (string) $authz, $state['base'] . '/oauth/authorize?' ), 'start redirects to Identvia /oauth/authorize', $sr['status'] . ' ' . $authz );
parse_str( (string) parse_url( (string) $authz, PHP_URL_QUERY ), $q );
check( 'S256' === ( $q['code_challenge_method'] ?? '' ) && strlen( $q['code_challenge'] ?? '' ) === 43, 'PKCE S256 challenge sent' );
check( strlen( $q['state'] ?? '' ) >= 43 && strlen( $q['nonce'] ?? '' ) >= 43, 'state and nonce are 256-bit random' );
check( ( $q['redirect_uri'] ?? '' ) === "$wp/wp-login.php?action=identvia_callback", 'exact registered redirect_uri' );
check( isset( $b->jar['identvia_login_binding'] ) && false !== stripos( $sr['head'], 'HttpOnly' ), 'HttpOnly browser-binding cookie set' );

// 2. Full sign-in.
$cb = browse( $authz );
check( null !== $cb && 0 === strpos( $cb, "$wp/wp-login.php?action=identvia_callback" ), 'Identvia redirects back to the WordPress callback', (string) $cb );
parse_str( (string) parse_url( (string) $cb, PHP_URL_QUERY ), $cq );
check( ( $cq['iss'] ?? '' ) === $state['base'], 'RFC 9207 iss present on the callback' );
$r = $b->req( $cb );
check( 302 === $r['status'] && $b->logged_in(), 'callback signs the user in to WordPress', $r['status'] . ' ' . $r['location'] . ' ' . substr( strip_tags( $r['body'] ), 0, 200 ) );
check( 0 === strpos( (string) $r['location'], "$wp/wp-admin/" ), 'lands on wp-admin (redirect_to default)', (string) $r['location'] );
$uid = wpcli( 'user get ' . escapeshellarg( $email ) . ' --field=ID' );
check( ctype_digit( $uid ), 'account created for the verified email', $uid );
$sub = wpcli( "user meta get $uid identvia_sub" );
check( (bool) preg_match( '/^[A-Za-z0-9_.-]{16,}$/', $sub ), 'linked by pairwise sub', $sub );
check( 'author' === wpcli( "user get $uid --field=roles" ), 'role mapping example.org=author applied' );
$admin = $b->req( "$wp/wp-admin/profile.php" );
check( 200 === $admin['status'] && false !== strpos( $admin['body'], 'Disconnect' ), 'profile shows the Identvia connection' );

// 3. Replay of the same callback (state is single use).
$b2 = new Browser();
$b2->jar = array_intersect_key( $b->jar, array( 'identvia_login_binding' => 1 ) );
$rep = $b2->req( $cb );
check( false !== strpos( (string) $rep['location'], 'identvia_error=invalid_state' ) && ! $b2->logged_in(), 'replayed callback is refused', (string) $rep['location'] );

// 4. Callback in another browser (no binding cookie) is refused.
list( $b3, $authz3 ) = start( $wp );
$cb3 = browse( $authz3 );
$other = new Browser();
$r3 = $other->req( $cb3 );
check( false !== strpos( (string) $r3['location'], 'identvia_error=invalid_state' ) && ! $other->logged_in(), 'callback without the browser-binding cookie is refused (login CSRF)' );
$r3b = $b3->req( $cb3 );
check( false !== strpos( (string) $r3b['location'], 'identvia_error=invalid_state' ) && ! $b3->logged_in(), 'that state cannot be used afterwards either' );

// 5. Tampered state.
list( $b4, $authz4 ) = start( $wp );
$cb4 = browse( $authz4 );
$r4  = $b4->req( preg_replace( '/state=[^&]+/', 'state=forged', $cb4 ) );
check( false !== strpos( (string) $r4['location'], 'identvia_error=invalid_state' ), 'forged state is refused' );

// 6. Second sign-in resolves the same account by sub.
list( $b5, $authz5 ) = start( $wp );
$r5 = $b5->req( browse( $authz5 ) );
check( $b5->logged_in() && $uid === wpcli( 'user get ' . escapeshellarg( $email ) . ' --field=ID' ) && 1 === substr_count( "\n" . wpcli( 'user list --field=user_email' ) . "\n", "\n$email\n" ), 'second sign-in reuses the linked account' );

// 7. Denied consent.
list( $b6, $authz6 ) = start( $wp );
$cb6 = browse( $authz6 . '&prompt=consent', 'deny' );
$r6  = $b6->req( (string) $cb6 );
check( false !== strpos( (string) $r6['location'], 'identvia_error=access_denied' ), 'denied consent -> access_denied', (string) $cb6 . ' -> ' . $r6['location'] );
$msg = $b6->req( $r6['location'] );
check( false !== strpos( $msg['body'], 'cancelled' ), 'wp-login.php explains the cancellation' );

// 8. Open redirect is neutralised.
$b7 = new Browser();
$s7 = $b7->req( "$wp/wp-login.php?action=identvia_start&redirect_to=" . rawurlencode( 'https://evil.example/' ) );
$r7 = $b7->req( browse( $s7['location'] ) );
check( 0 === strpos( (string) $r7['location'], "$wp/wp-admin" ), 'redirect_to an external host falls back to wp-admin', (string) $r7['location'] );
$b8 = new Browser();
$s8 = $b8->req( "$wp/wp-login.php?action=identvia_start&redirect_to=" . rawurlencode( "$wp/?p=1" ) );
$r8 = $b8->req( browse( $s8['location'] ) );
check( "$wp/?p=1" === $r8['location'], 'local redirect_to is honoured', (string) $r8['location'] );

// 10. Shortcode on a page.
$pid  = wpcli( 'post create --post_type=page --post_status=publish --post_title=Members --post_content=' . escapeshellarg( '[identvia_login_button text="continue" theme="dark"]' ) . ' --porcelain' );
$pg   = ( new Browser() )->req( "$wp/?page_id=$pid" );
check( false !== strpos( $pg['body'], 'Continue with Identvia' ) && false !== strpos( $pg['body'], 'data-theme="dark"' ), 'shortcode renders the button' );
check( false !== strpos( $pg['body'], 'redirect_to=' . rawurlencode( "$wp/?page_id=$pid" ) ), 'shortcode returns to the page after sign-in' );
$blk  = wpcli( 'post create --post_type=page --post_status=publish --post_title=Block --post_content=' . escapeshellarg( '<!-- wp:identvia-login/sign-in-button {"theme":"brand","size":"large"} /-->' ) . ' --porcelain' );
$bg   = ( new Browser() )->req( "$wp/?page_id=$blk" );
check( false !== strpos( $bg['body'], 'data-theme="brand"' ) && false !== strpos( $bg['body'], 'data-size="large"' ), 'block renders the button' );
$lg   = $b5->req( "$wp/?page_id=$pid" );
check( false === strpos( $lg['body'], 'identvia-btn' ), 'button hidden when already signed in' );

// 11. Admin accounts are never auto-linked by email.
wpcli( "user delete $uid --yes" );
wpcli( "user update $admin_id --user_email=" . escapeshellarg( $email ) . ' --skip-email' );
list( $b9, $authz9 ) = start( $wp );
$r9 = $b9->req( browse( $authz9 ) );
check( false !== strpos( (string) $r9['location'], 'identvia_error=identvia_admin_link' ) && ! $b9->logged_in(), 'admin with the same email is not auto-linked', (string) $r9['location'] );
wpcli( "user update $admin_id --user_email=" . escapeshellarg( $admin_email ) . ' --skip-email' );

// 12. Federated logout (last: it ends the Identvia session too).
list( $b, $authzl ) = start( $wp );
$b->req( browse( $authzl ) );
$adm = $b->req( "$wp/wp-admin/" );
preg_match( '#href=["\']([^"\']*wp-login\.php\?action=logout[^"\']*)["\']#', $adm['body'], $lm );
$lo = $b->req( html_entity_decode( $lm[1] ?? '' ) );
check( 0 === strpos( (string) $lo['location'], $state['base'] . '/oauth/logout?' ) && false !== strpos( $lo['location'], 'id_token_hint=' ), 'logout goes to Identvia end_session with id_token_hint', (string) $lo['location'] );
$after = $b->req( "$wp/wp-admin/" );
check( 302 === $after['status'] && false !== strpos( (string) $after['location'], 'wp-login.php' ), 'WordPress session is gone after logout', $after['status'] . ' ' . $after['location'] );
$h = new Browser();
$h->jar = array();
$ends = null;
// Follow the Identvia logout with the user's Identvia session.
exec( 'php -r ' . escapeshellarg( 'require "' . dirname( $helper ) . '/Http.php"; $s=json_decode(file_get_contents("' . $state_file . '"),true); $h=new TestHttp($s["base"]); $h->jar=$s["userJar"]; $u=$argv[1]; $r=$h->get(substr($u, strlen($s["base"]))); if ($r["status"]===200 && preg_match("/<form[^>]+action=\"([^\"]+)\"/", $r["body"], $f)) { $fields=[]; preg_match_all("/name=\"([^\"]+)\" value=\"([^\"]*)\"/", $r["body"], $mm, PREG_SET_ORDER); foreach ($mm as $x) $fields[$x[1]]=html_entity_decode($x[2]); $p=parse_url(html_entity_decode($f[1])); $r=$h->post($p["path"], $fields); } echo $r["location"] ?? ("HTTP ".$r["status"]);' ) . ' ' . escapeshellarg( (string) $lo['location'] ), $eo );
$ends = trim( implode( "\n", $eo ) );
check( 0 === strpos( $ends, "$wp/wp-login.php?loggedout=true" ), 'Identvia returns to the registered post-logout URI', $ends );


echo "\n" . ( $n - $fails ) . "/$n passed\n";
exit( $fails ? 1 : 0 );
