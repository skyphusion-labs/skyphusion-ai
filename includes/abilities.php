<?php
/**
 * Register the read-only abilities.
 *
 * Search, related pages, member search, and status. Each ability is
 * read-only. This file does not open an MCP server. It does not edit
 * posts. It does not change a cart.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability ids this plugin registers.
 *
 * @return array<int, string>
 */
function skyphusion_ai_ability_ids() {
	return array(
		skyphusion_ai_search_ability_id(),
		skyphusion_ai_related_ability_id(),
		skyphusion_ai_member_ability_id(),
		skyphusion_ai_status_ability_id(),
	);
}

/**
 * Related-pages ability id.
 *
 * @return string
 */
function skyphusion_ai_related_ability_id() {
	return 'skyphusion-ai/related';
}

/**
 * Member-search ability id.
 *
 * @return string
 */
function skyphusion_ai_member_ability_id() {
	return 'skyphusion-ai/member-search';
}

/**
 * Status ability id.
 *
 * @return string
 */
function skyphusion_ai_status_ability_id() {
	return 'skyphusion-ai/status';
}

/**
 * Arguments for the related ability.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_related_ability_args() {
	return array(
		'label'               => __( 'Related pages', 'skyphusion-ai' ),
		'description'         => __( 'List related pages for one post from Vectorize. The call does not change a cart.', 'skyphusion-ai' ),
		'category'            => skyphusion_ai_search_ability_category(),
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'The post id.', 'skyphusion-ai' ),
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'pages' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'title'  => array( 'type' => 'string' ),
							'url'    => array( 'type' => 'string' ),
							'reason' => array( 'type' => 'string' ),
							'score'  => array( 'type' => 'number' ),
						),
						'required'   => array( 'title', 'url', 'reason', 'score' ),
					),
				),
			),
			'required'   => array( 'pages' ),
		),
		'execute_callback'    => 'skyphusion_ai_related_ability_run',
		'permission_callback' => 'skyphusion_ai_search_ability_allowed',
		'meta'                => skyphusion_ai_ability_readonly_meta(),
	);
}

/**
 * Shared read-only MCP meta.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_ability_readonly_meta() {
	return array(
		'mcp'         => array(
			'public' => true,
			'type'   => 'tool',
		),
		'annotations' => array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		),
	);
}

/**
 * Keep only the public related fields.
 *
 * @param mixed $rows Related rows.
 * @return array{pages: array<int, array{title: string, url: string, reason: string, score: float}>}
 */
function skyphusion_ai_related_ability_output( $rows ) {
	$pages = array();
	if ( ! is_array( $rows ) ) {
		return array( 'pages' => $pages );
	}
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$pages[] = array(
			'title'  => isset( $row['title'] ) ? (string) $row['title'] : '',
			'url'    => isset( $row['url'] ) ? (string) $row['url'] : '',
			'reason' => isset( $row['reason'] ) ? (string) $row['reason'] : '',
			'score'  => isset( $row['score'] ) && is_numeric( $row['score'] ) ? (float) $row['score'] : 0.0,
		);
	}
	return array( 'pages' => $pages );
}

/**
 * Load one post as an array for related rows.
 *
 * @param int $post_id Post id.
 * @return array<string, mixed>|null
 */
function skyphusion_ai_related_ability_post( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id < 1 ) {
		return null;
	}
	if ( ! function_exists( 'get_post' ) ) {
		return null;
	}
	$post = get_post( $post_id );
	if ( ! $post ) {
		return null;
	}
	if ( is_array( $post ) ) {
		return $post;
	}
	if ( is_object( $post ) ) {
		return array(
			'ID'           => isset( $post->ID ) ? (int) $post->ID : $post_id,
			'post_type'    => isset( $post->post_type ) ? (string) $post->post_type : '',
			'post_status'  => isset( $post->post_status ) ? (string) $post->post_status : '',
			'post_title'   => isset( $post->post_title ) ? (string) $post->post_title : '',
			'post_content' => isset( $post->post_content ) ? (string) $post->post_content : '',
		);
	}
	return null;
}

/**
 * Run the related ability.
 *
 * @param mixed $input Ability input.
 * @return array{pages: array<int, array{title: string, url: string, reason: string, score: float}>}|WP_Error
 */
