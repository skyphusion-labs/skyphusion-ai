<?php
/**
 * Keep a Cloudflare Vectorize index in step with published posts.
 *
 * The admin sees a neuron estimate before the first run. A later save
 * calls the model only when the chosen text changed. Deleting the plugin
 * removes the saved choice and leaves the index in the Cloudflare account.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name for the Vectorize choice.
 *
 * @return string
 */
function skyphusion_ai_embed_option_name() {
	return 'skyphusion_ai_vectorize';
}

/**
 * Post meta that remembers the text already stored.
 *
 * @return string
 */
function skyphusion_ai_embed_hash_key() {
	return 'skyphusion_ai_embed_hash';
}

/**
 * Saved choice, with defaults filled in.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_embed_config() {
	$stored = array();
	if ( function_exists( 'get_option' ) ) {
		$stored = get_option( skyphusion_ai_embed_option_name(), array() );
	}
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	$index = 'skyphusion-ai';
	if ( isset( $stored['index'] ) ) {
		$clean = skyphusion_ai_embed_index_name( $stored['index'] );
		if ( '' !== $clean ) {
			$index = $clean;
		}
	}
	$types = array( 'post', 'product' );
	if ( isset( $stored['post_types'] ) && is_array( $stored['post_types'] ) ) {
		$types = skyphusion_ai_embed_names( $stored['post_types'] );
	}
	$fields = array( 'title', 'content', 'excerpt' );
	if ( isset( $stored['fields'] ) && is_array( $stored['fields'] ) ) {
		$fields = skyphusion_ai_embed_field_list( $stored['fields'] );
	}
	$model = '';
	if ( isset( $stored['model'] ) && is_string( $stored['model'] ) ) {
		$model = $stored['model'];
	}
	$created = 0;
	if ( ! empty( $stored['created'] ) ) {
		$created = 1;
	}
	$enabled = 0;
	if ( array_key_exists( 'enabled', $stored ) ) {
		$enabled = empty( $stored['enabled'] ) ? 0 : 1;
	} elseif ( 1 === $created ) {
		$enabled = 1;
	}
	$related = 0;
	if ( ! empty( $stored['related'] ) ) {
		$related = 1;
	}
	$related_place = 'after';
	if ( isset( $stored['related_place'] ) && in_array( (string) $stored['related_place'], array( 'after', 'before', 'paragraph' ), true ) ) {
		$related_place = (string) $stored['related_place'];
	}
	$related_paragraph = 1;
	if ( isset( $stored['related_paragraph'] ) && is_numeric( $stored['related_paragraph'] ) ) {
		$related_paragraph = (int) $stored['related_paragraph'];
		if ( 1 > $related_paragraph ) {
			$related_paragraph = 1;
		}
		if ( 20 < $related_paragraph ) {
			$related_paragraph = 20;
		}
	}
	$related_why = 1;
	if ( array_key_exists( 'related_why', $stored ) ) {
		$related_why = empty( $stored['related_why'] ) ? 0 : 1;
	}
	$statuses = array( 'publish' );
	if ( isset( $stored['statuses'] ) && is_array( $stored['statuses'] ) ) {
		$statuses = array();
		foreach ( $stored['statuses'] as $status ) {
			$status = (string) $status;
			if ( 'publish' === $status || 'private' === $status ) {
				$statuses[] = $status;
			}
		}
		if ( array() === $statuses ) {
			$statuses = array( 'publish' );
		}
	}
	$after = '';
	if ( isset( $stored['after'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $stored['after'] ) ) {
		$after = (string) $stored['after'];
	}
	$tax_mode = 'none';
	if ( isset( $stored['tax_mode'] ) && in_array( (string) $stored['tax_mode'], array( 'include', 'exclude' ), true ) ) {
		$tax_mode = (string) $stored['tax_mode'];
	}
	$terms = array();
	if ( isset( $stored['terms'] ) && is_array( $stored['terms'] ) ) {
		$terms = skyphusion_ai_embed_names( $stored['terms'] );
	}
	$limit = 3000;
	if ( isset( $stored['limit'] ) && is_numeric( $stored['limit'] ) && (int) $stored['limit'] >= 0 ) {
		$limit = (int) $stored['limit'];
	}
	$day = '';
	if ( isset( $stored['day'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $stored['day'] ) ) {
		$day = (string) $stored['day'];
	}
	$neurons = isset( $stored['neurons'] ) ? max( 0, (int) $stored['neurons'] ) : 0;
	return array(
		'index'             => $index,
		'post_types'        => $types,
		'fields'            => $fields,
		'model'             => $model,
		'created'           => $created,
		'enabled'           => $enabled,
		'related'           => $related,
		'related_place'     => $related_place,
		'related_paragraph' => $related_paragraph,
		'related_why'       => $related_why,
		'statuses'          => $statuses,
		'images'            => empty( $stored['images'] ) ? 0 : 1,
		'after'             => $after,
		'tax_mode'          => $tax_mode,
		'terms'             => $terms,
		'limit'             => $limit,
		'day'               => $day,
		'neurons'           => $neurons,
	);
}

/**
 * Index name Cloudflare will accept, or an empty string.
 *
 * @param mixed $value Raw name.
 * @return string
 */
function skyphusion_ai_embed_index_name( $value ) {
	$value = strtolower( trim( (string) $value ) );
	if ( ! preg_match( '/^[a-z][a-z0-9-]{0,31}$/', $value ) ) {
		return '';
	}
	return $value;
}

/**
 * Keep short slugs. Drop anything else.
 *
 * @param array<int, mixed> $values Raw slugs.
 * @return array<int, string>
 */
function skyphusion_ai_embed_names( $values ) {
	$clean = array();
	foreach ( $values as $value ) {
		$name = strtolower( trim( (string) $value ) );
		if ( ! preg_match( '/^[a-z0-9_-]{1,20}$/', $name ) ) {
			continue;
		}
		if ( in_array( $name, $clean, true ) ) {
			continue;
		}
		$clean[] = $name;
	}
	return $clean;
}

/**
 * Title, content, and excerpt are the fields this index stores.
 *
 * @param array<int, mixed> $values Raw field names.
 * @return array<int, string>
 */
function skyphusion_ai_embed_field_list( $values ) {
	$allowed = array( 'title', 'content', 'excerpt' );
	$clean   = array();
	foreach ( $values as $value ) {
		$name = strtolower( trim( (string) $value ) );
		if ( ! in_array( $name, $allowed, true ) || in_array( $name, $clean, true ) ) {
			continue;
		}
		$clean[] = $name;
	}
	return $clean;
}

/**
 * Map a field name to the post array key.
 *
 * @param string $field Field name.
 * @return string
 */
function skyphusion_ai_embed_field_key( $field ) {
	if ( 'title' === $field ) {
		return 'post_title';
	}
	if ( 'content' === $field ) {
		return 'post_content';
	}
	if ( 'excerpt' === $field ) {
		return 'post_excerpt';
	}
	return '';
}

/**
 * Text that would be embedded, in field order.
 *
 * @param array<string, mixed> $post   One post.
 * @param array<int, string>   $fields Chosen fields.
 * @return string
 */
function skyphusion_ai_embed_text( $post, $fields ) {
	$parts = array();
	foreach ( $fields as $field ) {
		$key = skyphusion_ai_embed_field_key( (string) $field );
		if ( '' === $key || ! isset( $post[ $key ] ) || ! is_string( $post[ $key ] ) ) {
			continue;
		}
		$part = trim( $post[ $key ] );
		if ( '' !== $part ) {
			$parts[] = $part;
		}
	}
	return implode( "\n", $parts );
}

/**
 * Stable id for one post inside the index.
 *
 * @param int $post_id Post id.
 * @return string
 */
function skyphusion_ai_embed_vector_id( $post_id ) {
	return 'p' . (string) (int) $post_id;
}

/**
 * True when the category and tag filter keeps this post.
 *
 * Mode none keeps every post. Include keeps a match. Exclude drops a match.
 *
 * @param array<string, mixed> $post   One post.
 * @param array<string, mixed> $config Saved choices.
 * @return bool
 */
function skyphusion_ai_embed_terms_ok( $post, $config ) {
	$mode = isset( $config['tax_mode'] ) ? (string) $config['tax_mode'] : 'none';
	if ( 'include' !== $mode && 'exclude' !== $mode ) {
		return true;
	}
	$wanted = array();
	if ( isset( $config['terms'] ) && is_array( $config['terms'] ) ) {
		$wanted = $config['terms'];
	}
	$have = array();
	foreach ( array( 'categories', 'tags' ) as $bucket ) {
		if ( ! isset( $post[ $bucket ] ) || ! is_array( $post[ $bucket ] ) ) {
			continue;
		}
		foreach ( $post[ $bucket ] as $slug ) {
			$have[] = (string) $slug;
		}
	}
	$hit = false;
	foreach ( $wanted as $slug ) {
		if ( in_array( (string) $slug, $have, true ) ) {
			$hit = true;
			break;
		}
	}
	if ( 'include' === $mode ) {
		return $hit;
	}
	return ! $hit;
}

