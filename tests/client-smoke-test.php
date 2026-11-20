<?php
/**
 * AI Client smoke check. Issue #227.
 *
 * The AI Client is absent in this harness. The smoke must report that
 * and must not call Cloudflare.
 */

$smoke = skyphusion_ai_client_smoke();
expect( 'smoke names the provider id', $smoke['provider_id'], 'skyphusion-ai' );
expect( 'smoke finds no AI Client here', $smoke['ai_client'], false );
expect( 'smoke finds no provider class here', $smoke['provider_class'], false );
expect( 'smoke finds no registration here', $smoke['registered'], false );
expect( 'smoke never dials the network', $smoke['network'], false );
expect( 'smoke state names the missing client', $smoke['state'], 'ai_client_missing' );

$lines = skyphusion_ai_client_smoke_lines( $smoke );
expect( 'smoke lines name the provider', $lines[0], 'provider_id=skyphusion-ai' );
expect( 'smoke lines mark network off', in_array( 'network=0', $lines, true ), true );
expect( 'smoke lines mark the missing client', in_array( 'state=ai_client_missing', $lines, true ), true );

$cli_php = (string) file_get_contents( dirname( __DIR__ ) . '/includes/cli.php' );
expect( 'CLI exposes the smoke command', strpos( $cli_php, 'public function smoke' ) !== false, true );
expect( 'README names the smoke command', strpos( (string) file_get_contents( dirname( __DIR__ ) . '/README.md' ), 'wp skyphusion-ai smoke' ) !== false, true );
