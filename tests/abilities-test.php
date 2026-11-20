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
$abilities_perm_can  = isset( $GLOBALS['cfais_can'] ) && is_array( $GLOBALS['cfais_can'] ) ? $GLOBALS['cfais_can'] : array();
$abilities_perm_user = isset( $GLOBALS['cfais_user_id'] ) ? $GLOBALS['cfais_user_id'] : null;
$GLOBALS['cfais_can'] = array();
$GLOBALS['cfais_user_id'] = 0;
expect( 'status callback refuses when manage_options is off', skyphusion_ai_status_ability_allowed(), false );
expect( 'search callback refuses when read is off', skyphusion_ai_search_ability_allowed(), false );
expect( 'related callback refuses when read is off', call_user_func( $related_args['permission_callback'] ), false );
expect( 'member callback refuses a signed-out user', skyphusion_ai_member_ability_allowed(), false );
$GLOBALS['cfais_can'] = array( 'manage_options' => true );
expect( 'status callback allows manage_options', skyphusion_ai_status_ability_allowed(), true );
$GLOBALS['cfais_can'] = array( 'read' => true );
expect( 'search callback allows read', skyphusion_ai_search_ability_allowed(), true );
expect( 'related callback allows read', call_user_func( $related_args['permission_callback'] ), true );
$GLOBALS['cfais_can'] = $abilities_perm_can;
if ( null === $abilities_perm_user ) {
	unset( $GLOBALS['cfais_user_id'] );
} else {
	$GLOBALS['cfais_user_id'] = $abilities_perm_user;
}

$snap = skyphusion_ai_status_snapshot();
expect( 'status snapshot names the provider', $snap['provider_id'], 'skyphusion-ai' );
expect( 'status snapshot has neuron_cap', isset( $snap['neuron_cap'] ), true );
expect( 'status snapshot has gateway_host', isset( $snap['gateway_host'] ), true );

$abilities_gw_id   = array_key_exists( 'gateway_id', $GLOBALS['cfais_stored'] ) ? $GLOBALS['cfais_stored']['gateway_id'] : null;
$abilities_gw_down = isset( $GLOBALS['cfais_options']['skyphusion_ai_gateway_down'] ) ? $GLOBALS['cfais_options']['skyphusion_ai_gateway_down'] : null;
$GLOBALS['cfais_stored']['gateway_id'] = 'store';
update_option( 'skyphusion_ai_gateway_down', time() + 3600, false );
$abilities_fallback = skyphusion_ai_status_snapshot();
expect( 'status ability keeps the saved gateway id during fallback', $abilities_fallback['gateway_id'], 'store' );
expect( 'status ability says fallback is on', $abilities_fallback['gateway_fallback'], true );
if ( null === $abilities_gw_id ) {
	unset( $GLOBALS['cfais_stored']['gateway_id'] );
} else {
	$GLOBALS['cfais_stored']['gateway_id'] = $abilities_gw_id;
}
if ( null === $abilities_gw_down ) {
	unset( $GLOBALS['cfais_options']['skyphusion_ai_gateway_down'] );
} else {
	$GLOBALS['cfais_options']['skyphusion_ai_gateway_down'] = $abilities_gw_down;
}

$abilities_can        = isset( $GLOBALS['cfais_can'] ) && is_array( $GLOBALS['cfais_can'] ) ? $GLOBALS['cfais_can'] : array();
$abilities_can_post   = isset( $GLOBALS['cfais_can_post'] ) ? $GLOBALS['cfais_can_post'] : null;
$abilities_settings   = isset( $GLOBALS['cfais_settings'] ) ? $GLOBALS['cfais_settings'] : null;
$abilities_status     = isset( $GLOBALS['cfais_status'] ) ? $GLOBALS['cfais_status'] : null;
$abilities_bodies     = isset( $GLOBALS['cfais_bodies'] ) ? $GLOBALS['cfais_bodies'] : null;
$abilities_calls      = isset( $GLOBALS['cfais_calls'] ) ? $GLOBALS['cfais_calls'] : 0;
$abilities_vector     = isset( $GLOBALS['cfais_options']['skyphusion_ai_vectorize'] ) ? $GLOBALS['cfais_options']['skyphusion_ai_vectorize'] : null;
$abilities_key        = getenv( 'CLOUDFLARE_API_KEY' );
$abilities_account    = getenv( 'CLOUDFLARE_ACCOUNT_ID' );
$abilities_transport  = ! empty( $GLOBALS['cfais_transport_error'] );

putenv( 'CLOUDFLARE_API_KEY=' . str_repeat( 'b', 40 ) );
putenv( 'CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' );
$GLOBALS['cfais_settings'] = array(
	'endpoint'   => 'https://abc123.search.ai.cloudflare.com',
	'account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
);
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_transport_error'] = false;
$GLOBALS['cfais_can'] = array( 'read' => true );
$GLOBALS['cfais_can_post'] = array(
	'read_post' => array(
		6  => true,
		7  => true,
		8  => false,
		9  => false,
		10 => true,
	),
);
$GLOBALS['cfais_posts_by_id'] = array(
	6  => embed_post( 6, 'Seed', 'Body' ),
	7  => embed_post( 7, 'Public Hat', 'Body' ),
	8  => embed_post( 8, 'Private Cap', 'Secret', array( 'post_status' => 'private' ) ),
	9  => embed_post( 9, 'Secret', 'Hidden', array( 'post_status' => 'private' ) ),
	10 => embed_post( 10, 'Locked', 'Body', array( 'post_password' => 'sesame' ) ),
);
update_option(
	'skyphusion_ai_vectorize',
	array(
		'index'      => 'skyphusion-ai',
		'post_types' => array( 'post' ),
		'fields'     => array( 'title', 'content' ),
		'model'      => '@cf/baai/bge-large-en-v1.5',
		'created'    => 1,
	),
	false
);