/**
 * True when this post belongs in the index.
 *
 * A password-protected post stays out. A draft, a revision, and a
 * type the admin did not choose stay out. Private stays out until
 * the admin includes it.
 *
 * @param array<string, mixed> $post   One post.
 * @param array<string, mixed> $config Saved choices.
 * @return bool
 */
function skyphusion_ai_embed_included( $post, $config ) {
	$password = isset( $post['post_password'] ) ? (string) $post['post_password'] : '';
	if ( '' !== $password ) {
		return false;
	}
	$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
	if ( function_exists( 'get_post_meta' ) && '1' === (string) get_post_meta( $post_id, 'skyphusion_ai_embed_exclude', true ) ) {
		return false;
	}
	$status   = isset( $post['post_status'] ) ? (string) $post['post_status'] : '';
	$statuses = array( 'publish' );
	if ( isset( $config['statuses'] ) && is_array( $config['statuses'] ) ) {
		$statuses = $config['statuses'];
	}
	$type = isset( $post['post_type'] ) ? (string) $post['post_type'] : '';
	if ( 'attachment' === $type ) {
		if ( empty( $config['images'] ) ) {
			return false;
		}
		if ( 'inherit' !== $status && 'publish' !== $status ) {
			return false;
		}
	} else {
		if ( ! in_array( $status, $statuses, true ) ) {
			return false;
		}
		if ( '' === $type || 'revision' === $type ) {
			return false;
		}
		$types = array();
		if ( isset( $config['post_types'] ) && is_array( $config['post_types'] ) ) {
			$types = $config['post_types'];
		}
		if ( ! in_array( $type, $types, true ) ) {
			return false;
		}
	}
	$after = isset( $config['after'] ) ? (string) $config['after'] : '';
	if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) ) {
		$dated = isset( $post['post_date_gmt'] ) ? (string) $post['post_date_gmt'] : '';
		if ( '' !== $dated && substr( $dated, 0, 10 ) < $after ) {
			return false;
		}
	}
	return skyphusion_ai_embed_terms_ok( $post, $config );
}

/**
 * Models whose Vectorize preset sets the dimensions.
 *
 * @return array<int, string>
 */
function skyphusion_ai_embed_presets() {
	return array(
		'@cf/baai/bge-small-en-v1.5',
		'@cf/baai/bge-base-en-v1.5',
		'@cf/baai/bge-large-en-v1.5',
	);
}

/**
 * Create-index body, or null when the model has no preset.
 *
 * @param string $name  Index name.
 * @param string $model Embeddings model id.
 * @return array<string, mixed>|null
 */
function skyphusion_ai_embed_create_body( $name, $model ) {
	if ( '' === skyphusion_ai_embed_index_name( $name ) ) {
		return null;
	}
	if ( ! in_array( $model, skyphusion_ai_embed_presets(), true ) ) {
		return null;
	}
	return array(
		'name'   => $name,
		'config' => array(
			'preset' => $model,
		),
	);
}

/**
 * Neuron estimate for the posts that would call the model.
 *
 * The character count is divided by four. That is an estimate.
 * This function does not call the model.
 *
 * @param array<int, mixed>    $posts  Posts to consider.
 * @param array<string, mixed> $config Saved choices.
 * @return array{posts: int, tokens: int, neurons: int, passages: int, days: int, stored: int}
 */
function skyphusion_ai_embed_estimate( $posts, $config ) {
	$chars  = 0;
	$count  = 0;
	$fields = array();
	if ( isset( $config['fields'] ) && is_array( $config['fields'] ) ) {
		$fields = $config['fields'];
	}
	foreach ( $posts as $post ) {
		if ( ! is_array( $post ) || ! skyphusion_ai_embed_included( $post, $config ) ) {
			continue;
		}
		$text = skyphusion_ai_embed_text( $post, $fields );
		if ( '' === $text ) {
			continue;
		}
		$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		if ( skyphusion_ai_embed_hash_get( $post_id ) === hash( 'sha256', $text ) ) {
			continue;
		}
		++$count;
		$chars += strlen( $text );
	}
	$tokens = intdiv( $chars, 4 );
	$model  = '@cf/baai/bge-large-en-v1.5';
	if ( function_exists( 'cloudflare_ai_provider_embeddings_model' ) ) {
		$model = cloudflare_ai_provider_embeddings_model();
	}
	$rate    = skyphusion_ai_neuron_rate( $model );
	$neurons = skyphusion_ai_neuron_from_tokens( $tokens, (int) $rate[0] );
	$limit   = isset( $config['limit'] ) ? (int) $config['limit'] : 3000;
	$days    = 1;
	if ( $limit >= 1 && $neurons > $limit ) {
		$days = (int) ceil( $neurons / $limit );
	}
	return array(
		'posts'    => $count,
		'tokens'   => $tokens,
		'neurons'  => $neurons,
		'passages' => $count,
		'days'     => $days,
		'stored'   => $count * 1024,
	);
}

/**
 * Hash already stored for one post.
 *
 * @param int $post_id Post id.
 * @return string
 */
function skyphusion_ai_embed_hash_get( $post_id ) {
	if ( ! function_exists( 'get_post_meta' ) ) {
		return '';
	}
	$value = get_post_meta( (int) $post_id, skyphusion_ai_embed_hash_key(), true );
	if ( ! is_string( $value ) ) {
		return '';
	}
	return $value;
}

/**
 * Remember or forget the hash for one post.
 *
 * @param int    $post_id Post id.
 * @param string $hash    Sha256, or an empty string to forget it.
 * @return void
 */
function skyphusion_ai_embed_hash_set( $post_id, $hash ) {
	if ( '' === $hash ) {
		if ( function_exists( 'delete_post_meta' ) ) {
			delete_post_meta( (int) $post_id, skyphusion_ai_embed_hash_key() );
		}
		return;
	}
	if ( function_exists( 'update_post_meta' ) ) {
		update_post_meta( (int) $post_id, skyphusion_ai_embed_hash_key(), $hash );
	}
}

/**
 * Post meta for an embedding that did not store.
 *
 * @return string
 */
function skyphusion_ai_embed_fail_key() {
	return 'skyphusion_ai_embed_failed';
}

/**
 * Remember or forget a failed store.
 *
 * @param int  $post_id Post id.
 * @param bool $failed  True when the last store did not finish.
 * @return void
 */
function skyphusion_ai_embed_fail_set( $post_id, $failed ) {
	if ( $failed ) {
		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( (int) $post_id, skyphusion_ai_embed_fail_key(), '1' );
		}
		return;
	}
	if ( function_exists( 'delete_post_meta' ) ) {
		delete_post_meta( (int) $post_id, skyphusion_ai_embed_fail_key() );
	}
}

/**
 * True when the last store for this post did not finish.
 *
 * @param int $post_id Post id.
 * @return bool
 */
function skyphusion_ai_embed_failed( $post_id ) {
	if ( ! function_exists( 'get_post_meta' ) ) {
		return false;
	}
	return '1' === (string) get_post_meta( (int) $post_id, skyphusion_ai_embed_fail_key(), true );
}

/**
 * True when embeddings are on.
 *
 * A missing switch follows the index. An explicit zero stays off.
 *
 * @param array<string, mixed> $config Saved choices.
 * @return bool
 */
function skyphusion_ai_embed_on( $config ) {
	return ! empty( $config['enabled'] );
}

/**
 * Account id and token from the same places as Workers AI.
 *
 * @return array{account_id: string, token: string}
 */
function skyphusion_ai_embed_credentials() {
	$settings = array();
	if ( function_exists( 'skyphusion_ai_settings' ) ) {
		$settings = skyphusion_ai_settings();
	}
	$account = '';
	if ( isset( $settings['account_id'] ) ) {
		$account = (string) $settings['account_id'];
	}
	$key = '';
	if ( function_exists( 'cloudflare_ai_provider_connector_key' ) ) {
		$key = cloudflare_ai_provider_connector_key();
	}
	if ( ! function_exists( 'cloudflare_ai_provider_resolve_credentials' ) ) {
		return array(
			'account_id' => '',
			'token'      => '',
		);
	}
	return cloudflare_ai_provider_resolve_credentials( $key, $account );
}

/**
 * Vectorize URL on the account API. The gateway host is not used.
 *
 * @param string $account Account id.
 * @param string $index   Index name. Empty for the collection URL.
 * @param string $suffix  Path after the index name.
 * @return string
 */