function skyphusion_ai_related_ability_run( $input ) {
	$post_id = 0;
	if ( is_array( $input ) && isset( $input['post_id'] ) ) {
		$post_id = (int) $input['post_id'];
	}
	$post = skyphusion_ai_related_ability_post( $post_id );
	if ( null === $post ) {
		if ( function_exists( 'WP_Error' ) || class_exists( 'WP_Error' ) ) {
			return new WP_Error( 'skyphusion_ai_related', __( 'That post was not found.', 'skyphusion-ai' ) );
		}
		return array( 'pages' => array() );
	}
	if ( ! function_exists( 'skyphusion_ai_related_rows' ) ) {
		return array( 'pages' => array() );
	}
	return skyphusion_ai_related_ability_output( skyphusion_ai_related_rows( $post ) );
}

/**
 * Arguments for the member-search ability.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_member_ability_args() {
	return array(
		'label'               => __( 'Member search', 'skyphusion-ai' ),
		'description'         => __( 'Search stored pages for a signed-in member. The call does not change a cart.', 'skyphusion-ai' ),
		'category'            => skyphusion_ai_search_ability_category(),
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'query' => array(
					'type'        => 'string',
					'description' => __( 'The search words.', 'skyphusion-ai' ),
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'state' => array( 'type' => 'string' ),
				'pages' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'title' => array( 'type' => 'string' ),
							'url'   => array( 'type' => 'string' ),
						),
						'required'   => array( 'title', 'url' ),
					),
				),
			),
			'required'   => array( 'state', 'pages' ),
		),
		'execute_callback'    => 'skyphusion_ai_member_ability_run',
		'permission_callback' => 'skyphusion_ai_member_ability_allowed',
		'meta'                => skyphusion_ai_ability_readonly_meta(),
	);
}

/**
 * True when the current user may call member search.
 *
 * @return bool
 */
function skyphusion_ai_member_ability_allowed() {
	if ( function_exists( 'skyphusion_ai_member_allowed' ) ) {
		return (bool) skyphusion_ai_member_allowed();
	}
	return false;
}

/**
 * Shape member results for the ability.
 *
 * @param mixed $rows Member results.
 * @return array{state: string, pages: array<int, array{title: string, url: string}>}
 */
function skyphusion_ai_member_ability_output( $rows ) {
	if ( is_array( $rows ) && isset( $rows['state'] ) ) {
		return array(
			'state' => (string) $rows['state'],
			'pages' => array(),
		);
	}
	$pages = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$pages[] = array(
				'title' => isset( $row['title'] ) ? (string) $row['title'] : '',
				'url'   => isset( $row['url'] ) ? (string) $row['url'] : '',
			);
		}
	}
	return array(
		'state' => 'ok',
		'pages' => $pages,
	);
}

/**
 * Run the member-search ability.
 *
 * @param mixed $input Ability input.
 * @return array{state: string, pages: array<int, array{title: string, url: string}>}
 */
function skyphusion_ai_member_ability_run( $input ) {
	$query = '';
	if ( is_array( $input ) && isset( $input['query'] ) ) {
		$query = (string) $input['query'];
	}
	if ( ! function_exists( 'skyphusion_ai_member_results' ) ) {
		return array(
			'state' => 'blocked',
			'pages' => array(),
		);
	}
	return skyphusion_ai_member_ability_output( skyphusion_ai_member_results( $query ) );
}

