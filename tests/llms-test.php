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
