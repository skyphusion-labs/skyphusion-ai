<?php
/**
 * Register the read-only search ability.
 *
 * The ability asks the public AI Search. It sends no token. It does
 * not change a cart. This file does not install the MCP Adapter.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability id the MCP Adapter lists.
 *
 * @return string
 */
function skyphusion_ai_search_ability_id() {
	return 'skyphusion-ai/search';
}

/**
 * Category id for the ability.
 *
 * @return string
 */
function skyphusion_ai_search_ability_category() {
	return 'skyphusion-ai';
}

/**
 * Arguments passed to wp_register_ability.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_search_ability_args() {
	return array(
		'label'               => __( 'Search the site', 'skyphusion-ai' ),
		'description'         => __( 'Ask the public AI Search. The call sends no token.', 'skyphusion-ai' ),
		'category'            => skyphusion_ai_search_ability_category(),
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'query' => array(
					'type'        => 'string',
					'description' => __( 'The question to ask.', 'skyphusion-ai' ),
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'answer'  => array(
					'type' => 'string',
				),
				'sources' => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
					),
				),
			),
			'required'   => array( 'answer', 'sources' ),
		),
		'execute_callback'    => 'skyphusion_ai_search_ability_run',
		'permission_callback' => 'skyphusion_ai_search_ability_allowed',
		'meta'                => array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Keep only the answer and the source list.
 *
 * @param mixed $result Result from skyphusion_ai_query.
 * @return array{answer: string, sources: array<int, string>}
 */
function skyphusion_ai_search_ability_output( $result ) {
	$answer  = '';
	$sources = array();
	if ( is_array( $result ) ) {
		if ( isset( $result['answer'] ) ) {
			$answer = (string) $result['answer'];
		}
		if ( isset( $result['sources'] ) && is_array( $result['sources'] ) ) {
			foreach ( $result['sources'] as $source ) {
				$sources[] = (string) $source;
			}
		}
	}
	return array(
		'answer'  => $answer,
		'sources' => $sources,
	);
}

/**
 * Run the search ability.
 *
 * @param mixed $input Ability input.
 * @return array{answer: string, sources: array<int, string>}|WP_Error
 */
function skyphusion_ai_search_ability_run( $input ) {
	$query = '';
	if ( is_array( $input ) && isset( $input['query'] ) ) {
		$query = (string) $input['query'];
	}
	$result = skyphusion_ai_query( $query );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return skyphusion_ai_search_ability_output( $result );
}

/**
 * A user who can read the site may call the ability.
 *
 * @return bool
 */
function skyphusion_ai_search_ability_allowed() {
	if ( ! function_exists( 'current_user_can' ) ) {
		return false;
	}
	return (bool) current_user_can( 'read' );
}

/**
 * Register the ability when WordPress provides the API.
 *
 * @return void
 */
function skyphusion_ai_search_ability_register() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	if ( function_exists( 'wp_register_ability_category' ) ) {
		wp_register_ability_category(
			skyphusion_ai_search_ability_category(),
			array(
				'label'       => __( 'Skyphusion AI', 'skyphusion-ai' ),
				'description' => __( 'Read-only search of the public AI Search index.', 'skyphusion-ai' ),
			)
		);
	}
	wp_register_ability( skyphusion_ai_search_ability_id(), skyphusion_ai_search_ability_args() );
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_init', 'skyphusion_ai_search_ability_register' );
}