function skyphusion_ai_vectorize_url( $account, $index, $suffix ) {
	$account = cloudflare_ai_provider_account_id( $account );
	if ( '' === $account ) {
		return '';
	}
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Vectorize API host, not a remote asset.
	$url   = 'https://api.cloudflare.com/client/v4/accounts/' . $account . '/vectorize/v2/indexes';
	$index = skyphusion_ai_embed_index_name( $index );
	if ( '' === $index ) {
		return $url;
	}
	$url   .= '/' . rawurlencode( $index );
	$suffix = trim( (string) $suffix, '/' );
	if ( '' !== $suffix ) {
		$url .= '/' . $suffix;
	}
	return $url;
}

/**
 * One Vectorize call. The token stays in the header.
 *
 * @param string $method       HTTP method.
 * @param string $url          Absolute URL.
 * @param string $token        API token.
 * @param string $body         Request body.
 * @param string $content_type Content type.
 * @return array<string, mixed>|WP_Error
 */
function skyphusion_ai_vectorize_request( $method, $url, $token, $body, $content_type ) {
	if ( '' === $url || '' === $token ) {
		return new WP_Error( 'skyphusion_ai_embed', 'Add the Cloudflare account ID and API token before embedding text.' );
	}
	$args = array(
		'method'  => $method,
		'timeout' => 20,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => $content_type,
		),
		'body'    => $body,
	);
	if ( ! function_exists( 'wp_remote_request' ) ) {
		return new WP_Error( 'skyphusion_ai_embed', 'The site could not reach Cloudflare.' );
	}
	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'skyphusion_ai_embed', 'The site could not reach Cloudflare.' );
	}
	$status  = (int) wp_remote_retrieve_response_code( $response );
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
		return new WP_Error( 'skyphusion_ai_embed', 'Vectorize did not accept the change.' );
	}
	return $decoded;
}

/**
 * Create the index once, when the model has a preset.
 *
 * @param array<string, mixed> $config Saved choices.
 * @return array{state: string}
 */
function skyphusion_ai_embed_ensure( $config ) {
	$model = '@cf/baai/bge-large-en-v1.5';
	if ( function_exists( 'cloudflare_ai_provider_embeddings_model' ) ) {
		$model = cloudflare_ai_provider_embeddings_model();
	}
	if ( ! empty( $config['created'] ) ) {
		if ( isset( $config['model'] ) && $config['model'] === $model ) {
			return array( 'state' => 'ready' );
		}
		return array( 'state' => 'blocked' );
	}
	$body = skyphusion_ai_embed_create_body( (string) $config['index'], $model );
	if ( null === $body ) {
		return array( 'state' => 'blocked' );
	}
	$credentials = skyphusion_ai_embed_credentials();
	$url         = skyphusion_ai_vectorize_url( $credentials['account_id'], '', '' );
	$result      = skyphusion_ai_vectorize_request(
		'POST',
		$url,
		$credentials['token'],
		(string) wp_json_encode( $body ),
		'application/json'
	);
	if ( is_wp_error( $result ) ) {
		return array( 'state' => 'blocked' );
	}
	$config['created'] = 1;
	$config['enabled'] = 1;
	$config['model']   = $model;
	update_option( skyphusion_ai_embed_option_name(), $config, false );
	return array( 'state' => 'ready' );
}

/**
 * Public http address, or an empty string.
 *
 * @param string $url Raw address.
 * @return string
 */
function skyphusion_ai_embed_public_url( $url ) {
	$url = trim( $url );
	if ( '' === $url || preg_match( '/\s/', $url ) ) {
		return '';
	}
	if ( function_exists( 'wp_parse_url' ) ) {
		$parts = wp_parse_url( $url );
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$parts = parse_url( $url );
	}
	if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return '';
	}
	if ( ! isset( $parts['scheme'] ) || ! isset( $parts['host'] ) ) {
		return '';
	}
	$scheme = strtolower( (string) $parts['scheme'] );
	if ( 'https' !== $scheme && 'http' !== $scheme ) {
		return '';
	}
	if ( strlen( $url ) > 200 ) {
		return '';
	}
	return $url;
}

/**
 * Metadata stored beside the vector. The post body is not included.
 *
 * @param array<string, mixed> $post One post.
 * @return array<string, string>
 */
function skyphusion_ai_embed_metadata( $post ) {
	$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
	$title   = '';
	if ( isset( $post['post_title'] ) && is_string( $post['post_title'] ) ) {
		$title = trim( $post['post_title'] );
	}
	if ( strlen( $title ) > 200 ) {
		$title = substr( $title, 0, 200 );
	}
	$type = isset( $post['post_type'] ) ? (string) $post['post_type'] : '';
	$url  = '';
	if ( function_exists( 'get_permalink' ) ) {
		$url = skyphusion_ai_embed_public_url( (string) get_permalink( $post_id ) );
	}
	$metadata = array(
		'post_id'   => (string) $post_id,
		'post_type' => $type,
		'title'     => $title,
	);
	if ( '' !== $url ) {
		$metadata['url'] = $url;
	}
	$term_line = skyphusion_ai_embed_term_line( $post );
	if ( '' !== $term_line ) {
		$metadata['terms'] = $term_line;
	}
	return $metadata;
}

/**
 * Categories and tags stored beside the vector.
 *
 * The line is empty when the post has none. A page view does not call this.
 *
 * @param array<string, mixed> $post One post.
 * @return string
 */
function skyphusion_ai_embed_term_line( $post ) {
	$names = array();
	foreach ( array( 'categories', 'tags' ) as $bucket ) {
		if ( ! isset( $post[ $bucket ] ) || ! is_array( $post[ $bucket ] ) ) {
			continue;
		}
		foreach ( $post[ $bucket ] as $name ) {
			$name = trim( (string) $name );
			if ( '' === $name || 40 < strlen( $name ) || in_array( $name, $names, true ) ) {
				continue;
			}
			$names[] = $name;
			if ( 8 <= count( $names ) ) {
				return implode( ', ', $names );
			}
		}
	}
	if ( array() !== $names || ! function_exists( 'wp_get_post_terms' ) ) {
		return implode( ', ', $names );
	}
	$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
	$found   = wp_get_post_terms( $post_id, array( 'category', 'post_tag' ), array( 'fields' => 'names' ) );
	if ( ! is_array( $found ) ) {
		return '';
	}
	foreach ( $found as $name ) {
		$name = trim( (string) $name );
		if ( '' === $name || 40 < strlen( $name ) || in_array( $name, $names, true ) ) {
			continue;
		}
		$names[] = $name;
		if ( 8 <= count( $names ) ) {
			break;
		}
	}
	return implode( ', ', $names );
}

/**
 * Remove one vector and forget its hash.
 *
 * @param int                   $post_id     Post id.
 * @param array<string, mixed>  $config      Saved choices.
 * @param array<string, string> $credentials Account id and token.
 * @return array{state: string}
 */
function skyphusion_ai_embed_remove( $post_id, $config, $credentials ) {
	$url    = skyphusion_ai_vectorize_url( $credentials['account_id'], (string) $config['index'], 'delete_by_ids' );
	$body   = wp_json_encode( array( 'ids' => array( skyphusion_ai_embed_vector_id( $post_id ) ) ) );
	$result = skyphusion_ai_vectorize_request( 'POST', $url, $credentials['token'], (string) $body, 'application/json' );
	if ( is_wp_error( $result ) ) {
		return array( 'state' => 'blocked' );
	}
	skyphusion_ai_embed_hash_set( $post_id, '' );
	return array( 'state' => 'removed' );
}

/**
 * Store or remove one post. An unchanged post does not call the model.
 *
 * @param array<string, mixed> $post One post.
 * @return array{state: string}
 */
