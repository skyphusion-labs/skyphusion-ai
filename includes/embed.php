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
	return array(
		'index'      => $index,
		'post_types' => $types,
		'fields'     => $fields,
		'model'      => $model,
		'created'    => $created,
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
 * True when this post belongs in the index.
 *
 * A password-protected post stays out. A draft, a revision, and a
 * type the admin did not choose stay out.
 *
 * @param array<string, mixed> $post   One post.
 * @param array<string, mixed> $config Saved choices.
 * @return bool
 */
function skyphusion_ai_embed_included( $post, $config ) {
	$status = isset( $post['post_status'] ) ? (string) $post['post_status'] : '';
	if ( 'publish' !== $status ) {
		return false;
	}
	$password = isset( $post['post_password'] ) ? (string) $post['post_password'] : '';
	if ( '' !== $password ) {
		return false;
	}
	$type = isset( $post['post_type'] ) ? (string) $post['post_type'] : '';
	if ( '' === $type || 'revision' === $type ) {
		return false;
	}
	$types = array();
	if ( isset( $config['post_types'] ) && is_array( $config['post_types'] ) ) {
		$types = $config['post_types'];
	}
	return in_array( $type, $types, true );
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
 * @return array{posts: int, tokens: int, neurons: int}
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
	return array(
		'posts'   => $count,
		'tokens'  => $tokens,
		'neurons' => $neurons,
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
	return $metadata;
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
			return array( 'state' => 'removed' );
		}
		return skyphusion_ai_embed_remove( $post_id, $config, $credentials );
	}
	if ( ! function_exists( 'cloudflare_ai_provider_embeddings' ) ) {
		return array( 'state' => 'blocked' );
	}
	$vectors = cloudflare_ai_provider_embeddings( array( $text ) );
	if ( is_wp_error( $vectors ) || ! is_array( $vectors ) || ! isset( $vectors[0] ) || ! is_array( $vectors[0] ) ) {
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
		return array( 'state' => 'blocked' );
	}
	skyphusion_ai_embed_hash_set( $post_id, $hash );
	return array( 'state' => 'embedded' );
}

/**
 * Posts the estimate and the run walk.
 *
 * A test may set skyphusion_ai_embed_posts. Otherwise published posts
 * of the chosen types are loaded.
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
	$types  = $config['post_types'];
	if ( array() === $types ) {
		return array();
	}
	$posts = get_posts(
		array(
			'post_type'   => $types,
			'post_status' => 'publish',
			'numberposts' => -1,
		)
	);
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
	/* translators: 1: number of posts, 2: estimated neurons. */
	$sentence = __( 'This run would embed %1$d posts. The estimate is %2$d neurons. The model has not been called.', 'skyphusion-ai' );
	echo '<p>' . esc_html( sprintf( $sentence, (int) $estimate['posts'], (int) $estimate['neurons'] ) ) . '</p>';
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
	skyphusion_ai_embed_apply( array( 'index' => $index ) );
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
 * WP-CLI entry. Without --yes the model is not called.
 *
 * @param array<int, string>    $args       Positional arguments.
 * @param array<string, string> $assoc_args Flags.
 * @return void
 */
function skyphusion_ai_embed_cli( $args, $assoc_args ) {
	$command = '';
	if ( isset( $args[0] ) ) {
		$command = (string) $args[0];
	}
	$confirmed = isset( $assoc_args['yes'] );
	if ( 'status' === $command ) {
		$config = skyphusion_ai_embed_config();
		WP_CLI::log( 'embed_index=' . $config['index'] );
		WP_CLI::log( 'embed_created=' . (string) $config['created'] );
		WP_CLI::log( 'embed_model=' . (string) $config['model'] );
		return;
	}
	if ( 'search' === $command ) {
		$query = trim( implode( ' ', array_slice( $args, 1 ) ) );
		$rows  = skyphusion_ai_embed_search( $query, 5 );
		if ( isset( $rows['state'] ) ) {
			WP_CLI::log( 'embed_search=' . (string) $rows['state'] );
			return;
		}
		foreach ( $rows as $row ) {
			WP_CLI::log( $row['id'] . ' ' . $row['title'] );
		}
		return;
	}
	if ( 'delete-index' === $command ) {
		$result = skyphusion_ai_embed_delete_index( $confirmed );
		WP_CLI::log( 'embed_delete=' . $result['state'] );
		return;
	}
	$result = skyphusion_ai_embed_run( skyphusion_ai_embed_collect(), $confirmed );
	if ( 'estimate' === $result['state'] ) {
		WP_CLI::log( 'embed_posts=' . (string) $result['estimate']['posts'] );
		WP_CLI::log( 'embed_neurons=' . (string) $result['estimate']['neurons'] );
		WP_CLI::log( 'embed_called=0' );
		return;
	}
	WP_CLI::log( 'embed_state=' . $result['state'] );
}

add_action( 'save_post', 'skyphusion_ai_embed_on_save', 20, 1 );
add_action( 'before_delete_post', 'skyphusion_ai_embed_on_delete', 10, 1 );
add_action( 'admin_post_skyphusion_ai_embed_save', 'skyphusion_ai_embed_handle_save' );
add_action( 'admin_post_skyphusion_ai_embed_run', 'skyphusion_ai_embed_handle_run' );
add_action( 'admin_post_skyphusion_ai_embed_delete', 'skyphusion_ai_embed_handle_delete' );
