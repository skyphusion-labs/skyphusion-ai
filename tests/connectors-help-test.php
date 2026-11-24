<?php
/**
 * Connectors help when the AI Client screen is absent. Issue #247.
 *
 * Loaded by tests/endpoint-test.php after settings.php is available.
 */

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Stand-in. The harness is not a WordPress bootstrap.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return (string) $url;
	}
}

expect(
	'connectors available is a function',
	function_exists( 'skyphusion_ai_connectors_available' ),
	true
);
expect(
	'workers token help is a function',
	function_exists( 'skyphusion_ai_workers_token_help' ),
	true
);

// This harness does not load the WordPress AI Client.
expect(
	'connectors are absent in the unit harness',
	skyphusion_ai_connectors_available(),
	false
);

$absent = skyphusion_ai_workers_token_help( false, '' );
expect(
	'absent help names the environment variable',
	strpos( $absent, 'CLOUDFLARE_API_KEY' ) !== false,
	true
);
expect(
	'absent help names WordPress 7.0',
	strpos( $absent, 'WordPress 7.0' ) !== false,
	true
);
expect(
	'absent help does not link to options-connectors.php',
	strpos( $absent, 'options-connectors.php' ) === false,
	true
);
expect(
	'absent help has no href',
	strpos( $absent, 'href=' ) === false,
	true
);

$present = skyphusion_ai_workers_token_help( true, 'https://example.test/wp-admin/options-connectors.php' );
expect(
	'present help links to Connectors',
	strpos( $present, 'options-connectors.php' ) !== false,
	true
);
expect(
	'present help keeps the provider id',
	strpos( $present, 'skyphusion-ai' ) !== false,
	true
);
expect(
	'present help does not print a bare false URL',
	strpos( skyphusion_ai_workers_token_help( true, '' ), 'options-connectors.php' ) === false,
	true
);

$settings_php = (string) file_get_contents( dirname( __DIR__ ) . '/includes/settings.php' );
expect(
	'the workers section calls the help helper',
	strpos( $settings_php, 'skyphusion_ai_workers_token_help' ) !== false,
	true
);
expect(
	'the workers section checks connectors availability',
	strpos( $settings_php, 'skyphusion_ai_connectors_available' ) !== false,
	true
);
expect(
	'the workers section only builds the Connectors URL when available',
	strpos( $settings_php, '$connectors_available ? admin_url( \'options-connectors.php\' )' ) !== false
		|| strpos( $settings_php, '$connectors_available ? admin_url( "options-connectors.php" )' ) !== false,
	true
);