function skyphusion_ai_embed_sync_post( $post ) {
	$config = skyphusion_ai_embed_config();
	if ( empty( $config['created'] ) ) {
		return array( 'state' => 'not_created' );
	}
	$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
	$fields  = array();
	if ( isset( $config['fields'] ) && is_array( $config['fields'] ) ) {
		$fields = $config['fields'];
	}
	$text     = skyphusion_ai_embed_text( $post, $fields );
	$included = skyphusion_ai_embed_included( $post, $config ) && '' !== $text;
	$hash     = '' === $text ? '' : hash( 'sha256', $text );
	$stored   = skyphusion_ai_embed_hash_get( $post_id );
	if ( $included && $hash === $stored ) {
		skyphusion_ai_embed_fail_set( $post_id, false );
		return array( 'state' => 'unchanged' );
	}
	$model = '@cf/baai/bge-large-en-v1.5';
	if ( function_exists( 'cloudflare_ai_provider_embeddings_model' ) ) {
		$model = cloudflare_ai_provider_embeddings_model();
	}
	if ( ! isset( $config['model'] ) || $config['model'] !== $model ) {
		return array( 'state' => 'blocked' );
	}
	$credentials = skyphusion_ai_embed_credentials();
	if ( '' === $credentials['account_id'] || '' === $credentials['token'] ) {
		return array( 'state' => 'blocked' );
	}
	if ( ! $included ) {
		if ( '' === $stored ) {
			skyphusion_ai_embed_fail_set( $post_id, false );
			return array( 'state' => 'removed' );
		}
		$removed = skyphusion_ai_embed_remove( $post_id, $config, $credentials );
		if ( 'removed' === $removed['state'] ) {
			skyphusion_ai_embed_fail_set( $post_id, false );
		}
		return $removed;
	}
	if ( ! function_exists( 'cloudflare_ai_provider_embeddings' ) ) {
		return array( 'state' => 'blocked' );
	}
	if ( skyphusion_ai_embed_limit_blocked( skyphusion_ai_embed_config() ) ) {
		return array( 'state' => 'limit' );
	}
	$before = 0;
	if ( function_exists( 'skyphusion_ai_neuron_state' ) ) {
		$before = (int) skyphusion_ai_neuron_state()['neurons'];
	}
	$vectors = cloudflare_ai_provider_embeddings( array( $text ) );
	if ( is_wp_error( $vectors ) || ! is_array( $vectors ) || ! isset( $vectors[0] ) || ! is_array( $vectors[0] ) ) {
		skyphusion_ai_embed_fail_set( $post_id, true );
		return array( 'state' => 'blocked' );
	}
	$line = wp_json_encode(
		array(
			'id'       => skyphusion_ai_embed_vector_id( $post_id ),
			'values'   => $vectors[0],
			'metadata' => skyphusion_ai_embed_metadata( $post ),
		)
	);
	$url  = skyphusion_ai_vectorize_url( $credentials['account_id'], (string) $config['index'], 'upsert' );
	$save = skyphusion_ai_vectorize_request( 'POST', $url, $credentials['token'], (string) $line . "\n", 'application/x-ndjson' );
	if ( is_wp_error( $save ) ) {
		skyphusion_ai_embed_fail_set( $post_id, true );
		return array( 'state' => 'blocked' );
	}
	skyphusion_ai_embed_hash_set( $post_id, $hash );
	skyphusion_ai_embed_fail_set( $post_id, false );
	$after = $before;
	if ( function_exists( 'skyphusion_ai_neuron_state' ) ) {
		$after = (int) skyphusion_ai_neuron_state()['neurons'];
	}
	skyphusion_ai_embed_add_neurons( $after - $before );
	return array( 'state' => 'embedded' );
}

/**
 * True when today's embeddings limit is spent.
 *
 * Zero means this job has no limit of its own.
 *
 * @param array<string, mixed> $config Saved choices.
 * @return bool
 */
function skyphusion_ai_embed_limit_blocked( $config ) {
	$limit = isset( $config['limit'] ) ? (int) $config['limit'] : 3000;
	if ( $limit < 1 ) {
		return false;
	}
	$today = function_exists( 'skyphusion_ai_neuron_today' ) ? skyphusion_ai_neuron_today() : gmdate( 'Y-m-d' );
	$used  = 0;
	if ( isset( $config['day'] ) && $config['day'] === $today ) {
		$used = (int) $config['neurons'];
	}
	return $used >= $limit;
}

/**
 * Add measured neurons to today's embeddings total.
 *
 * @param int $neurons Neurons from the call.
 * @return void
 */
function skyphusion_ai_embed_add_neurons( $neurons ) {
	if ( ! function_exists( 'update_option' ) ) {
		return;
	}
	$neurons = (int) $neurons;
	if ( $neurons < 0 ) {
		$neurons = 0;
	}
	$config = skyphusion_ai_embed_config();
	$today  = function_exists( 'skyphusion_ai_neuron_today' ) ? skyphusion_ai_neuron_today() : gmdate( 'Y-m-d' );
	$used   = 0;
	if ( isset( $config['day'] ) && $config['day'] === $today ) {
		$used = (int) $config['neurons'];
	}
	$config['day']     = $today;
	$config['neurons'] = $used + $neurons;
	update_option( skyphusion_ai_embed_option_name(), $config, false );
}

/**
 * Query for the posts a run would consider.
 *
 * Images are included only when the admin turned that choice on.
 * Private posts are included only when that choice is saved.
 *
 * @param array<string, mixed> $config Saved choices.
 * @return array<string, mixed>
 */
function skyphusion_ai_embed_query_args( $config ) {
	$types = array();
	if ( isset( $config['post_types'] ) && is_array( $config['post_types'] ) ) {
		$types = $config['post_types'];
	}
	if ( ! empty( $config['images'] ) && ! in_array( 'attachment', $types, true ) ) {
		$types[] = 'attachment';
	}
	$statuses = array( 'publish' );
	if ( isset( $config['statuses'] ) && is_array( $config['statuses'] ) && array() !== $config['statuses'] ) {
		$statuses = $config['statuses'];
	}
	if ( ! empty( $config['images'] ) && ! in_array( 'inherit', $statuses, true ) ) {
		$statuses[] = 'inherit';
	}
	return array(
		'post_type'   => $types,
		'post_status' => $statuses,
		'numberposts' => -1,
	);
}

/**
 * Posts the estimate and the run walk.
 *
 * A test may set skyphusion_ai_embed_posts. Otherwise the chosen types
 * and statuses are loaded.
 *
 * @return array<int, mixed>
 */
function skyphusion_ai_embed_collect() {
	if ( isset( $GLOBALS['skyphusion_ai_embed_posts'] ) && is_array( $GLOBALS['skyphusion_ai_embed_posts'] ) ) {
		return $GLOBALS['skyphusion_ai_embed_posts'];
	}
	if ( ! function_exists( 'get_posts' ) ) {
		return array();
	}
	$config = skyphusion_ai_embed_config();
	$args   = skyphusion_ai_embed_query_args( $config );
	if ( array() === $args['post_type'] ) {
		return array();
	}
	$posts = get_posts( $args );
	if ( ! is_array( $posts ) ) {
		return array();
	}
	$rows = array();
	foreach ( $posts as $post ) {
		if ( is_object( $post ) ) {
			$post = get_object_vars( $post );
		}
		if ( is_array( $post ) ) {
			$rows[] = $post;
		}
	}
	return $rows;
}

/**
 * Estimate, or create the index and embed when the admin confirmed.
 *
 * @param array<int, mixed> $posts     Posts to consider.
 * @param bool              $confirmed True only after the admin asked to run.
 * @return array<string, mixed>
 */
function skyphusion_ai_embed_run( $posts, $confirmed ) {
	$config   = skyphusion_ai_embed_config();
	$estimate = skyphusion_ai_embed_estimate( $posts, $config );
	if ( ! $confirmed ) {
		return array(
			'state'    => 'estimate',
			'estimate' => $estimate,
		);
	}
	$ready = skyphusion_ai_embed_ensure( $config );
	if ( 'ready' !== $ready['state'] ) {
		return array(
			'state'    => 'blocked',
			'estimate' => $estimate,
		);
	}
	$done = 0;
	foreach ( $posts as $post ) {
		if ( ! is_array( $post ) ) {
			continue;
		}
		$result = skyphusion_ai_embed_sync_post( $post );
		if ( 'embedded' === $result['state'] ) {
			++$done;
		}
	}
	return array(
		'state'    => 'ran',
		'embedded' => $done,
		'estimate' => $estimate,
	);
}

/**
 * Delete the index only when the admin asked.
 *
 * @param bool $confirmed True only after the admin asked.
 * @return array{state: string}
 */
function skyphusion_ai_embed_delete_index( $confirmed ) {
	if ( ! $confirmed ) {
		return array( 'state' => 'blocked' );
	}
	$config      = skyphusion_ai_embed_config();
	$credentials = skyphusion_ai_embed_credentials();
	$url         = skyphusion_ai_vectorize_url( $credentials['account_id'], (string) $config['index'], '' );
	$result      = skyphusion_ai_vectorize_request( 'DELETE', $url, $credentials['token'], '', 'application/json' );
	if ( is_wp_error( $result ) ) {
		return array( 'state' => 'blocked' );
	}
	$config['created'] = 0;
	$config['enabled'] = 0;
	$config['model']   = '';
	update_option( skyphusion_ai_embed_option_name(), $config, false );
	return array( 'state' => 'deleted' );
}

