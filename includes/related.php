<?php
/**
 * Related posts from the Vectorize index.
 *
 * A page view reads the stored vector. It does not call the embeddings
 * model. The list is empty when the index has no vector for this post.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reason shown beside a related page. It is not written by a model.
 *
 * @return string
 */
function skyphusion_ai_related_reason() {
	return __( 'Close to this page.', 'skyphusion-ai' );
}

/**
 * Read one stored vector. An empty result means this post is not in the index.
 *
 * @param int                   $post_id     Post id.
 * @param array<string, mixed>  $config      Saved choices.
 * @param array<string, string> $credentials Account id and token.
 * @return array<int, float>
 */
function skyphusion_ai_related_vector( $post_id, $config, $credentials ) {
	$url  = skyphusion_ai_vectorize_url( $credentials['account_id'], (string) $config['index'], 'get_by_ids' );
	$body = wp_json_encode( array( 'ids' => array( skyphusion_ai_embed_vector_id( $post_id ) ) ) );
	$read = skyphusion_ai_vectorize_request( 'POST', $url, $credentials['token'], (string) $body, 'application/json' );
	if ( is_wp_error( $read ) ) {
		return array();
	}
	$rows = array();
	if ( isset( $read['result'] ) && is_array( $read['result'] ) && isset( $read['result'][0] ) ) {
		$rows = $read['result'];
	} elseif ( isset( $read['result']['vectors'] ) && is_array( $read['result']['vectors'] ) ) {
		$rows = $read['result']['vectors'];
	}
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['values'] ) || ! is_array( $row['values'] ) ) {
			continue;
		}
		$values = array();
		foreach ( $row['values'] as $number ) {
			if ( ! is_numeric( $number ) ) {
				return array();
			}
			$values[] = (float) $number;
		}
		return $values;
	}
	return array();
}

/**
 * Related pages for one post. This does not call the embeddings model.
 *
 * @param array<string, mixed> $post One post.
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_related_rows( $post ) {
	$config = skyphusion_ai_embed_config();
	if ( empty( $config['created'] ) || ! is_array( $post ) ) {
		return array();
	}
	$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
	if ( $post_id < 1 ) {
		return array();
	}
	$credentials = skyphusion_ai_embed_credentials();
	$vector      = skyphusion_ai_related_vector( $post_id, $config, $credentials );
	if ( array() === $vector ) {
		return array();
	}
	$url     = skyphusion_ai_vectorize_url( $credentials['account_id'], (string) $config['index'], 'query' );
	$body    = wp_json_encode(
		array(
			'vector'         => $vector,
			'topK'           => 6,
			'returnMetadata' => 'all',
		)
	);
	$decoded = skyphusion_ai_vectorize_request( 'POST', $url, $credentials['token'], (string) $body, 'application/json' );
	if ( is_wp_error( $decoded ) || ! isset( $decoded['result']['matches'] ) || ! is_array( $decoded['result']['matches'] ) ) {
		return array();
	}
	$own    = skyphusion_ai_embed_vector_id( $post_id );
	$rows   = array();
	$reason = skyphusion_ai_related_reason();
	foreach ( $decoded['result']['matches'] as $match ) {
		if ( ! is_array( $match ) ) {
			continue;
		}
		$id = isset( $match['id'] ) ? (string) $match['id'] : '';
		if ( '' === $id || $id === $own ) {
			continue;
		}
		$metadata = array();
		if ( isset( $match['metadata'] ) && is_array( $match['metadata'] ) ) {
			$metadata = $match['metadata'];
		}
		$title  = isset( $metadata['title'] ) ? (string) $metadata['title'] : '';
		$link   = isset( $metadata['url'] ) ? skyphusion_ai_embed_public_url( (string) $metadata['url'] ) : '';
		$rows[] = array(
			'id'     => $id,
			'title'  => $title,
			'url'    => $link,
			'reason' => $reason,
		);
		if ( count( $rows ) >= 5 ) {
			break;
		}
	}
	return $rows;
}

/**
 * HTML for the related list, or an empty string.
 *
 * @param array<string, mixed> $post One post.
 * @return string
 */
function skyphusion_ai_related_html( $post ) {
	$rows = skyphusion_ai_related_rows( $post );
	if ( array() === $rows ) {
		return '';
	}
	$html = '<section class="skyphusion-ai-related"><ul>';
	foreach ( $rows as $row ) {
		$html .= '<li>';
		if ( '' !== $row['url'] ) {
			$html .= '<a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['title'] ) . '</a>';
		} else {
			$html .= esc_html( $row['title'] );
		}
		$html .= ' <span>' . esc_html( $row['reason'] ) . '</span></li>';
	}
	$html .= '</ul></section>';
	return $html;
}

/**
 * Append the list when the switch is on. The shortcode is left as it is.
 *
 * @param string               $content Page body.
 * @param array<string, mixed> $post    One post.
 * @return string
 */
function skyphusion_ai_related_append( $content, $post ) {
	if ( ! is_string( $content ) ) {
		return '';
	}
	$config = skyphusion_ai_embed_config();
	if ( empty( $config['related'] ) ) {
		return $content;
	}
	if ( ! is_array( $post ) || ! skyphusion_ai_embed_included( $post, $config ) ) {
		return $content;
	}
	if ( false !== strpos( $content, '[skyphusion_ai_related]' ) ) {
		return $content;
	}
	return $content . skyphusion_ai_related_html( $post );
}

/**
 * Content filter. A page view uses the stored vector.
 *
 * @param string $content Page body.
 * @return string
 */
function skyphusion_ai_related_content( $content ) {
	if ( function_exists( 'is_admin' ) && is_admin() ) {
		return $content;
	}
	if ( function_exists( 'is_singular' ) && ! is_singular( array( 'post', 'product' ) ) ) {
		return $content;
	}
	$post_id = 0;
	if ( function_exists( 'get_the_ID' ) ) {
		$post_id = (int) get_the_ID();
	}
	$post = skyphusion_ai_embed_load( $post_id );
	if ( ! is_array( $post ) ) {
		return $content;
	}
	return skyphusion_ai_related_append( $content, $post );
}

/**
 * Shortcode. It works when the automatic switch is off.
 *
 * @return string
 */
function skyphusion_ai_related_shortcode() {
	$post_id = 0;
	if ( function_exists( 'get_the_ID' ) ) {
		$post_id = (int) get_the_ID();
	}
	$post = skyphusion_ai_embed_load( $post_id );
	if ( ! is_array( $post ) ) {
		return '';
	}
	return skyphusion_ai_related_html( $post );
}

/**
 * Register the shortcode and the content filter.
 *
 * @return void
 */
function skyphusion_ai_related_boot() {
	if ( function_exists( 'add_shortcode' ) ) {
		add_shortcode( 'skyphusion_ai_related', 'skyphusion_ai_related_shortcode' );
	}
	if ( function_exists( 'add_filter' ) ) {
		add_filter( 'the_content', 'skyphusion_ai_related_content' );
	}
}
add_action( 'init', 'skyphusion_ai_related_boot' );
