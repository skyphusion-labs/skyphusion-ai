<?php
/**
 * Optional llms.txt. Issue #228.
 */

require_once dirname( __DIR__ ) . '/includes/llms.php';

$llms_root   = dirname( __DIR__ );
$llms_plugin = (string) file_get_contents( $llms_root . '/skyphusion-ai.php' );
$llms_readme = (string) file_get_contents( $llms_root . '/README.md' );
$llms_php    = (string) file_get_contents( $llms_root . '/includes/llms.php' );

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

expect( 'the plugin loads llms.php', strpos( $llms_plugin, '/includes/llms.php' ) !== false, true );
expect( 'llms hooks template_redirect', strpos( $llms_php, 'template_redirect' ) !== false, true );
expect( 'README mentions llms.txt', strpos( $llms_readme, 'llms.txt' ) !== false, true );