/**
 * Search the index. The query is not stored.
 *
 * @param string $query Question text.
 * @param int    $limit How many matches to ask for.
 * @return array<int, array<string, mixed>>|array{state: string}
 */
function skyphusion_ai_embed_search( $query, $limit ) {
	$config = skyphusion_ai_embed_config();
	if ( empty( $config['created'] ) ) {
		return array( 'state' => 'not_created' );
	}
	$query = trim( $query );
	if ( '' === $query || ! function_exists( 'cloudflare_ai_provider_embeddings' ) ) {
		return array( 'state' => 'blocked' );
	}
	$vectors = cloudflare_ai_provider_embeddings( array( $query ) );
	if ( is_wp_error( $vectors ) || ! isset( $vectors[0] ) || ! is_array( $vectors[0] ) ) {
		return array( 'state' => 'blocked' );
	}
	$limit = (int) $limit;
	if ( $limit < 1 ) {
		$limit = 5;
	}
	if ( $limit > 20 ) {
		$limit = 20;
	}
	$credentials = skyphusion_ai_embed_credentials();
	$url         = skyphusion_ai_vectorize_url( $credentials['account_id'], (string) $config['index'], 'query' );
	$body        = wp_json_encode(
		array(
			'vector'         => $vectors[0],
			'topK'           => $limit,
			'returnMetadata' => 'all',
		)
	);
	$decoded     = skyphusion_ai_vectorize_request( 'POST', $url, $credentials['token'], (string) $body, 'application/json' );
	if ( is_wp_error( $decoded ) ) {
		return array( 'state' => 'blocked' );
	}
	$rows = array();
	if ( ! isset( $decoded['result']['matches'] ) || ! is_array( $decoded['result']['matches'] ) ) {
		return $rows;
	}
	foreach ( $decoded['result']['matches'] as $match ) {
		if ( ! is_array( $match ) ) {
			continue;
		}
		$metadata = array();
		if ( isset( $match['metadata'] ) && is_array( $match['metadata'] ) ) {
			$metadata = $match['metadata'];
		}
		$title     = isset( $metadata['title'] ) ? (string) $metadata['title'] : '';
		$url_value = isset( $metadata['url'] ) ? skyphusion_ai_embed_public_url( (string) $metadata['url'] ) : '';
		$rows[]    = array(
			'id'      => isset( $match['id'] ) ? (string) $match['id'] : '',
			'score'   => isset( $match['score'] ) ? (float) $match['score'] : 0,
			'post_id' => isset( $metadata['post_id'] ) ? (int) $metadata['post_id'] : 0,
			'title'   => $title,
			'url'     => $url_value,
		);
	}
	return $rows;
}

/**
 * Save the admin's choice. This does not call the model.
 *
 * An index that already exists keeps its name.
 *
 * @param array<string, mixed> $posted Form fields.
 * @return array<string, mixed>
 */
function skyphusion_ai_embed_apply( $posted ) {
	$config = skyphusion_ai_embed_config();
	$index  = '';
	if ( isset( $posted['index'] ) ) {
		$index = skyphusion_ai_embed_index_name( $posted['index'] );
	}
	if ( '' !== $index && ( empty( $config['created'] ) || $index === $config['index'] ) ) {
		$config['index'] = $index;
	}
	if ( isset( $posted['post_types'] ) && is_array( $posted['post_types'] ) ) {
		$config['post_types'] = skyphusion_ai_embed_names( $posted['post_types'] );
	}
	if ( isset( $posted['fields'] ) && is_array( $posted['fields'] ) ) {
		$config['fields'] = skyphusion_ai_embed_field_list( $posted['fields'] );
	}
	if ( isset( $posted['related'] ) ) {
		$config['related'] = empty( $posted['related'] ) ? 0 : 1;
	}
	if ( isset( $posted['related_place'] ) ) {
		$place                   = (string) $posted['related_place'];
		$config['related_place'] = in_array( $place, array( 'after', 'before', 'paragraph' ), true ) ? $place : 'after';
	}
	if ( isset( $posted['related_paragraph'] ) && is_numeric( $posted['related_paragraph'] ) ) {
		$paragraph = (int) $posted['related_paragraph'];
		if ( 1 > $paragraph ) {
			$paragraph = 1;
		}
		if ( 20 < $paragraph ) {
			$paragraph = 20;
		}
		$config['related_paragraph'] = $paragraph;
	}
	if ( isset( $posted['related_why'] ) ) {
		$config['related_why'] = empty( $posted['related_why'] ) ? 0 : 1;
	}
	if ( isset( $posted['private'] ) ) {
		$statuses = array( 'publish' );
		if ( ! empty( $posted['private'] ) ) {
			$statuses[] = 'private';
		}
		$config['statuses'] = $statuses;
	}
	if ( isset( $posted['images'] ) ) {
		$config['images'] = empty( $posted['images'] ) ? 0 : 1;
	}
	if ( isset( $posted['after'] ) ) {
		$after           = (string) $posted['after'];
		$config['after'] = ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) ) ? $after : '';
	}
	if ( isset( $posted['tax_mode'] ) ) {
		$tax                = (string) $posted['tax_mode'];
		$config['tax_mode'] = in_array( $tax, array( 'include', 'exclude' ), true ) ? $tax : 'none';
	}
	if ( isset( $posted['terms'] ) && is_array( $posted['terms'] ) ) {
		$config['terms'] = skyphusion_ai_embed_names( $posted['terms'] );
	}
	if ( isset( $posted['limit'] ) && is_numeric( $posted['limit'] ) && (int) $posted['limit'] >= 0 ) {
		$config['limit'] = (int) $posted['limit'];
	}
	update_option( skyphusion_ai_embed_option_name(), $config, false );
	return skyphusion_ai_embed_config();
}

/**
 * Load one post as an array.
 *
 * @param int $post_id Post id.
 * @return array<string, mixed>|null
 */
function skyphusion_ai_embed_load( $post_id ) {
	if ( ! function_exists( 'get_post' ) ) {
		return null;
	}
	$post = get_post( (int) $post_id );
	if ( is_object( $post ) ) {
		$post = get_object_vars( $post );
	}
	if ( ! is_array( $post ) ) {
		return null;
	}
	return $post;
}

/**
 * Save hook. Autosave and revisions do not call the model.
 *
 * @param int $post_id Post id.
 * @return void
 */
function skyphusion_ai_embed_on_save( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
		return;
	}
	$post = skyphusion_ai_embed_load( $post_id );
	if ( ! is_array( $post ) ) {
		return;
	}
	skyphusion_ai_embed_sync_post( $post );
}

/**
 * Delete hook. The vector is removed when the index exists.
 *
 * @param int $post_id Post id.
 * @return void
 */
function skyphusion_ai_embed_on_delete( $post_id ) {
	$post = skyphusion_ai_embed_load( $post_id );
	if ( ! is_array( $post ) ) {
		$post = array(
			'ID'            => (int) $post_id,
			'post_status'   => 'trash',
			'post_type'     => 'post',
			'post_password' => '',
			'post_title'    => '',
			'post_content'  => '',
			'post_excerpt'  => '',
		);
	}
	$post['post_status'] = 'trash';
	skyphusion_ai_embed_sync_post( $post );
}

/**
 * Stop when the current user cannot manage the site.
 *
 * @return bool
 */
function skyphusion_ai_embed_admin_allowed() {
	if ( ! function_exists( 'current_user_can' ) ) {
		return false;
	}
	return current_user_can( 'manage_options' );
}

/**
 * Print the estimate and the run control.
 *
 * @return void
 */
