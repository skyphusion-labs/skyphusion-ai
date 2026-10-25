<?php
/**
 * Log fields for an AI Gateway request.
 *
 * A direct call to api.cloudflare.com does not get these fields.
 * The prompt is not a field.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * True when the URL host is the AI Gateway host.
 *
 * @param string $url Request URL.
 * @return bool
 */
function skyphusion_ai_gateway_host( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return false;
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return false;
	}
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- AI Gateway API host, not a remote asset.
	return 'gateway.ai.cloudflare.com' === strtolower( (string) $parts['host'] );
}

/**
 * Site address for the log, without a user, a query, or a fragment.
 *
 * @return string
 */
function skyphusion_ai_gateway_site() {
	if ( ! function_exists( 'home_url' ) ) {
		return '';
	}
	$parts = wp_parse_url( (string) home_url() );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	$site = $parts['scheme'] . '://' . $parts['host'];
	if ( ! empty( $parts['port'] ) ) {
		$site .= ':' . (int) $parts['port'];
	}
	if ( isset( $parts['path'] ) && is_string( $parts['path'] ) && '' !== $parts['path'] && '/' !== $parts['path'] ) {
		$site .= $parts['path'];
	}
	return $site;
}

/**
 * Numeric user id, or an empty string for a visitor.
 *
 * @return string
 */
function skyphusion_ai_gateway_user() {
	if ( ! function_exists( 'get_current_user_id' ) ) {
		return '';
	}
	$user_id = (int) get_current_user_id();
	if ( 1 > $user_id ) {
		return '';
	}
	return (string) $user_id;
}

/**
 * Feature name that may appear in the log.
 *
 * @param string $feature Requested feature.
 * @return string
 */
function skyphusion_ai_gateway_feature( $feature ) {
	$allowed = array( 'text', 'vision', 'image', 'speech', 'embeddings' );
	$feature = (string) $feature;
	if ( in_array( $feature, $allowed, true ) ) {
		return $feature;
	}
	return 'text';
}

/**
 * Four log fields. The prompt is not one of them.
 *
 * @param string $feature Feature name.
 * @param string $model   Model id.
 * @return array{site: string, feature: string, model: string, user: string}
 */
function skyphusion_ai_gateway_metadata( $feature, $model ) {
	$clean_model = '';
	if ( function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		$clean_model = cloudflare_ai_provider_model_id( $model );
	}
	return array(
		'site'    => skyphusion_ai_gateway_site(),
		'feature' => skyphusion_ai_gateway_feature( $feature ),
		'model'   => $clean_model,
		'user'    => skyphusion_ai_gateway_user(),
	);
}

/**
 * Header for a gateway URL, or an empty array for any other URL.
 *
 * @param string $url     Request URL.
 * @param string $feature Feature name.
 * @param string $model   Model id.
 * @return array<string, string>
 */
function skyphusion_ai_gateway_metadata_header( $url, $feature, $model ) {
	if ( ! skyphusion_ai_gateway_host( $url ) ) {
		return array();
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		return array();
	}
	$encoded = wp_json_encode( skyphusion_ai_gateway_metadata( $feature, $model ) );
	if ( ! is_string( $encoded ) || '' === $encoded ) {
		return array();
	}
	return array(
		'cf-aig-metadata' => $encoded,
	);
}

/**
 * Headers for one Workers AI POST from the AI Client.
 *
 * The feature and the model come from the neuron call already held for
 * that request. This function does not read the prompt.
 *
 * @param string $url   Request URL.
 * @param string $token Connectors token.
 * @return array<string, string>
 */
function skyphusion_ai_gateway_transport_headers( $url, $token ) {
	$feature = 'text';
	$model   = '';
	if ( isset( $GLOBALS['skyphusion_ai_neuron_call'] ) && is_array( $GLOBALS['skyphusion_ai_neuron_call'] ) ) {
		$call = $GLOBALS['skyphusion_ai_neuron_call'];
		if ( isset( $call['feature'] ) ) {
			$feature = (string) $call['feature'];
		}
		if ( isset( $call['model'] ) ) {
			$model = (string) $call['model'];
		}
	}
	$headers = array(
		'Content-Type'  => 'application/json',
		'Authorization' => 'Bearer ' . $token,
	);
	return array_merge( $headers, skyphusion_ai_gateway_metadata_header( $url, $feature, $model ) );
}
