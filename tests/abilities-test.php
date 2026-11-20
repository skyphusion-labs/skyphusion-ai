<?php
/**
 * Extra read-only abilities. Issues #225.
 */

require_once dirname( __DIR__ ) . '/includes/search-ability.php';
require_once dirname( __DIR__ ) . '/includes/abilities.php';

$abilities_root = dirname( __DIR__ );
$abilities_doc  = (string) file_get_contents( $abilities_root . '/docs/search-ability.md' );
$abilities_plugin = (string) file_get_contents( $abilities_root . '/skyphusion-ai.php' );

expect( 'related ability id', skyphusion_ai_related_ability_id(), 'skyphusion-ai/related' );
expect( 'member ability id', skyphusion_ai_member_ability_id(), 'skyphusion-ai/member-search' );
expect( 'status ability id', skyphusion_ai_status_ability_id(), 'skyphusion-ai/status' );
expect(
	'ability ids list the four tools',
	skyphusion_ai_ability_ids(),
	array(
		'skyphusion-ai/search',
		'skyphusion-ai/related',
		'skyphusion-ai/member-search',
		'skyphusion-ai/status',
	)
);

$related_args = skyphusion_ai_related_ability_args();
expect( 'related is read-only', $related_args['meta']['annotations']['readonly'], true );
expect( 'related requires post_id', $related_args['input_schema']['required'], array( 'post_id' ) );
expect( 'related output requires pages', $related_args['output_schema']['required'], array( 'pages' ) );

$related_out = skyphusion_ai_related_ability_output(
	array(
		array(
			'title'  => 'Cap',
			'url'    => 'https://example.com/cap/',
			'reason' => 'same tags',
			'score'  => 0.9,
			'id'     => 'drop-me',
		),
	)
);
expect( 'related output keeps title', $related_out['pages'][0]['title'], 'Cap' );
expect( 'related output drops id', isset( $related_out['pages'][0]['id'] ), false );
expect( 'related output empty for null', skyphusion_ai_related_ability_output( null ), array( 'pages' => array() ) );

$member_args = skyphusion_ai_member_ability_args();
expect( 'member is read-only', $member_args['meta']['annotations']['readonly'], true );
expect( 'member requires query', $member_args['input_schema']['required'], array( 'query' ) );

$member_out = skyphusion_ai_member_ability_output(
	array(
		array(
			'title' => 'Hat',
			'url'   => 'https://example.com/hat/',
		),
	)
);
expect( 'member output state is ok', $member_out['state'], 'ok' );
expect( 'member output keeps the page', $member_out['pages'][0]['title'], 'Hat' );
expect(
	'member closed state clears pages',
	skyphusion_ai_member_ability_output( array( 'state' => 'closed' ) ),
	array(
		'state' => 'closed',
		'pages' => array(),
	)
);

$status_args = skyphusion_ai_status_ability_args();
expect( 'status is read-only', $status_args['meta']['annotations']['readonly'], true );
expect( 'status permission is manage_options', $status_args['permission_callback'], 'skyphusion_ai_status_ability_allowed' );
expect( 'status is refused without WordPress', skyphusion_ai_status_ability_allowed(), false );

$snap = skyphusion_ai_status_snapshot();
expect( 'status snapshot names the provider', $snap['provider_id'], 'skyphusion-ai' );
expect( 'status snapshot has neuron_cap', isset( $snap['neuron_cap'] ), true );
expect( 'status snapshot has gateway_host', isset( $snap['gateway_host'] ), true );

expect( 'the plugin loads abilities.php', strpos( $abilities_plugin, '/includes/abilities.php' ) !== false, true );
expect( 'the doc names related', strpos( $abilities_doc, 'skyphusion-ai/related' ) !== false, true );
expect( 'the doc names member-search', strpos( $abilities_doc, 'skyphusion-ai/member-search' ) !== false, true );
expect( 'the doc names status', strpos( $abilities_doc, 'skyphusion-ai/status' ) !== false, true );
expect( 'the doc says status makes no network call', strpos( $abilities_doc, 'does not reach Cloudflare' ) !== false, true );