function skyphusion_ai_embed_render() {
	if ( ! function_exists( 'esc_html' ) ) {
		return;
	}
	$config   = skyphusion_ai_embed_config();
	$estimate = skyphusion_ai_embed_estimate( skyphusion_ai_embed_collect(), $config );
	echo '<hr />';
	echo '<h2>' . esc_html__( 'Embeddings', 'skyphusion-ai' ) . '</h2>';
	/* translators: 1: number of posts, 2: estimated neurons, 3: passages, 4: days, 5: stored dimensions. */
	$sentence = __( 'This run would embed %1$d posts and %3$d passages. The estimate is %2$d neurons, about %4$d days, and %5$d stored dimensions. The model has not been called.', 'skyphusion-ai' );
	echo '<p>' . esc_html(
		sprintf(
			$sentence,
			(int) $estimate['posts'],
			(int) $estimate['neurons'],
			(int) $estimate['passages'],
			(int) $estimate['days'],
			(int) $estimate['stored']
		)
	) . '</p>';
	echo '<p>' . esc_html__( 'Password-protected posts stay out. The embeddings limit does not stop the other tools.', 'skyphusion-ai' ) . '</p>';
	if ( ! empty( $config['created'] ) ) {
		echo '<p>' . esc_html__( 'The index exists. A saved post is sent again only when its text changes.', 'skyphusion-ai' ) . '</p>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="skyphusion_ai_embed_save" />';
	if ( function_exists( 'wp_nonce_field' ) ) {
		wp_nonce_field( 'skyphusion_ai_embed_save' );
	}
	echo '<p><label>' . esc_html__( 'Index name', 'skyphusion-ai' ) . ' ';
	echo '<input type="text" name="skyphusion_ai_embed_index" value="' . esc_attr( (string) $config['index'] ) . '" /></label></p>';
	echo '<p><label><input type="checkbox" name="skyphusion_ai_related" value="1"';
	if ( ! empty( $config['related'] ) ) {
		echo ' checked="checked"';
	}
	echo ' /> ' . esc_html__( 'Show related posts on a single post or product.', 'skyphusion-ai' ) . '</label></p>';
	echo '<p><label>' . esc_html__( 'Where the list goes.', 'skyphusion-ai' ) . ' ';
	echo '<select name="skyphusion_ai_related_place">';
	$related_places = array(
		'after'     => __( 'After the content.', 'skyphusion-ai' ),
		'before'    => __( 'Before the content.', 'skyphusion-ai' ),
		'paragraph' => __( 'After a paragraph.', 'skyphusion-ai' ),
	);
	foreach ( $related_places as $related_mode => $related_label ) {
		echo '<option value="' . esc_attr( $related_mode ) . '"';
		if ( (string) $config['related_place'] === $related_mode ) {
			echo ' selected="selected"';
		}
		echo '>' . esc_html( $related_label ) . '</option>';
	}
	echo '</select></label></p>';
	echo '<p><label>' . esc_html__( 'Paragraph number.', 'skyphusion-ai' ) . ' ';
	echo '<input type="number" name="skyphusion_ai_related_paragraph" value="' . esc_attr( (string) $config['related_paragraph'] ) . '" min="1" max="20" step="1" /></label></p>';
	echo '<p><label><input type="checkbox" name="skyphusion_ai_related_why" value="1"';
	if ( ! empty( $config['related_why'] ) ) {
		echo ' checked="checked"';
	}
	echo ' /> ' . esc_html__( 'Say why each page matches.', 'skyphusion-ai' ) . '</label></p>';
	echo '<p><label><input type="checkbox" name="skyphusion_ai_embed_private" value="1"';
	if ( in_array( 'private', $config['statuses'], true ) ) {
		echo ' checked="checked"';
	}
	echo ' /> ' . esc_html__( 'Include private posts.', 'skyphusion-ai' ) . '</label></p>';
	echo '<p><label><input type="checkbox" name="skyphusion_ai_embed_images" value="1"';
	if ( ! empty( $config['images'] ) ) {
		echo ' checked="checked"';
	}
	echo ' /> ' . esc_html__( 'Include images.', 'skyphusion-ai' ) . '</label></p>';
	echo '<p><label>' . esc_html__( 'Only posts on or after this date.', 'skyphusion-ai' ) . ' ';
	echo '<input type="text" name="skyphusion_ai_embed_after" value="' . esc_attr( (string) $config['after'] ) . '" /></label></p>';
	echo '<p><label>' . esc_html__( 'Categories and tags.', 'skyphusion-ai' ) . ' ';
	echo '<select name="skyphusion_ai_embed_tax">';
	$tax_labels = array(
		'none'    => __( 'Keep every category and tag.', 'skyphusion-ai' ),
		'include' => __( 'Only these slugs.', 'skyphusion-ai' ),
		'exclude' => __( 'Leave these slugs out.', 'skyphusion-ai' ),
	);
	foreach ( $tax_labels as $mode => $label ) {
		echo '<option value="' . esc_attr( $mode ) . '"';
		if ( $mode === $config['tax_mode'] ) {
			echo ' selected="selected"';
		}
		echo '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></label></p>';
	echo '<p><label>' . esc_html__( 'Slugs, separated by commas.', 'skyphusion-ai' ) . ' ';
	echo '<input type="text" name="skyphusion_ai_embed_terms" value="' . esc_attr( implode( ', ', $config['terms'] ) ) . '" /></label></p>';
	echo '<p><label>' . esc_html__( 'Daily embeddings limit, in neurons. Zero means this job has no limit of its own.', 'skyphusion-ai' ) . ' ';
	echo '<input type="number" name="skyphusion_ai_embed_limit" value="' . esc_attr( (string) $config['limit'] ) . '" min="0" step="1" /></label></p>';
	echo '<p>' . esc_html__( 'An editor can leave one post out.', 'skyphusion-ai' ) . '</p>';
	echo '<button type="submit">' . esc_html__( 'Save the embedding choice', 'skyphusion-ai' ) . '</button>';
	echo '</form>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="skyphusion_ai_embed_run" />';
	if ( function_exists( 'wp_nonce_field' ) ) {
		wp_nonce_field( 'skyphusion_ai_embed_run' );
	}
	echo '<button type="submit">' . esc_html__( 'Run the embedding sync', 'skyphusion-ai' ) . '</button>';
	echo '</form>';
}

/**
 * Read the save form and store the choice.
 *
 * @return void
 */
function skyphusion_ai_embed_handle_save() {
	if ( ! skyphusion_ai_embed_admin_allowed() ) {
		return;
	}
	check_admin_referer( 'skyphusion_ai_embed_save' );
	$index = '';
	if ( isset( $_POST['skyphusion_ai_embed_index'] ) ) {
		$index = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_embed_index'] ) );
	}
	$related = '';
	if ( isset( $_POST['skyphusion_ai_related'] ) ) {
		$related = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_related'] ) );
	}
	$related_place = '';
	if ( isset( $_POST['skyphusion_ai_related_place'] ) ) {
		$related_place = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_related_place'] ) );
	}
	$related_paragraph = '';
	if ( isset( $_POST['skyphusion_ai_related_paragraph'] ) ) {
		$related_paragraph = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_related_paragraph'] ) );
	}
	$related_why = '';
	if ( isset( $_POST['skyphusion_ai_related_why'] ) ) {
		$related_why = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_related_why'] ) );
	}
	$private = '';
	if ( isset( $_POST['skyphusion_ai_embed_private'] ) ) {
		$private = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_embed_private'] ) );
	}
	$images = '';
	if ( isset( $_POST['skyphusion_ai_embed_images'] ) ) {
		$images = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_embed_images'] ) );
	}
	$after = '';
	if ( isset( $_POST['skyphusion_ai_embed_after'] ) ) {
		$after = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_embed_after'] ) );
	}
	$tax = '';
	if ( isset( $_POST['skyphusion_ai_embed_tax'] ) ) {
		$tax = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_embed_tax'] ) );
	}
	$terms = array();
	if ( isset( $_POST['skyphusion_ai_embed_terms'] ) ) {
		$raw   = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_embed_terms'] ) );
		$terms = array_map( 'trim', explode( ',', $raw ) );
	}
	$limit = null;
	if ( isset( $_POST['skyphusion_ai_embed_limit'] ) ) {
		$limit = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_embed_limit'] ) );
	}
	skyphusion_ai_embed_apply(
		array(
			'index'             => $index,
			'related'           => $related,
			'related_place'     => $related_place,
			'related_paragraph' => $related_paragraph,
			'related_why'       => $related_why,
			'private'           => $private,
			'images'            => $images,
			'after'             => $after,
			'tax_mode'          => $tax,
			'terms'             => $terms,
			'limit'             => $limit,
		)
	);
	skyphusion_ai_embed_redirect();
}

/**
 * Run after the admin presses the button.
 *
 * @return void
 */
function skyphusion_ai_embed_handle_run() {
	if ( ! skyphusion_ai_embed_admin_allowed() ) {
		return;
	}
	check_admin_referer( 'skyphusion_ai_embed_run' );
	skyphusion_ai_embed_run( skyphusion_ai_embed_collect(), true );
	skyphusion_ai_embed_redirect();
}

/**
 * Delete the index after the admin presses that button.
 *
 * @return void
 */
function skyphusion_ai_embed_handle_delete() {
	if ( ! skyphusion_ai_embed_admin_allowed() ) {
		return;
	}
	check_admin_referer( 'skyphusion_ai_embed_delete' );
	skyphusion_ai_embed_delete_index( true );
	skyphusion_ai_embed_redirect();
}

/**
 * Return to the settings screen.
 *
 * @return void
 */
function skyphusion_ai_embed_redirect() {
	if ( ! function_exists( 'wp_safe_redirect' ) ) {
		return;
	}
	wp_safe_redirect( admin_url( 'options-general.php?page=cloudflare-ai-search' ) );
	exit;
}

/**
 * Count stored, waiting, and failed posts. This does not call the model.
 *
 * @param array<int, mixed>    $posts  Posts to consider.
 * @param array<string, mixed> $config Saved choices.
 * @return array{embedded: int, waiting: int, failed: int}
 */
function skyphusion_ai_embed_tally( $posts, $config ) {
	$embedded = 0;
	$waiting  = 0;
	$failed   = 0;
	$fields   = array();
	if ( isset( $config['fields'] ) && is_array( $config['fields'] ) ) {
		$fields = $config['fields'];
	}
	foreach ( $posts as $post ) {
		if ( ! is_array( $post ) || ! skyphusion_ai_embed_included( $post, $config ) ) {
			continue;
		}
		$text = skyphusion_ai_embed_text( $post, $fields );
		if ( '' === $text ) {
			continue;
		}
		$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		if ( skyphusion_ai_embed_failed( $post_id ) ) {
			++$failed;
			continue;
		}
		$hash = hash( 'sha256', $text );
		if ( skyphusion_ai_embed_hash_get( $post_id ) === $hash ) {
			++$embedded;
			continue;
		}
		++$waiting;
	}
	return array(
		'embedded' => $embedded,
		'waiting'  => $waiting,
		'failed'   => $failed,
	);
}

/**
 * Remove vectors for posts that no longer belong. The model is not called.
 *
 * @param array<int, mixed> $posts Posts to consider.
 * @return array<string, mixed>
 */
function skyphusion_ai_embed_reconcile( $posts ) {
	$config = skyphusion_ai_embed_config();
	if ( ! skyphusion_ai_embed_on( $config ) ) {
		return array(
			'state'   => 'off',
			'removed' => 0,
			'waiting' => 0,
		);
	}
	$fields = array();
	if ( isset( $config['fields'] ) && is_array( $config['fields'] ) ) {
		$fields = $config['fields'];
	}
	$removed     = 0;
	$credentials = skyphusion_ai_embed_credentials();
	foreach ( $posts as $post ) {
		if ( ! is_array( $post ) ) {
			continue;
		}
		$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		$text    = skyphusion_ai_embed_text( $post, $fields );
		$keep    = skyphusion_ai_embed_included( $post, $config ) && '' !== $text;
		$stored  = skyphusion_ai_embed_hash_get( $post_id );
		if ( $keep || '' === $stored ) {
			continue;
		}
		$result = skyphusion_ai_embed_remove( $post_id, $config, $credentials );
		if ( 'removed' === $result['state'] ) {
			++$removed;
		}
	}
	$tally = skyphusion_ai_embed_tally( $posts, skyphusion_ai_embed_config() );
	return array(
		'state'   => 'reconciled',
		'removed' => $removed,
		'waiting' => $tally['waiting'],
	);
}

/**
 * Store failed posts again.
 *
 * @param array<int, mixed> $posts Posts to consider.
 * @return array{state: string, retried: int}
 */
function skyphusion_ai_embed_retry( $posts ) {
	$config = skyphusion_ai_embed_config();
	if ( ! skyphusion_ai_embed_on( $config ) ) {
		return array(
			'state'   => 'off',
			'retried' => 0,
		);
	}
	$retried = 0;
	foreach ( $posts as $post ) {
		if ( ! is_array( $post ) ) {
			continue;
		}
		$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		if ( ! skyphusion_ai_embed_failed( $post_id ) ) {
			continue;
		}
		$result = skyphusion_ai_embed_sync_post( $post );
		if ( 'embedded' === $result['state'] ) {
			++$retried;
		}
	}
	return array(
		'state'   => 'retried',
		'retried' => $retried,
	);
}

/**
 * Forget stored hashes and embed the selection again.
 *
 * @param array<int, mixed> $posts Posts to consider.
 * @return array<string, mixed>
 */
function skyphusion_ai_embed_rebuild( $posts ) {
	$config = skyphusion_ai_embed_config();
	if ( ! skyphusion_ai_embed_on( $config ) ) {
		return array( 'state' => 'off' );
	}
	foreach ( $posts as $post ) {
		if ( ! is_array( $post ) ) {
			continue;
		}
		$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		skyphusion_ai_embed_hash_set( $post_id, '' );
		skyphusion_ai_embed_fail_set( $post_id, false );
	}
	return skyphusion_ai_embed_run( $posts, true );
}

/**
 * Vector count from the index.
 *
 * The field is result.vectorCount on GET /vectorize/v2/indexes/{name}/info.
 * See https://developers.cloudflare.com/api/resources/vectorize/
 *
 * @param array<string, mixed> $config Saved choices.
 * @return int|null Null when the index is missing or the call failed.
 */
function skyphusion_ai_embed_store_count( $config ) {
	if ( empty( $config['created'] ) ) {
		return null;
	}
	$credentials = skyphusion_ai_embed_credentials();
	$url         = skyphusion_ai_vectorize_url( $credentials['account_id'], (string) $config['index'], 'info' );
	$read        = skyphusion_ai_vectorize_request( 'GET', $url, $credentials['token'], '', 'application/json' );
	if ( is_wp_error( $read ) || ! isset( $read['result']['vectorCount'] ) ) {
		return null;
	}
	return (int) $read['result']['vectorCount'];
}

/**
 * Copy stored vectors into another Vectorize index. The model is not called.
 *
 * @param string            $destination Index name.
 * @param array<int, mixed> $posts       Posts whose vectors are copied.
 * @return array{state: string, moved: int}
 */
function skyphusion_ai_embed_move( $destination, $posts ) {
	$target = skyphusion_ai_embed_index_name( $destination );
	$config = skyphusion_ai_embed_config();
	if ( '' === $target || $target === (string) $config['index'] ) {
		return array(
			'state' => 'blocked',
			'moved' => 0,
		);
	}
	if ( empty( $config['created'] ) ) {
		return array(
			'state' => 'not_created',
			'moved' => 0,
		);
	}
	$ids = array();
	foreach ( $posts as $post ) {
		if ( ! is_array( $post ) ) {
			continue;
		}
		$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		if ( '' === skyphusion_ai_embed_hash_get( $post_id ) ) {
			continue;
		}
		$ids[] = skyphusion_ai_embed_vector_id( $post_id );
	}
	if ( array() === $ids ) {
		return array(
			'state' => 'copied',
			'moved' => 0,
		);
	}
	$credentials = skyphusion_ai_embed_credentials();
	$model       = isset( $config['model'] ) ? (string) $config['model'] : '';
	$create      = skyphusion_ai_embed_create_body( $target, $model );
	if ( null !== $create ) {
		$create_url = skyphusion_ai_vectorize_url( $credentials['account_id'], '', '' );
		skyphusion_ai_vectorize_request(
			'POST',
			$create_url,
			$credentials['token'],
			(string) wp_json_encode( $create ),
			'application/json'
		);
	}
	$read_url = skyphusion_ai_vectorize_url( $credentials['account_id'], (string) $config['index'], 'get_by_ids' );
	$read     = skyphusion_ai_vectorize_request(
		'POST',
		$read_url,
		$credentials['token'],
		(string) wp_json_encode( array( 'ids' => $ids ) ),
		'application/json'
	);
	if ( is_wp_error( $read ) ) {
		return array(
			'state' => 'blocked',
			'moved' => 0,
		);
	}
	$rows = array();
	if ( isset( $read['result'] ) && is_array( $read['result'] ) && isset( $read['result'][0] ) ) {
		$rows = $read['result'];
	} elseif ( isset( $read['result']['vectors'] ) && is_array( $read['result']['vectors'] ) ) {
		$rows = $read['result']['vectors'];
	}
	$lines = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! isset( $row['values'] ) || ! is_array( $row['values'] ) ) {
			continue;
		}
		$values  = array();
		$numeric = true;
		foreach ( $row['values'] as $number ) {
			if ( ! is_numeric( $number ) ) {
				$numeric = false;
				break;
			}
			$values[] = (float) $number;
		}
		if ( ! $numeric || array() === $values ) {
			continue;
		}
		$line = array(
			'id'     => (string) $row['id'],
			'values' => $values,
		);
		if ( isset( $row['metadata'] ) && is_array( $row['metadata'] ) ) {
			$line['metadata'] = $row['metadata'];
		}
		$encoded = wp_json_encode( $line );
		if ( is_string( $encoded ) ) {
			$lines[] = $encoded;
		}
	}
	if ( array() === $lines ) {
		return array(
			'state' => 'blocked',
			'moved' => 0,
		);
	}
	$write_url = skyphusion_ai_vectorize_url( $credentials['account_id'], $target, 'upsert' );
	$write     = skyphusion_ai_vectorize_request(
		'POST',
		$write_url,
		$credentials['token'],
		implode( "\n", $lines ) . "\n",
		'application/x-ndjson'
	);
	if ( is_wp_error( $write ) ) {
		return array(
			'state' => 'blocked',
			'moved' => 0,
		);
	}
	return array(
		'state' => 'copied',
		'moved' => count( $lines ),
	);
}

