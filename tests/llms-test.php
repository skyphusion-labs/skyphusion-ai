<?php
/**
 * Optional llms.txt. Issue #228.
 */

require_once dirname( __DIR__ ) . '/includes/llms.php';

expect( 'llms is off without settings', skyphusion_ai_llms_on(), false );

$llms_cli = skyphusion_ai_llms_apply_cli( array( 'endpoint' => '' ), array( 'llms-txt' => '1' ), array() );
expect( 'CLI llms-txt turns the switch on', $llms_cli['llms_txt'], true );
$llms_cli_keep = skyphusion_ai_llms_apply_cli( array( 'endpoint' => '' ), array(), array( 'llms_txt' => true ) );
expect( 'CLI keeps llms when the flag is omitted', $llms_cli_keep['llms_txt'], true );

$body = skyphusion_ai_llms_body(
	array(
		'name'        => 'Demo Shop',
		'home'        => 'https://shop.example/',
		'description' => 'Hats and caps.',
		'endpoint'    => 'https://demo.search.ai.cloudflare.com/',
	)
);
expect( 'llms body names the site', strpos( $body, '# Demo Shop' ) !== false, true );
expect( 'llms body names the home URL', strpos( $body, 'https://shop.example/' ) !== false, true );
expect( 'llms body names AI Search', strpos( $body, 'https://demo.search.ai.cloudflare.com/' ) !== false, true );
expect( 'llms body names the search ability', strpos( $body, 'skyphusion-ai/search' ) !== false, true );
expect( 'llms body names the provider', strpos( $body, 'skyphusion-ai' ) !== false, true );
expect( 'llms body keeps checkout human', strpos( $body, 'Checkout stays a person.' ) !== false, true );

expect( 'llms path accepts /llms.txt', skyphusion_ai_llms_path( '/llms.txt' ), true );
expect( 'llms path accepts trailing slash home', skyphusion_ai_llms_path( '/llms.txt?x=1' ), true );
expect( 'llms path rejects other files', skyphusion_ai_llms_path( '/robots.txt' ), false );
expect( 'llms path rejects a nested file', skyphusion_ai_llms_path( '/shop/llms.txt' ), false );

$llms_home_was = isset( $GLOBALS['cfais_home'] ) ? $GLOBALS['cfais_home'] : null;
$GLOBALS['cfais_home'] = 'https://shop.example/blog';
expect( 'llms path matches llms.txt under the home path', skyphusion_ai_llms_path( '/blog/llms.txt' ), true );
expect( 'llms path matches the home file with a query', skyphusion_ai_llms_path( '/blog/llms.txt?x=1' ), true );
expect( 'llms path does not match the site root when home is nested', skyphusion_ai_llms_path( '/llms.txt' ), false );
expect( 'llms path does not match a deeper file', skyphusion_ai_llms_path( '/blog/shop/llms.txt' ), false );
if ( null === $llms_home_was ) {
	unset( $GLOBALS['cfais_home'] );
} else {
	$GLOBALS['cfais_home'] = $llms_home_was;
}

$llms_nosniff = false;
if ( function_exists( 'skyphusion_ai_llms_response_headers' ) ) {
	if ( ! isset( $GLOBALS['cfais_stored'] ) || ! is_array( $GLOBALS['cfais_stored'] ) ) {
		$GLOBALS['cfais_stored'] = array();
	}
	$llms_stored_txt = array_key_exists( 'llms_txt', $GLOBALS['cfais_stored'] ) ? $GLOBALS['cfais_stored']['llms_txt'] : null;
	$GLOBALS['cfais_stored']['llms_txt'] = true;
	$llms_uri_was = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
	$_SERVER['REQUEST_URI'] = '/llms.txt';
	$GLOBALS['skyphusion_ai_llms_capture'] = true;
	$GLOBALS['skyphusion_ai_llms_sent']    = array();
	ob_start();
	skyphusion_ai_llms_serve();
	$llms_served = ob_get_clean();
	$_SERVER['REQUEST_URI'] = '/shop/llms.txt';
	ob_start();
	skyphusion_ai_llms_serve();
	$llms_nested_body = ob_get_clean();
	expect( 'a nested llms.txt is not served', (string) $llms_nested_body, '' );
	unset( $GLOBALS['skyphusion_ai_llms_capture'] );
	$llms_sent = isset( $GLOBALS['skyphusion_ai_llms_sent'] ) && is_array( $GLOBALS['skyphusion_ai_llms_sent'] ) ? $GLOBALS['skyphusion_ai_llms_sent'] : array();
	foreach ( $llms_sent as $llms_header_line ) {
		if ( false !== stripos( (string) $llms_header_line, 'nosniff' ) ) {
			$llms_nosniff = true;
		}
	}
	expect( 'llms serve writes the body', false !== strpos( (string) $llms_served, 'Checkout stays a person.' ), true );
	if ( null === $llms_stored_txt ) {
		unset( $GLOBALS['cfais_stored']['llms_txt'] );
	} else {
		$GLOBALS['cfais_stored']['llms_txt'] = $llms_stored_txt;
	}
	if ( null === $llms_uri_was ) {
		unset( $_SERVER['REQUEST_URI'] );
	} else {
		$_SERVER['REQUEST_URI'] = $llms_uri_was;
	}
}
expect( 'llms sends nosniff', $llms_nosniff, true );

$_POST['skyphusion_ai_settings'] = array(
	'endpoint' => 'https://abc123.search.ai.cloudflare.com',
);
$llms_unticked = skyphusion_ai_llms_sanitize_option(
	array( 'endpoint' => 'https://abc123.search.ai.cloudflare.com' ),
	'skyphusion_ai_settings',
	array(
		'endpoint' => 'https://abc123.search.ai.cloudflare.com',
		'llms_txt' => true,
	)
);
expect( 'untick saves llms off', ! empty( $llms_unticked['llms_txt'] ), false );
$_POST['skyphusion_ai_settings'] = array(
	'endpoint' => 'https://abc123.search.ai.cloudflare.com',
	'llms_txt' => '0',
);
$llms_posted_off = skyphusion_ai_llms_sanitize_option(
	array( 'endpoint' => 'https://abc123.search.ai.cloudflare.com' ),
	'skyphusion_ai_settings',
	array( 'llms_txt' => true )
);
expect( 'a posted zero saves llms off', ! empty( $llms_posted_off['llms_txt'] ), false );
$_POST['skyphusion_ai_settings'] = array(
	'endpoint' => 'https://abc123.search.ai.cloudflare.com',
	'llms_txt' => '1',
);
$llms_posted_on = skyphusion_ai_llms_sanitize_option(
	array( 'endpoint' => 'https://abc123.search.ai.cloudflare.com' ),
	'skyphusion_ai_settings',
	array( 'llms_txt' => false )
);
expect( 'a posted one saves llms on', ! empty( $llms_posted_on['llms_txt'] ), true );
unset( $_POST['skyphusion_ai_settings'] );

$llms_kept = skyphusion_ai_llms_sanitize_option(
	array( 'endpoint' => '' ),
	'skyphusion_ai_settings',
	array( 'llms_txt' => true )
);
expect( 'a save with no form keeps llms on', ! empty( $llms_kept['llms_txt'] ), true );