$abilities_stored = wp_json_encode(
	array(
		'success' => true,
		'result'  => array(
			array(
				'id'       => 'p6',
				'values'   => array( 0.2, 0.4 ),
				'metadata' => array(
					'title'   => 'Seed',
					'post_id' => '6',
				),
			),
		),
	)
);
$abilities_query = wp_json_encode(
	array(
		'success' => true,
		'result'  => array(
			'matches' => array(
				array(
					'id'       => 'p7',
					'score'    => 0.8,
					'metadata' => array(
						'title'   => 'Public Hat',
						'url'     => 'https://example.test/hat',
						'post_id' => '7',
					),
				),
				array(
					'id'       => 'p8',
					'score'    => 0.7,
					'metadata' => array(
						'title'   => 'Private Cap',
						'url'     => 'https://example.test/cap',
						'post_id' => '8',
					),
				),
			),
		),
	)
);

$GLOBALS['cfais_bodies'] = array( $abilities_stored, $abilities_query );
$GLOBALS['cfais_calls']  = 0;
$abilities_private = skyphusion_ai_related_ability_run( array( 'post_id' => 9 ) );
expect( 'a subscriber asking for a private post gets an empty list', $abilities_private, array( 'pages' => array() ) );
expect( 'a private post does not query Vectorize', $GLOBALS['cfais_calls'], 0 );

$GLOBALS['cfais_bodies'] = array( $abilities_stored, $abilities_query );
$GLOBALS['cfais_calls']  = 0;
$abilities_locked = skyphusion_ai_related_ability_run( array( 'post_id' => 10 ) );
expect( 'a password post gets an empty list', $abilities_locked, array( 'pages' => array() ) );

$abilities_missing = skyphusion_ai_related_ability_run( array( 'post_id' => 404 ) );
expect( 'a missing post gets an empty list', $abilities_missing, array( 'pages' => array() ) );
expect( 'a missing post matches a private post', $abilities_missing, $abilities_private );

$GLOBALS['cfais_bodies'] = array( $abilities_stored, $abilities_query );
$GLOBALS['cfais_calls']  = 0;
$abilities_public = skyphusion_ai_related_ability_run( array( 'post_id' => 6 ) );
$abilities_titles = array();
if ( is_array( $abilities_public ) && isset( $abilities_public['pages'] ) && is_array( $abilities_public['pages'] ) ) {
	foreach ( $abilities_public['pages'] as $abilities_page ) {
		$abilities_titles[] = isset( $abilities_page['title'] ) ? (string) $abilities_page['title'] : '';
	}
}
expect( 'a private match is left out', in_array( 'Private Cap', $abilities_titles, true ), false );
expect( 'a public match is kept', in_array( 'Public Hat', $abilities_titles, true ), true );

$GLOBALS['cfais_can'] = $abilities_can;
if ( null === $abilities_can_post ) {
	unset( $GLOBALS['cfais_can_post'] );
} else {
	$GLOBALS['cfais_can_post'] = $abilities_can_post;
}
unset( $GLOBALS['cfais_posts_by_id'] );
if ( null === $abilities_settings ) {
	unset( $GLOBALS['cfais_settings'] );
} else {
	$GLOBALS['cfais_settings'] = $abilities_settings;
}
if ( null === $abilities_status ) {
	unset( $GLOBALS['cfais_status'] );
} else {
	$GLOBALS['cfais_status'] = $abilities_status;
}
if ( null === $abilities_bodies ) {
	unset( $GLOBALS['cfais_bodies'] );
} else {
	$GLOBALS['cfais_bodies'] = $abilities_bodies;
}
$GLOBALS['cfais_calls'] = $abilities_calls;
if ( null === $abilities_vector ) {
	unset( $GLOBALS['cfais_options']['skyphusion_ai_vectorize'] );
} else {
	$GLOBALS['cfais_options']['skyphusion_ai_vectorize'] = $abilities_vector;
}
if ( false === $abilities_key || '' === $abilities_key ) {
	putenv( 'CLOUDFLARE_API_KEY' );
} else {
	putenv( 'CLOUDFLARE_API_KEY=' . $abilities_key );
}
if ( false === $abilities_account || '' === $abilities_account ) {
	putenv( 'CLOUDFLARE_ACCOUNT_ID' );
} else {
	putenv( 'CLOUDFLARE_ACCOUNT_ID=' . $abilities_account );
}
if ( $abilities_transport ) {
	$GLOBALS['cfais_transport_error'] = true;
} else {
	unset( $GLOBALS['cfais_transport_error'] );
}

expect( 'the plugin loads abilities.php', strpos( $abilities_plugin, '/includes/abilities.php' ) !== false, true );
expect( 'the doc names related', strpos( $abilities_doc, 'skyphusion-ai/related' ) !== false, true );
expect( 'the doc names member-search', strpos( $abilities_doc, 'skyphusion-ai/member-search' ) !== false, true );
expect( 'the doc names status', strpos( $abilities_doc, 'skyphusion-ai/status' ) !== false, true );
expect( 'the doc says status makes no network call', strpos( $abilities_doc, 'does not reach Cloudflare' ) !== false, true );