/**
 * WP-CLI jobs for the Vectorize index.
 *
 * Marker: embed-cli. run, reconcile, and rebuild stop while embeddings are off.
 * move copies stored vectors and does not call the embeddings model.
 *
 * @param array<int, string>    $args       Positional arguments.
 * @param array<string, string> $assoc_args Flags.
 * @return void
 */
function skyphusion_ai_embed_cli( $args, $assoc_args ) {
	if ( ! class_exists( 'WP_CLI' ) ) {
		return;
	}
	$command = '';
	if ( isset( $args[0] ) ) {
		$command = (string) $args[0];
	}
	$confirmed = isset( $assoc_args['yes'] );
	if ( '' === $command ) {
		$command = $confirmed ? 'run' : 'estimate';
	}
	if ( 'status' === $command ) {
		skyphusion_ai_embed_cli_status( $assoc_args );
		return;
	}
	if ( 'estimate' === $command ) {
		skyphusion_ai_embed_cli_estimate();
		return;
	}
	if ( 'run' === $command || 'reconcile' === $command || 'rebuild' === $command ) {
		skyphusion_ai_embed_cli_job( $command );
		return;
	}
	if ( 'retry' === $command ) {
		skyphusion_ai_embed_cli_retry();
		return;
	}
	if ( 'search' === $command ) {
		skyphusion_ai_embed_cli_search( $args );
		return;
	}
	if ( 'purge' === $command || 'delete-index' === $command ) {
		skyphusion_ai_embed_cli_purge( $command, $confirmed );
		return;
	}
	if ( 'move' === $command ) {
		skyphusion_ai_embed_cli_move( $assoc_args );
		return;
	}
	WP_CLI::log( 'embed_state=unknown' );
}