/**
 * Snapshot for the status ability. No network call.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_status_snapshot() {
	$gateway = function_exists( 'skyphusion_ai_gateway_status' ) ? skyphusion_ai_gateway_status() : array();
	$neurons = 0;
	$cap     = 10000;
	$day     = '';
	if ( function_exists( 'skyphusion_ai_neuron_state' ) ) {
		$state   = skyphusion_ai_neuron_state();
		$neurons = isset( $state['neurons'] ) ? (int) $state['neurons'] : 0;
		$day     = isset( $state['day'] ) ? (string) $state['day'] : '';
	}
	if ( function_exists( 'skyphusion_ai_neuron_cap' ) ) {
		$cap = (int) skyphusion_ai_neuron_cap();
	}
	$embed_on      = false;
	$embed_index   = '';
	$embed_created = false;
	if ( function_exists( 'skyphusion_ai_embed_config' ) ) {
		$config        = skyphusion_ai_embed_config();
		$embed_index   = isset( $config['index'] ) ? (string) $config['index'] : '';
		$embed_created = ! empty( $config['created'] );
		$embed_on      = function_exists( 'skyphusion_ai_embed_on' ) ? (bool) skyphusion_ai_embed_on( $config ) : false;
	}
	$endpoint = '';
	if ( function_exists( 'skyphusion_ai_settings' ) ) {
		$settings = skyphusion_ai_settings();
		if ( is_array( $settings ) && isset( $settings['endpoint'] ) ) {
			$endpoint = (string) $settings['endpoint'];
		}
	}
	return array(
		'gateway_id'       => isset( $gateway['gateway_id'] ) ? (string) $gateway['gateway_id'] : '',
		'gateway_host'     => isset( $gateway['host'] ) ? (string) $gateway['host'] : '',
		'gateway_fallback' => ! empty( $gateway['fallback_active'] ),
		'gateway_route'    => isset( $gateway['route'] ) ? (string) $gateway['route'] : '',
		'log_prompts'      => ! empty( $gateway['log_prompts'] ),
		'neurons'          => $neurons,
		'neuron_cap'       => $cap,
		'neuron_day'       => $day,
		'embed_on'         => $embed_on,
		'embed_index'      => $embed_index,
		'embed_created'    => $embed_created,
		'search_endpoint'  => '' !== $endpoint,
		'provider_id'      => 'skyphusion-ai',
	);
}

/**
 * Arguments for the status ability.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_status_ability_args() {
	return array(
		'label'               => __( 'Skyphusion AI status', 'skyphusion-ai' ),
		'description'         => __( 'Read gateway, budget, and embed state. The call does not reach Cloudflare.', 'skyphusion-ai' ),
		'category'            => skyphusion_ai_search_ability_category(),
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'gateway_id'       => array( 'type' => 'string' ),
				'gateway_host'     => array( 'type' => 'string' ),
				'gateway_fallback' => array( 'type' => 'boolean' ),
				'gateway_route'    => array( 'type' => 'string' ),
				'log_prompts'      => array( 'type' => 'boolean' ),
				'neurons'          => array( 'type' => 'integer' ),
				'neuron_cap'       => array( 'type' => 'integer' ),
				'neuron_day'       => array( 'type' => 'string' ),
				'embed_on'         => array( 'type' => 'boolean' ),
				'embed_index'      => array( 'type' => 'string' ),
				'embed_created'    => array( 'type' => 'boolean' ),
				'search_endpoint'  => array( 'type' => 'boolean' ),
				'provider_id'      => array( 'type' => 'string' ),
			),
			'required'   => array(
				'gateway_id',
				'gateway_host',
				'gateway_fallback',
				'gateway_route',
				'log_prompts',
				'neurons',
				'neuron_cap',
				'neuron_day',
				'embed_on',
				'embed_index',
				'embed_created',
				'search_endpoint',
				'provider_id',
			),
		),
		'execute_callback'    => 'skyphusion_ai_status_ability_run',
		'permission_callback' => 'skyphusion_ai_status_ability_allowed',
		'meta'                => skyphusion_ai_ability_readonly_meta(),
	);
}

/**
 * Admins may read the status ability.
 *
 * @return bool
 */
function skyphusion_ai_status_ability_allowed() {
	if ( ! function_exists( 'current_user_can' ) ) {
		return false;
	}
	return (bool) current_user_can( 'manage_options' );
}

/**
 * Run the status ability.
 *
 * @param mixed $input Ability input.
 * @return array<string, mixed>
 */
function skyphusion_ai_status_ability_run( $input ) {
	unset( $input );
	return skyphusion_ai_status_snapshot();
}

/**
 * Register every ability when WordPress provides the API.
 *
 * @return void
 */
function skyphusion_ai_abilities_register() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	if ( function_exists( 'wp_register_ability_category' ) ) {
		wp_register_ability_category(
			skyphusion_ai_search_ability_category(),
			array(
				'label'       => __( 'Skyphusion AI', 'skyphusion-ai' ),
				'description' => __( 'Read-only search, related pages, member search, and status.', 'skyphusion-ai' ),
			)
		);
	}
	wp_register_ability( skyphusion_ai_search_ability_id(), skyphusion_ai_search_ability_args() );
	wp_register_ability( skyphusion_ai_related_ability_id(), skyphusion_ai_related_ability_args() );
	wp_register_ability( skyphusion_ai_member_ability_id(), skyphusion_ai_member_ability_args() );
	wp_register_ability( skyphusion_ai_status_ability_id(), skyphusion_ai_status_ability_args() );
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_init', 'skyphusion_ai_abilities_register' );
}