/**
 * Print local counts, and the index count when asked.
 *
 * @param array<string, string> $assoc_args Flags.
 * @return void
 */
function skyphusion_ai_embed_cli_status( $assoc_args ) {
	$config = skyphusion_ai_embed_config();
	$tally  = skyphusion_ai_embed_tally( skyphusion_ai_embed_collect(), $config );
	WP_CLI::log( 'embed_index=' . $config['index'] );
	WP_CLI::log( 'embed_created=' . (string) $config['created'] );
	WP_CLI::log( 'embed_model=' . (string) $config['model'] );
	WP_CLI::log( 'embed_on=' . ( skyphusion_ai_embed_on( $config ) ? '1' : '0' ) );
	WP_CLI::log( 'embed_embedded=' . (string) $tally['embedded'] );
	WP_CLI::log( 'embed_waiting=' . (string) $tally['waiting'] );
	WP_CLI::log( 'embed_failed=' . (string) $tally['failed'] );
	if ( ! isset( $assoc_args['store'] ) ) {
		return;
	}
	$count = skyphusion_ai_embed_store_count( $config );
	if ( null === $count ) {
		WP_CLI::log( 'embed_vectors=0' );
		return;
	}
	WP_CLI::log( 'embed_vectors=' . (string) $count );
}

/**
 * Print the estimate. The model is not called.
 *
 * @return void
 */
function skyphusion_ai_embed_cli_estimate() {
	$result   = skyphusion_ai_embed_run( skyphusion_ai_embed_collect(), false );
	$estimate = $result['estimate'];
	WP_CLI::log( 'embed_posts=' . (string) $estimate['posts'] );
	WP_CLI::log( 'embed_neurons=' . (string) $estimate['neurons'] );
	WP_CLI::log( 'embed_passages=' . (string) $estimate['passages'] );
	WP_CLI::log( 'embed_days=' . (string) $estimate['days'] );
	WP_CLI::log( 'embed_stored=' . (string) $estimate['stored'] );
	WP_CLI::log( 'embed_called=0' );
}

/**
 * Say that the job stopped because embeddings are off.
 *
 * @return void
 */
function skyphusion_ai_embed_cli_refuse() {
	WP_CLI::log( 'embed_state=off' );
	WP_CLI::log( 'embed_called=0' );
}

/**
 * Run, reconcile, or rebuild. Each stops while embeddings are off.
 *
 * @param string $command Job name.
 * @return void
 */
function skyphusion_ai_embed_cli_job( $command ) {
	if ( ! skyphusion_ai_embed_on( skyphusion_ai_embed_config() ) ) {
		skyphusion_ai_embed_cli_refuse();
		return;
	}
	$posts = skyphusion_ai_embed_collect();
	if ( 'reconcile' === $command ) {
		$result = skyphusion_ai_embed_reconcile( $posts );
		WP_CLI::log( 'embed_state=' . $result['state'] );
		WP_CLI::log( 'embed_removed=' . (string) $result['removed'] );
		WP_CLI::log( 'embed_waiting=' . (string) $result['waiting'] );
		WP_CLI::log( 'embed_called=0' );
		return;
	}
	if ( 'rebuild' === $command ) {
		$result = skyphusion_ai_embed_rebuild( $posts );
		WP_CLI::log( 'embed_state=' . $result['state'] );
		return;
	}
	$result = skyphusion_ai_embed_run( $posts, true );
	WP_CLI::log( 'embed_state=' . $result['state'] );
}

/**
 * Try failed posts again. This stops while embeddings are off.
 *
 * @return void
 */
function skyphusion_ai_embed_cli_retry() {
	if ( ! skyphusion_ai_embed_on( skyphusion_ai_embed_config() ) ) {
		skyphusion_ai_embed_cli_refuse();
		return;
	}
	$result = skyphusion_ai_embed_retry( skyphusion_ai_embed_collect() );
	WP_CLI::log( 'embed_state=' . $result['state'] );
	WP_CLI::log( 'embed_retried=' . (string) $result['retried'] );
}

/**
 * Search the index. This does not write vectors.
 *
 * @param array<int, string> $args Positional arguments.
 * @return void
 */
function skyphusion_ai_embed_cli_search( $args ) {
	$query = trim( implode( ' ', array_slice( $args, 1 ) ) );
	$rows  = skyphusion_ai_embed_search( $query, 5 );
	if ( isset( $rows['state'] ) ) {
		WP_CLI::log( 'embed_search=' . (string) $rows['state'] );
		return;
	}
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$title = isset( $row['title'] ) ? (string) $row['title'] : '';
		$id    = isset( $row['id'] ) ? (string) $row['id'] : '';
		WP_CLI::log( $id . ' ' . $title );
	}
}

/**
 * Delete the index. purge always confirms. delete-index waits for the yes flag.
 *
 * @param string $command   purge or delete-index.
 * @param bool   $confirmed True when the yes flag is set.
 * @return void
 */
function skyphusion_ai_embed_cli_purge( $command, $confirmed ) {
	if ( 'delete-index' === $command && ! $confirmed ) {
		$result = skyphusion_ai_embed_delete_index( false );
		WP_CLI::log( 'embed_delete=' . $result['state'] );
		return;
	}
	$result = skyphusion_ai_embed_delete_index( true );
	WP_CLI::log( 'embed_delete=' . $result['state'] );
	WP_CLI::log( 'embed_on=' . ( skyphusion_ai_embed_on( skyphusion_ai_embed_config() ) ? '1' : '0' ) );
	WP_CLI::log( 'embed_called=0' );
}

/**
 * Copy vectors to the index named by the flag. The model is not called.
 *
 * @param array<string, string> $assoc_args Flags.
 * @return void
 */
function skyphusion_ai_embed_cli_move( $assoc_args ) {
	$destination = '';
	if ( isset( $assoc_args['index'] ) ) {
		$destination = (string) $assoc_args['index'];
	}
	$result = skyphusion_ai_embed_move( $destination, skyphusion_ai_embed_collect() );
	WP_CLI::log( 'embed_state=' . $result['state'] );
	WP_CLI::log( 'embed_moved=' . (string) $result['moved'] );
	WP_CLI::log( 'embed_called=0' );
}

add_action( 'save_post', 'skyphusion_ai_embed_on_save', 20, 1 );
add_action( 'before_delete_post', 'skyphusion_ai_embed_on_delete', 10, 1 );
add_action( 'admin_post_skyphusion_ai_embed_save', 'skyphusion_ai_embed_handle_save' );
add_action( 'admin_post_skyphusion_ai_embed_run', 'skyphusion_ai_embed_handle_run' );
add_action( 'admin_post_skyphusion_ai_embed_delete', 'skyphusion_ai_embed_handle_delete' );

require_once __DIR__ . '/related.php';
