<?php
/**
 * Keep a Cloudflare Vectorize index in step with published posts.
 *
 * The admin sees a neuron estimate before the first run. A later save
 * calls the model only when the chosen text changed. Title and content
 * are stored as short passages. Deleting the plugin removes the saved
 * choice and leaves the index in the Cloudflare account.
 *
 * On wordpress-on-cloudflare the Worker can provision Workers AI and
 * Vectorize as bindings. When SKYPHUSION_AI_CF_BRIDGE is set and
 * skyphusion_worker_call() exists, embeddings and the Vectorize data
 * plane use that bridge. Every other install keeps the REST API.
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
 * Characters in one stored passage.
 *
 * About 500 characters stays under the 512-token input of
 * `@cf/baai/bge-large-en-v1.5`.
 *
 * @return int
 */
function skyphusion_ai_embed_passage_size() {
	return 500;
}

/**
 * Characters repeated at the start of the next passage.
 *
 * The overlap is short so a sentence on the cut is still found.
 *
 * @return int
 */
function skyphusion_ai_embed_passage_overlap() {
	return 60;
}

/**
 * Cut one window on a paragraph, a sentence, or a space.
 *
 * The cut stays in the second half. A window with no break is kept whole.
 *
 * @param string $window Text that is already at most one passage long.
 * @return int
 */
function skyphusion_ai_embed_passage_cut( $window ) {
	$size = strlen( $window );
	$half = intdiv( $size, 2 );
	if ( 1 > $half ) {
		return $size;
	}
	foreach ( array( "\n\n", "\n", '. ', ' ' ) as $mark ) {
		$pos = strrpos( $window, $mark );
		if ( false === $pos || $half > $pos ) {
			continue;
		}
		$cut = $pos + strlen( $mark );
		if ( $cut > $size || $half >= $cut ) {
			continue;
		}
		return $cut;
	}
	return $size;
}

/**
 * Split title and content into short passages.
 *
 * Marker: chunk-passages. Vectorize does not split text. This does.
 * A short post stays one passage. The next passage repeats a short tail
 * of the previous one.
 *
 * @param string $text Title and content already joined.
 * @return array<int, string>
 */
function skyphusion_ai_embed_passages( $text ) {
	$text = trim( (string) $text );
	if ( '' === $text ) {
		return array();
	}
	$size    = skyphusion_ai_embed_passage_size();
	$overlap = skyphusion_ai_embed_passage_overlap();
	$length  = strlen( $text );
	if ( $length <= $size ) {
		return array( $text );
	}
	$passages = array();
	$start    = 0;
	$guard    = 0;
	while ( $length > $start && 10000 > $guard ) {
		++$guard;
		$remaining = $length - $start;
		if ( $remaining <= $size ) {
			$tail = trim( substr( $text, $start ) );
			if ( '' !== $tail ) {
				$passages[] = $tail;
			}
			break;
		}
		$window = substr( $text, $start, $size );
		$cut    = skyphusion_ai_embed_passage_cut( $window );
		if ( 1 > $cut ) {
			$cut = $size;
		}
		$piece = trim( substr( $text, $start, $cut ) );
		if ( '' !== $piece ) {
			$passages[] = $piece;
		}
		$next = $start + $cut - $overlap;
		if ( $next <= $start ) {
			$next = $start + $cut;
		}
		$start = $next;
	}
	return $passages;
}

/**
 * Stable id for one passage. The first passage keeps the post id.
 *
 * @param int $post_id Post id.
 * @param int $index   Passage index, starting at zero.
 * @return string
 */
function skyphusion_ai_embed_passage_id( $post_id, $index ) {
	$base  = skyphusion_ai_embed_vector_id( $post_id );
	$index = (int) $index;
	if ( 1 > $index ) {
		return $base;
	}
	return $base . '-' . (string) $index;
}

/**
 * Ids for the passages already stored, or one id when the count is unknown.
 *
 * @param int $post_id Post id.
 * @param int $count   Stored passage count.
 * @return array<int, string>
 */
function skyphusion_ai_embed_passage_ids( $post_id, $count ) {
	$count = (int) $count;
	if ( 1 > $count ) {
		$count = 1;
	}
	$ids   = array();
	$index = 0;
	while ( $count > $index ) {
		$ids[] = skyphusion_ai_embed_passage_id( $post_id, $index );
		++$index;
	}
	return $ids;
}

/**
 * True when this vector id belongs to the post.
 *
 * @param string $vector_id Vector id.
 * @param int    $post_id   Post id.
 * @return bool
 */
function skyphusion_ai_embed_same_post( $vector_id, $post_id ) {
	$vector_id = (string) $vector_id;
	$own       = skyphusion_ai_embed_vector_id( $post_id );
	if ( $vector_id === $own ) {
		return true;
	}
	$prefix = $own . '-';
	return 0 === strpos( $vector_id, $prefix );
}

/**
 * Post meta that remembers how many passages were stored.
 *
 * @return string
 */
function skyphusion_ai_embed_part_key() {
	return 'skyphusion_ai_embed_parts';
}

/**
 * Passage count already stored for one post.
 *
 * @param int $post_id Post id.
 * @return int
 */
function skyphusion_ai_embed_part_count( $post_id ) {
	if ( ! function_exists( 'get_post_meta' ) ) {
		return 0;
	}
	$value = get_post_meta( (int) $post_id, skyphusion_ai_embed_part_key(), true );
	if ( ! is_numeric( $value ) ) {
		return 0;
	}
	$count = (int) $value;
	if ( 0 > $count ) {
		return 0;
	}
	return $count;
}

/**
 * Remember or forget the passage count for one post.
 *
 * @param int $post_id Post id.
 * @param int $count   Passage count, or zero to forget it.
 * @return void
 */
function skyphusion_ai_embed_part_set( $post_id, $count ) {
	$count = (int) $count;
	if ( 1 > $count ) {
		if ( function_exists( 'delete_post_meta' ) ) {
			delete_post_meta( (int) $post_id, skyphusion_ai_embed_part_key() );
		}
		return;
	}
	if ( function_exists( 'update_post_meta' ) ) {
		update_post_meta( (int) $post_id, skyphusion_ai_embed_part_key(), (string) $count );
	}
}

/**
 * Taxonomies the category and tag filter reads for one post type.
 *
 * A WooCommerce product files its categories and tags under its own
 * taxonomies. Every other type uses the core ones.
 *
 * @param string $type Post type.
 * @return array<int, string>
 */
function skyphusion_ai_embed_term_taxonomies( $type ) {
	if ( 'product' === $type ) {
		return array( 'product_cat', 'product_tag' );
	}
	return array( 'category', 'post_tag' );
}

/**
 * Category and tag slugs of one post.
 *
 * A row that already carries categories or tags keeps them. A row made from
 * a WP_Post has neither key, so the slugs come from the term API, which reads
 * the term cache get_posts() loaded (issue #198).
 *
 * @param array<string, mixed> $post One post.
 * @return array<int, string>
 */
function skyphusion_ai_embed_post_slugs( $post ) {
	$have    = array();
	$carried = false;
	foreach ( array( 'categories', 'tags' ) as $bucket ) {
		if ( ! isset( $post[ $bucket ] ) || ! is_array( $post[ $bucket ] ) ) {
			continue;
		}
		$carried = true;
		foreach ( $post[ $bucket ] as $slug ) {
			$have[] = (string) $slug;
		}
	}
	if ( $carried ) {
		return $have;
	}
	return skyphusion_ai_embed_api_terms( $post, 'slug' );
}

/**
 * One field of every category and tag term of one post, from the term API.
 *
 * The taxonomies come from skyphusion_ai_embed_term_taxonomies(), so a
 * product reads product_cat and product_tag. get_the_terms() reads the term
 * cache when get_posts() loaded it. Issues #198 and #203.
 *
 * @param array<string, mixed> $post  One post.
 * @param string               $field Term field: slug or name.
 * @return array<int, string>
 */
function skyphusion_ai_embed_api_terms( $post, $field ) {
	$found = array();
	if ( ! function_exists( 'get_the_terms' ) || ! is_array( $post ) ) {
		return $found;
	}
	$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
	$type    = isset( $post['post_type'] ) ? (string) $post['post_type'] : '';
	if ( 1 > $post_id ) {
		return $found;
	}
	foreach ( skyphusion_ai_embed_term_taxonomies( $type ) as $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			continue;
		}
		foreach ( $terms as $term ) {
			if ( is_object( $term ) && isset( $term->$field ) ) {
				$found[] = (string) $term->$field;
			}
		}
	}
	return $found;
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
	$have = skyphusion_ai_embed_post_slugs( $post );
	$hit  = false;
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
	return skyphusion_ai_embed_estimate_from( skyphusion_ai_embed_estimate_tally( $posts, $config ), $config );
}

/**
 * Posts, passages and characters a run would send. This does not call the model.
 *
 * The screen adds these up one page at a time. The run adds them up for
 * every post at once.
 *
 * @param array<int, mixed>    $posts  Posts to consider.
 * @param array<string, mixed> $config Saved choices.
 * @return array{posts: int, passages: int, chars: int}
 */
function skyphusion_ai_embed_estimate_tally( $posts, $config ) {
	$chars         = 0;
	$count         = 0;
	$passage_count = 0;
	$fields        = array();
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
		$pieces = skyphusion_ai_embed_passages( $text );
		if ( array() === $pieces ) {
			continue;
		}
		++$count;
		$passage_count += count( $pieces );
		foreach ( $pieces as $piece ) {
			$chars += strlen( $piece );
		}
	}
	return array(
		'posts'    => $count,
		'passages' => $passage_count,
		'chars'    => $chars,
	);
}

/**
 * Neuron estimate from a tally.
 *
 * @param array<string, mixed> $tally  Output of skyphusion_ai_embed_estimate_tally(), or a sum of them.
 * @param array<string, mixed> $config Saved choices.
 * @return array<string, int>
 */
function skyphusion_ai_embed_estimate_from( $tally, $config ) {
	$count         = isset( $tally['posts'] ) ? (int) $tally['posts'] : 0;
	$passage_count = isset( $tally['passages'] ) ? (int) $tally['passages'] : 0;
	$chars         = isset( $tally['chars'] ) ? (int) $tally['chars'] : 0;
	$tokens        = intdiv( $chars, 4 );

	$model = '@cf/baai/bge-large-en-v1.5';
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
		'passages' => $passage_count,
		'days'     => $days,
		'stored'   => $passage_count * 1024,
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
 * True when Workers AI and Vectorize should use the WfP op bridge.
 *
 * Both halves are required. skyphusion_worker_call() is the bridge.
 * SKYPHUSION_AI_CF_BRIDGE means the bindings are provisioned, the same
 * pattern as SKYPHUSION_AI_ALT. The Worker does not write this constant
 * yet. Until it does, this stays false and every call uses the REST API.
 *
 * @return bool
 */
function skyphusion_ai_cf_bridge_on() {
	if ( ! defined( 'SKYPHUSION_AI_CF_BRIDGE' ) || ! SKYPHUSION_AI_CF_BRIDGE ) {
		return false;
	}
	return function_exists( 'skyphusion_worker_call' );
}

/**
 * Local stand-in account id. It is not a Cloudflare account.
 *
 * The URL builder rejects an empty account id. The bridge never sends
 * this value. A control-plane call with no real token is refused before
 * any HTTP request, so it is not sent there either.
 *
 * @return string
 */
function skyphusion_ai_bridge_account_id() {
	return str_repeat( '0', 32 );
}

/**
 * Local stand-in token. It is not a Cloudflare token.
 *
 * Callers refuse to run when the token is empty. This value only fills
 * that gap. It must not be placed on a request.
 *
 * @return string
 */
function skyphusion_ai_bridge_token() {
	return 'skyphusion-ai-wfp-bridge';
}

/**
 * True when the token is the local stand-in.
 *
 * @param string $token Token from the credential helper.
 * @return bool
 */
function skyphusion_ai_bridge_token_is( $token ) {
	return skyphusion_ai_bridge_token() === (string) $token;
}

/**
 * One op-bridge call. The name is aiEmbed or vectorize.
 *
 * The Worker reply is JSON. ok true, or success true, is accepted.
 * The token is not part of the message.
 *
 * @param string               $name    Op name the Worker keys on.
 * @param array<string, mixed> $payload Op body.
 * @return array<string, mixed>|WP_Error
 */
function skyphusion_ai_worker_op( $name, $payload ) {
	if ( 'aiEmbed' !== $name && 'vectorize' !== $name ) {
		return new WP_Error( 'skyphusion_ai_embed', 'The site could not reach Cloudflare.' );
	}
	if ( ! function_exists( 'skyphusion_worker_call' ) || ! function_exists( 'wp_json_encode' ) ) {
		return new WP_Error( 'skyphusion_ai_embed', 'The site could not reach Cloudflare.' );
	}
	$encoded = wp_json_encode( array( $name => $payload ) );
	if ( ! is_string( $encoded ) || '' === $encoded ) {
		return new WP_Error( 'skyphusion_ai_embed', 'The site could not reach Cloudflare.' );
	}
	try {
		$raw = skyphusion_worker_call( $encoded );
	} catch ( Throwable $error ) {
		unset( $error );
		return new WP_Error( 'skyphusion_ai_embed', 'The site could not reach Cloudflare.' );
	}
	$decoded = json_decode( (string) $raw, true );
	if ( ! is_array( $decoded ) ) {
		return new WP_Error( 'skyphusion_ai_embed', 'The site could not reach Cloudflare.' );
	}
	$ok = ! empty( $decoded['success'] );
	if ( array_key_exists( 'ok', $decoded ) && true === $decoded['ok'] ) {
		$ok = true;
	}
	if ( ! $ok ) {
		return new WP_Error( 'skyphusion_ai_embed', 'Vectorize did not accept the change.' );
	}
	return $decoded;
}

/**
 * Index and suffix from a Vectorize REST URL.
 *
 * An empty suffix is the collection, or one index. Those are control
 * plane. The binding cannot create or list indexes.
 *
 * @param string $url Absolute Vectorize URL.
 * @return array{index: string, suffix: string}|null
 */
function skyphusion_ai_vectorize_url_parts( $url ) {
	if ( function_exists( 'wp_parse_url' ) ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$path = parse_url( (string) $url, PHP_URL_PATH );
	}
	if ( ! is_string( $path ) ) {
		return null;
	}
	$matched = preg_match( '#/vectorize/v2/indexes(?:/([^/]+))?(?:/([^/]+))?$#', $path, $parts );
	if ( 1 !== $matched ) {
		return null;
	}
	$index  = isset( $parts[1] ) ? rawurldecode( (string) $parts[1] ) : '';
	$suffix = isset( $parts[2] ) ? rawurldecode( (string) $parts[2] ) : '';
	return array(
		'index'  => $index,
		'suffix' => $suffix,
	);
}

/**
 * Binding method for one data-plane URL, or null.
 *
 * Query, upsert, getByIds, and deleteByIds are data plane. Create,
 * list, info, and index delete are not. A URL for a different index
 * is not this binding.
 *
 * @param string $method HTTP method.
 * @param string $url    Absolute Vectorize URL.
 * @return string|null
 */
function skyphusion_ai_vectorize_bridge_op( $method, $url ) {
	if ( 'POST' !== strtoupper( (string) $method ) ) {
		return null;
	}
	$parts = skyphusion_ai_vectorize_url_parts( $url );
	if ( null === $parts ) {
		return null;
	}
	$map = array(
		'query'         => 'query',
		'upsert'        => 'upsert',
		'get_by_ids'    => 'getByIds',
		'delete_by_ids' => 'deleteByIds',
	);
	if ( ! isset( $map[ $parts['suffix'] ] ) ) {
		return null;
	}
	$config = skyphusion_ai_embed_config();
	$bound  = '';
	if ( isset( $config['index'] ) ) {
		$bound = skyphusion_ai_embed_index_name( (string) $config['index'] );
	}
	if ( '' === $bound || $parts['index'] !== $bound ) {
		return null;
	}
	return $map[ $parts['suffix'] ];
}

/**
 * Vectors from one NDJSON upsert body.
 *
 * @param string $body Request body.
 * @return array<int, array<string, mixed>>|null
 */
function skyphusion_ai_vectorize_ndjson_vectors( $body ) {
	$lines = preg_split( "/\r\n|\n|\r/", (string) $body );
	if ( ! is_array( $lines ) ) {
		return null;
	}
	$vectors = array();
	foreach ( $lines as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) {
			continue;
		}
		$row = json_decode( $line, true );
		if ( ! is_array( $row ) || ! isset( $row['id'], $row['values'] ) || ! is_array( $row['values'] ) ) {
			return null;
		}
		$one = array(
			'id'     => (string) $row['id'],
			'values' => $row['values'],
		);
		if ( isset( $row['metadata'] ) && is_array( $row['metadata'] ) ) {
			$one['metadata'] = $row['metadata'];
		}
		$vectors[] = $one;
	}
	if ( array() === $vectors ) {
		return null;
	}
	return $vectors;
}

/**
 * Worker payload for one data-plane op, or null when the body is unusable.
 *
 * @param string $op            Binding method name.
 * @param string $body          Request body.
 * @param string $content_type  Content type.
 * @return array<string, mixed>|null
 */
function skyphusion_ai_vectorize_bridge_payload( $op, $body, $content_type ) {
	$payload = array(
		'op' => $op,
	);
	if ( 'upsert' === $op ) {
		$typed = (string) $content_type;
		$json  = false !== strpos( $typed, 'ndjson' ) || false !== strpos( $typed, 'json' );
		if ( ! $json ) {
			return null;
		}
		$vectors = skyphusion_ai_vectorize_ndjson_vectors( $body );
		if ( null === $vectors ) {
			return null;
		}
		$payload['vectors'] = $vectors;
		return $payload;
	}
	$decoded = json_decode( (string) $body, true );
	if ( ! is_array( $decoded ) ) {
		return null;
	}
	if ( 'query' === $op ) {
		if ( ! isset( $decoded['vector'] ) || ! is_array( $decoded['vector'] ) ) {
			return null;
		}
		$payload['vector'] = $decoded['vector'];
		if ( isset( $decoded['topK'] ) && is_numeric( $decoded['topK'] ) ) {
			$payload['topK'] = (int) $decoded['topK'];
		}
		if ( isset( $decoded['returnMetadata'] ) ) {
			$payload['returnMetadata'] = (string) $decoded['returnMetadata'];
		}
		return $payload;
	}
	if ( ! isset( $decoded['ids'] ) || ! is_array( $decoded['ids'] ) ) {
		return null;
	}
	$ids = array();
	foreach ( $decoded['ids'] as $id ) {
		$ids[] = (string) $id;
	}
	$payload['ids'] = $ids;
	return $payload;
}

/**
 * REST-shaped envelope from a Worker vectorize reply.
 *
 * Callers already read result.matches and a list of vectors. The bridge
 * keeps that shape so the rest of the plugin does not change.
 *
 * @param string               $op    Binding method name.
 * @param array<string, mixed> $reply Worker JSON.
 * @return array<string, mixed>
 */
function skyphusion_ai_vectorize_bridge_envelope( $op, $reply ) {
	if ( ! empty( $reply['success'] ) && isset( $reply['result'] ) ) {
		return $reply;
	}
	if ( 'query' === $op ) {
		$matches = array();
		if ( isset( $reply['matches'] ) && is_array( $reply['matches'] ) ) {
			$matches = $reply['matches'];
		} elseif ( isset( $reply['result']['matches'] ) && is_array( $reply['result']['matches'] ) ) {
			$matches = $reply['result']['matches'];
		}
		return array(
			'success' => true,
			'result'  => array(
				'matches' => $matches,
			),
		);
	}
	if ( 'getByIds' === $op ) {
		$vectors = array();
		if ( isset( $reply['vectors'] ) && is_array( $reply['vectors'] ) ) {
			$vectors = $reply['vectors'];
		} elseif ( isset( $reply['result'] ) && is_array( $reply['result'] ) ) {
			if ( isset( $reply['result']['vectors'] ) && is_array( $reply['result']['vectors'] ) ) {
				$vectors = $reply['result']['vectors'];
			} else {
				$vectors = $reply['result'];
			}
		}
		return array(
			'success' => true,
			'result'  => $vectors,
		);
	}
	$result = array(
		'ok' => true,
	);
	if ( isset( $reply['result'] ) && is_array( $reply['result'] ) ) {
		$result = $reply['result'];
	}
	return array(
		'success' => true,
		'result'  => $result,
	);
}

/**
 * Data-plane call through the bridge, or null to keep the REST path.
 *
 * Null is only for a control-plane call, or another index, when a real
 * token is present. The stand-in token is never sent. Create and list
 * are not binding ops.
 *
 * @param string $method       HTTP method.
 * @param string $url          Absolute URL.
 * @param string $token        API token or the local stand-in.
 * @param string $body         Request body.
 * @param string $content_type Content type.
 * @return array<string, mixed>|WP_Error|null
 */
function skyphusion_ai_vectorize_via_bridge( $method, $url, $token, $body, $content_type ) {
	$op = skyphusion_ai_vectorize_bridge_op( $method, $url );
	if ( null === $op ) {
		if ( skyphusion_ai_bridge_token_is( $token ) ) {
			return new WP_Error( 'skyphusion_ai_embed', 'Vectorize did not accept the change.' );
		}
		return null;
	}
	$payload = skyphusion_ai_vectorize_bridge_payload( $op, $body, $content_type );
	if ( null === $payload ) {
		return new WP_Error( 'skyphusion_ai_embed', 'Vectorize did not accept the change.' );
	}
	$reply = skyphusion_ai_worker_op( 'vectorize', $payload );
	if ( is_wp_error( $reply ) ) {
		return $reply;
	}
	return skyphusion_ai_vectorize_bridge_envelope( $op, $reply );
}

/**
 * Workers AI embeddings through the aiEmbed op. No token is sent.
 *
 * The neuron budget still applies. The return shape matches the REST helper.
 *
 * @param array<int, string> $texts Texts to embed.
 * @return array<int, array<int, float>>|WP_Error
 */
function skyphusion_ai_bridge_embeddings( $texts ) {
	$body = cloudflare_ai_provider_embeddings_body( $texts );
	if ( empty( $body['text'] ) ) {
		return new WP_Error( 'cloudflare_ai_embeddings', __( 'Add the Cloudflare account ID and API token before embedding text.', 'skyphusion-ai' ) );
	}
	if ( function_exists( 'skyphusion_ai_neuron_refusal' ) ) {
		$refusal = skyphusion_ai_neuron_refusal();
		if ( is_wp_error( $refusal ) ) {
			return $refusal;
		}
	}
	if ( function_exists( 'skyphusion_ai_tool_refusal' ) ) {
		$tool = skyphusion_ai_tool_refusal( 'embeddings' );
		if ( is_wp_error( $tool ) ) {
			return $tool;
		}
	}
	$model = '@cf/baai/bge-large-en-v1.5';
	if ( function_exists( 'cloudflare_ai_provider_embeddings_model' ) ) {
		$model = cloudflare_ai_provider_embeddings_model();
	}
	$reply = skyphusion_ai_worker_op(
		'aiEmbed',
		array(
			'model' => $model,
			'text'  => $body['text'],
		)
	);
	if ( is_wp_error( $reply ) ) {
		if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
			skyphusion_ai_neuron_record( 'embeddings', $model, $reply );
		}
		return new WP_Error( 'cloudflare_ai_embeddings', __( 'The site could not reach Cloudflare.', 'skyphusion-ai' ) );
	}
	$decoded = $reply;
	if ( isset( $reply['data'] ) && is_array( $reply['data'] ) && ! isset( $reply['result'] ) ) {
		$decoded = array(
			'success' => true,
			'result'  => array(
				'data' => $reply['data'],
			),
		);
	}
	if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
		skyphusion_ai_neuron_record( 'embeddings', $model, $decoded );
	}
	$vectors = cloudflare_ai_provider_read_embeddings( $decoded );
	if ( array() === $vectors ) {
		return new WP_Error( 'cloudflare_ai_embeddings', __( 'The site could not reach Cloudflare.', 'skyphusion-ai' ) );
	}
	return $vectors;
}

/**
 * Mark the pre-provisioned index ready. No create request is sent.
 *
 * A binding cannot create or list an index. The WfP provisioner already
 * created it. A model with no preset stays blocked, as it does on REST.
 *
 * @param array<string, mixed> $config Saved choices.
 * @param string               $model  Embeddings model id.
 * @return array{state: string}
 */
function skyphusion_ai_embed_ensure_bridge( $config, $model ) {
	$body = skyphusion_ai_embed_create_body( (string) $config['index'], $model );
	if ( null === $body ) {
		return array( 'state' => 'blocked' );
	}
	$config['created'] = 1;
	$config['enabled'] = 1;
	$config['model']   = $model;
	if ( function_exists( 'update_option' ) ) {
		update_option( skyphusion_ai_embed_option_name(), $config, false );
	}
	return array( 'state' => 'ready' );
}

/**
 * Account id and token from the same places as Workers AI.
 *
 * When the bridge is on and no real token is stored, the return is a
 * local stand-in so callers can proceed. The stand-in is not sent.
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
		$resolved = array(
			'account_id' => '',
			'token'      => '',
		);
	} else {
		$resolved = cloudflare_ai_provider_resolve_credentials( $key, $account );
	}
	if ( ! skyphusion_ai_cf_bridge_on() ) {
		return $resolved;
	}
	if ( '' !== $resolved['account_id'] && '' !== $resolved['token'] ) {
		return $resolved;
	}
	if ( '' === $resolved['account_id'] ) {
		$resolved['account_id'] = skyphusion_ai_bridge_account_id();
	}
	$resolved['token'] = skyphusion_ai_bridge_token();
	return $resolved;
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
	if ( skyphusion_ai_cf_bridge_on() ) {
		$bridged = skyphusion_ai_vectorize_via_bridge( $method, $url, $token, $body, $content_type );
		if ( null !== $bridged ) {
			return $bridged;
		}
	}
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
	if ( skyphusion_ai_cf_bridge_on() ) {
		return skyphusion_ai_embed_ensure_bridge( $config, $model );
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
 * A page image can be longer than a post address, so the caller sets the cap.
 *
 * @param string $url   Raw address.
 * @param int    $limit Longest address to keep.
 * @return string
 */
function skyphusion_ai_embed_public_url( $url, $limit = 200 ) {
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
	$limit = (int) $limit;
	if ( 1 > $limit ) {
		$limit = 200;
	}
	if ( strlen( $url ) > $limit ) {
		return '';
	}
	return $url;
}

/**
 * Metadata stored beside the vector. The post body is not included.
 *
 * @param array<string, mixed> $post One post.
 * @return array<string, string|int>
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
	if ( 'product' === $type && function_exists( 'skyphusion_ai_assistant_product_meta' ) ) {
		$product_meta = skyphusion_ai_assistant_product_meta( $post_id );
		foreach ( array( 'image', 'sku', 'stock_status', 'count' ) as $key ) {
			if ( ! array_key_exists( $key, $product_meta ) ) {
				continue;
			}
			$metadata[ $key ] = $product_meta[ $key ];
		}
	}
	return $metadata;
}

/**
 * Categories and tags stored beside the vector.
 *
 * The line is empty when the post has none. A page view does not call this.
 * A row made from a WP_Post carries no categories or tags key, so the names
 * come from the term API by post type (issue #203).
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
	if ( array() !== $names ) {
		return implode( ', ', $names );
	}
	foreach ( skyphusion_ai_embed_api_terms( $post, 'name' ) as $name ) {
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
	$body   = wp_json_encode( array( 'ids' => skyphusion_ai_embed_passage_ids( $post_id, skyphusion_ai_embed_part_count( $post_id ) ) ) );
	$result = skyphusion_ai_vectorize_request( 'POST', $url, $credentials['token'], (string) $body, 'application/json' );
	if ( is_wp_error( $result ) ) {
		return array( 'state' => 'blocked' );
	}
	skyphusion_ai_embed_hash_set( $post_id, '' );
	skyphusion_ai_embed_part_set( $post_id, 0 );
	return array( 'state' => 'removed' );
}

/**
 * Embed passages in batches of 100. Workers AI accepts at most 100 strings.
 *
 * @param array<int, string> $passages Passage texts.
 * @return array<int, array<int, float>>|WP_Error
 */
function skyphusion_ai_embed_passage_vectors( $passages ) {
	$vectors = array();
	$offset  = 0;
	$total   = count( $passages );
	while ( $total > $offset ) {
		$batch = array_slice( $passages, $offset, 100 );
		$got   = cloudflare_ai_provider_embeddings( $batch );
		if ( is_wp_error( $got ) || ! is_array( $got ) || count( $got ) !== count( $batch ) ) {
			return new WP_Error( 'skyphusion_ai_embed', 'The embeddings model did not return a vector for every passage.' );
		}
		foreach ( $got as $vector ) {
			if ( ! is_array( $vector ) ) {
				return new WP_Error( 'skyphusion_ai_embed', 'The embeddings model did not return a vector for every passage.' );
			}
			$vectors[] = $vector;
		}
		$offset += count( $batch );
	}
	return $vectors;
}

/**
 * Upsert NDJSON lines. One request stays at or under 1000 vectors.
 *
 * @param array<string, mixed>  $config      Saved choices.
 * @param array<string, string> $credentials Account id and token.
 * @param array<int, string>    $lines       NDJSON objects.
 * @return true|WP_Error
 */
function skyphusion_ai_embed_upsert_lines( $config, $credentials, $lines ) {
	$url    = skyphusion_ai_vectorize_url( $credentials['account_id'], (string) $config['index'], 'upsert' );
	$offset = 0;
	$total  = count( $lines );
	if ( 1 > $total ) {
		return new WP_Error( 'skyphusion_ai_embed', 'Vectorize did not accept the change.' );
	}
	while ( $total > $offset ) {
		$batch = array_slice( $lines, $offset, 1000 );
		$save  = skyphusion_ai_vectorize_request(
			'POST',
			$url,
			$credentials['token'],
			implode( "\n", $batch ) . "\n",
			'application/x-ndjson'
		);
		if ( is_wp_error( $save ) ) {
			return $save;
		}
		$offset += count( $batch );
	}
	return true;
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
	$passages = skyphusion_ai_embed_passages( $text );
	if ( array() === $passages ) {
		return array( 'state' => 'blocked' );
	}
	$vectors = skyphusion_ai_embed_passage_vectors( $passages );
	if ( is_wp_error( $vectors ) || ! is_array( $vectors ) || count( $vectors ) !== count( $passages ) ) {
		skyphusion_ai_embed_fail_set( $post_id, true );
		return array( 'state' => 'blocked' );
	}
	$lines = array();
	foreach ( array_keys( $passages ) as $index ) {
		$index = (int) $index;
		if ( ! isset( $vectors[ $index ] ) || ! is_array( $vectors[ $index ] ) ) {
			skyphusion_ai_embed_fail_set( $post_id, true );
			return array( 'state' => 'blocked' );
		}
		$metadata         = skyphusion_ai_embed_metadata( $post );
		$metadata['part'] = (string) $index;
		$encoded          = wp_json_encode(
			array(
				'id'       => skyphusion_ai_embed_passage_id( $post_id, $index ),
				'values'   => $vectors[ $index ],
				'metadata' => $metadata,
			)
		);
		if ( ! is_string( $encoded ) ) {
			skyphusion_ai_embed_fail_set( $post_id, true );
			return array( 'state' => 'blocked' );
		}
		$lines[] = $encoded;
	}
	$old   = skyphusion_ai_embed_part_count( $post_id );
	$saved = skyphusion_ai_embed_upsert_lines( $config, $credentials, $lines );
	if ( is_wp_error( $saved ) ) {
		skyphusion_ai_embed_fail_set( $post_id, true );
		return array( 'state' => 'blocked' );
	}
	$new_count = count( $passages );
	if ( $new_count < $old ) {
		$drop  = array();
		$index = $new_count;
		while ( $old > $index ) {
			$drop[] = skyphusion_ai_embed_passage_id( $post_id, $index );
			++$index;
		}
		$delete_url = skyphusion_ai_vectorize_url( $credentials['account_id'], (string) $config['index'], 'delete_by_ids' );
		$deleted    = skyphusion_ai_vectorize_request(
			'POST',
			$delete_url,
			$credentials['token'],
			(string) wp_json_encode( array( 'ids' => $drop ) ),
			'application/json'
		);
		if ( is_wp_error( $deleted ) ) {
			skyphusion_ai_embed_fail_set( $post_id, true );
			return array( 'state' => 'blocked' );
		}
	}
	skyphusion_ai_embed_hash_set( $post_id, $hash );
	skyphusion_ai_embed_part_set( $post_id, $new_count );
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
 * Posts in one counting step.
 *
 * Small on purpose. On a WordPress on Cloudflare store one admin request
 * has little memory left, so the screen never loads every post at once.
 *
 * @return int
 */
function skyphusion_ai_embed_count_size() {
	return 100;
}

/**
 * One page of the chosen posts, for the settings screen count.
 *
 * Oldest id first, so the pages do not overlap while nothing is saved.
 * The term cache is loaded only when the category and tag filter is on,
 * because only that filter reads terms.
 *
 * @param int $page Page number, from 1.
 * @param int $size Posts per page.
 * @return array<int, mixed>
 */
function skyphusion_ai_embed_collect_page( $page, $size ) {
	$page = max( 1, (int) $page );
	$size = max( 1, (int) $size );
	if ( isset( $GLOBALS['skyphusion_ai_embed_posts'] ) && is_array( $GLOBALS['skyphusion_ai_embed_posts'] ) ) {
		return array_slice( array_values( $GLOBALS['skyphusion_ai_embed_posts'] ), ( $page - 1 ) * $size, $size );
	}
	if ( ! function_exists( 'get_posts' ) ) {
		return array();
	}
	$config = skyphusion_ai_embed_config();
	$args   = skyphusion_ai_embed_query_args( $config );
	if ( array() === $args['post_type'] ) {
		return array();
	}
	$mode                           = isset( $config['tax_mode'] ) ? (string) $config['tax_mode'] : 'none';
	$args['numberposts']            = $size;
	$args['paged']                  = $page;
	$args['orderby']                = 'ID';
	$args['order']                  = 'ASC';
	$args['update_post_term_cache'] = ( 'include' === $mode || 'exclude' === $mode );
	$posts                          = get_posts( $args );
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
 * Option that keeps the last count.
 *
 * @return string
 */
function skyphusion_ai_embed_estimate_option() {
	return 'skyphusion_ai_embed_estimate';
}

/**
 * The last count, or null when the posts were never counted.
 *
 * @return array<string, int>|null
 */
function skyphusion_ai_embed_estimate_stored() {
	if ( ! function_exists( 'get_option' ) ) {
		return null;
	}
	$stored = get_option( skyphusion_ai_embed_estimate_option(), null );
	if ( ! is_array( $stored ) || ! isset( $stored['posts'], $stored['neurons'] ) ) {
		return null;
	}
	return $stored;
}

/**
 * Count one page and keep the running sum for this admin.
 *
 * Page 1 starts again from zero. A later page only adds to the sum when
 * the page before it was counted, so a lost step cannot count twice.
 * The last page stores the estimate and clears the sum.
 *
 * @param int $page Page number, from 1.
 * @return array<string, mixed> state more, done, or restart.
 */
function skyphusion_ai_embed_count_step( $page ) {
	$page  = max( 1, (int) $page );
	$user  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$key   = 'skyphusion_ai_embed_count_' . $user;
	$tally = array(
		'posts'    => 0,
		'passages' => 0,
		'chars'    => 0,
	);
	if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) || ! function_exists( 'delete_transient' ) || ! function_exists( 'update_option' ) ) {
		return array( 'state' => 'restart' );
	}
	if ( 1 < $page ) {
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) || ! isset( $stored['page'] ) || (int) $stored['page'] !== $page - 1 ) {
			return array( 'state' => 'restart' );
		}
		foreach ( array_keys( $tally ) as $field ) {
			$tally[ $field ] = isset( $stored[ $field ] ) ? (int) $stored[ $field ] : 0;
		}
	}
	$config = skyphusion_ai_embed_config();
	$size   = skyphusion_ai_embed_count_size();
	$rows   = skyphusion_ai_embed_collect_page( $page, $size );
	$part   = skyphusion_ai_embed_estimate_tally( $rows, $config );
	foreach ( array_keys( $tally ) as $field ) {
		$tally[ $field ] += (int) $part[ $field ];
	}
	$seen = ( $page - 1 ) * $size + count( $rows );
	if ( count( $rows ) < $size ) {
		delete_transient( $key );
		$estimate         = skyphusion_ai_embed_estimate_from( $tally, $config );
		$estimate['time'] = time();
		update_option( skyphusion_ai_embed_estimate_option(), $estimate, false );
		return array(
			'state'    => 'done',
			'seen'     => $seen,
			'estimate' => $estimate,
		);
	}
	$tally['page'] = $page;
	set_transient( $key, $tally, 3600 );
	return array(
		'state' => 'more',
		'seen'  => $seen,
		'next'  => $page + 1,
	);
}

/**
 * The estimate as one plain sentence.
 *
 * @param array<string, mixed> $estimate Output of skyphusion_ai_embed_estimate_from().
 * @return string Plain text. The caller escapes it.
 */
function skyphusion_ai_embed_estimate_sentence( $estimate ) {
	/* translators: 1: number of posts, 2: estimated neurons, 3: passages, 4: days, 5: stored dimensions. */
	$sentence = __( 'This run would embed %1$d posts and %3$d passages. The estimate is %2$d neurons, about %4$d days, and %5$d stored dimensions. The model has not been called.', 'skyphusion-ai' );
	return sprintf(
		$sentence,
		isset( $estimate['posts'] ) ? (int) $estimate['posts'] : 0,
		isset( $estimate['neurons'] ) ? (int) $estimate['neurons'] : 0,
		isset( $estimate['passages'] ) ? (int) $estimate['passages'] : 0,
		isset( $estimate['days'] ) ? (int) $estimate['days'] : 0,
		isset( $estimate['stored'] ) ? (int) $estimate['stored'] : 0
	);
}

/**
 * Count one page for the screen. Called by the count button.
 *
 * @return void
 */
function skyphusion_ai_embed_handle_count() {
	if ( ! skyphusion_ai_embed_admin_allowed() ) {
		wp_send_json_error( null, 403 );
	}
	check_ajax_referer( 'skyphusion_ai_embed_count' );
	$page = 1;
	if ( isset( $_POST['page'] ) ) {
		$page = absint( wp_unslash( $_POST['page'] ) );
	}
	$step = skyphusion_ai_embed_count_step( $page );
	$out  = array(
		'state' => $step['state'],
		'seen'  => isset( $step['seen'] ) ? (int) $step['seen'] : 0,
		'next'  => isset( $step['next'] ) ? (int) $step['next'] : 0,
	);
	if ( 'done' === $step['state'] ) {
		$out['text'] = skyphusion_ai_embed_estimate_sentence( $step['estimate'] );
	}
	wp_send_json_success( $out );
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
	if ( 1 > $limit ) {
		$limit = 5;
	}
	if ( 20 < $limit ) {
		$limit = 20;
	}
	$ask = $limit + 15;
	if ( 50 < $ask ) {
		$ask = 50;
	}
	$credentials = skyphusion_ai_embed_credentials();
	$url         = skyphusion_ai_vectorize_url( $credentials['account_id'], (string) $config['index'], 'query' );
	$body        = wp_json_encode(
		array(
			'vector'         => $vectors[0],
			'topK'           => $ask,
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
	$seen = array();
	foreach ( $decoded['result']['matches'] as $match ) {
		if ( ! is_array( $match ) ) {
			continue;
		}
		$metadata = array();
		if ( isset( $match['metadata'] ) && is_array( $match['metadata'] ) ) {
			$metadata = $match['metadata'];
		}
		$matched = isset( $metadata['post_id'] ) ? (int) $metadata['post_id'] : 0;
		if ( 0 < $matched ) {
			if ( isset( $seen[ $matched ] ) ) {
				continue;
			}
			$seen[ $matched ] = true;
		}
		$title     = isset( $metadata['title'] ) ? (string) $metadata['title'] : '';
		$url_value = isset( $metadata['url'] ) ? skyphusion_ai_embed_public_url( (string) $metadata['url'] ) : '';
		$row       = array(
			'id'      => isset( $match['id'] ) ? (string) $match['id'] : '',
			'score'   => isset( $match['score'] ) ? (float) $match['score'] : 0,
			'post_id' => $matched,
			'title'   => $title,
			'url'     => $url_value,
		);
		if ( isset( $metadata['post_type'] ) ) {
			$row['post_type'] = (string) $metadata['post_type'];
		}
		if ( isset( $metadata['image'] ) ) {
			$row['image'] = (string) $metadata['image'];
		}
		if ( isset( $metadata['sku'] ) ) {
			$row['sku'] = (string) $metadata['sku'];
		}
		if ( isset( $metadata['stock_status'] ) ) {
			$row['stock_status'] = (string) $metadata['stock_status'];
		}
		if ( array_key_exists( 'count', $metadata ) ) {
			$row['count'] = $metadata['count'];
		} elseif ( array_key_exists( 'quantity', $metadata ) ) {
			$row['quantity'] = $metadata['quantity'];
		}
		if ( array_key_exists( 'managing_stock', $metadata ) ) {
			$row['managing_stock'] = $metadata['managing_stock'];
		}
		$rows[] = $row;
		if ( count( $rows ) >= $limit ) {
			break;
		}
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
	$estimate = skyphusion_ai_embed_estimate_stored();
	echo '<hr />';
	echo '<h2>' . esc_html__( 'Embeddings', 'skyphusion-ai' ) . '</h2>';
	echo '<p class="skyphusion-ai-embed-estimate" aria-live="polite">';
	if ( null === $estimate ) {
		echo esc_html__( 'The posts have not been counted. Press Count the posts to see what a run would embed.', 'skyphusion-ai' );
	} else {
		echo esc_html( skyphusion_ai_embed_estimate_sentence( $estimate ) );
	}
	echo '</p>';
	$nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'skyphusion_ai_embed_count' ) : '';
	/* translators: %d is the number of posts read so far. */
	$working = __( 'Counting. %d posts read so far.', 'skyphusion-ai' );
	echo '<p><button type="button" class="button skyphusion-ai-embed-count"';
	echo ' data-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"';
	echo ' data-nonce="' . esc_attr( $nonce ) . '"';
	echo ' data-working="' . esc_attr( $working ) . '"';
	echo ' data-failed="' . esc_attr( __( 'The count stopped. Press Count the posts to start again.', 'skyphusion-ai' ) ) . '">';
	echo esc_html__( 'Count the posts', 'skyphusion-ai' ) . '</button></p>';
	echo '<p>' . esc_html__( 'The count reads the posts in small steps, so a large store does not slow this screen. Count again after you change the choices below.', 'skyphusion-ai' ) . '</p>';
	echo '<p>' . esc_html__( 'Password-protected posts stay out. The embeddings limit does not stop the other tools.', 'skyphusion-ai' ) . '</p>';
	skyphusion_ai_embed_render_progress();
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
	);
	foreach ( $tax_labels as $mode => $label ) {
		echo '<option value="' . esc_attr( $mode ) . '"';
		if ( $mode === $config['tax_mode'] ) {
			echo ' selected="selected"';
		}
		echo '>' . esc_html( $label ) . '</option>';
	}
	$leave_out = 'exclude';
	echo '<option value="' . esc_attr( $leave_out ) . '"';
	if ( $leave_out === (string) $config['tax_mode'] ) {
		echo ' selected="selected"';
	}
	echo '>' . esc_html__( 'Leave these slugs out.', 'skyphusion-ai' ) . '</option>';
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
 * Run one step after the admin presses the button.
 *
 * The cron event runs the next steps until the pass is done.
 *
 * @return void
 */
function skyphusion_ai_embed_handle_run() {
	if ( ! skyphusion_ai_embed_admin_allowed() ) {
		return;
	}
	check_admin_referer( 'skyphusion_ai_embed_run' );
	skyphusion_ai_embed_step_now();
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
 * Status counts for the whole selection, one batch at a time.
 *
 * @param array<string, mixed> $config Saved choices.
 * @param int                  $batch  Batch size, or zero for the saved size.
 * @return array{embedded: int, waiting: int, failed: int, batches: int}
 */
function skyphusion_ai_embed_tally_all( $config, $batch = 0 ) {
	$sum            = array(
		'embedded' => 0,
		'waiting'  => 0,
		'failed'   => 0,
	);
	$walk           = skyphusion_ai_embed_each_batch(
		function ( $rows ) use ( &$sum, $config ) {
			$part = skyphusion_ai_embed_tally( $rows, $config );
			foreach ( array_keys( $sum ) as $field ) {
				$sum[ $field ] += (int) $part[ $field ];
			}
		},
		$batch
	);
	$sum['batches'] = $walk['batches'];
	return $sum;
}

/**
 * Estimate for the whole selection, one batch at a time. The model is not called.
 *
 * @param array<string, mixed> $config Saved choices.
 * @param int                  $batch  Batch size, or zero for the saved size.
 * @return array<string, int> The estimate, plus batches.
 */
function skyphusion_ai_embed_estimate_all( $config, $batch = 0 ) {
	$sum                 = array(
		'posts'    => 0,
		'passages' => 0,
		'chars'    => 0,
	);
	$walk                = skyphusion_ai_embed_each_batch(
		function ( $rows ) use ( &$sum, $config ) {
			$part = skyphusion_ai_embed_estimate_tally( $rows, $config );
			foreach ( array_keys( $sum ) as $field ) {
				$sum[ $field ] += (int) $part[ $field ];
			}
		},
		$batch
	);
	$estimate            = skyphusion_ai_embed_estimate_from( $sum, $config );
	$estimate['batches'] = $walk['batches'];
	return $estimate;
}

/**
 * Reconcile the whole selection, one batch at a time.
 *
 * @param int $batch Batch size, or zero for the saved size.
 * @return array<string, mixed>
 */
function skyphusion_ai_embed_reconcile_all( $batch = 0 ) {
	if ( ! skyphusion_ai_embed_on( skyphusion_ai_embed_config() ) ) {
		return skyphusion_ai_embed_reconcile( array() );
	}
	$sum            = array(
		'state'   => 'reconciled',
		'removed' => 0,
		'waiting' => 0,
	);
	$walk           = skyphusion_ai_embed_each_batch(
		function ( $rows ) use ( &$sum ) {
			$part            = skyphusion_ai_embed_reconcile( $rows );
			$sum['removed'] += (int) $part['removed'];
			$sum['waiting'] += (int) $part['waiting'];
		},
		$batch
	);
	$sum['batches'] = $walk['batches'];
	return $sum;
}

/**
 * Retry failed posts in the whole selection, one batch at a time.
 *
 * The daily limit stops the walk. No later batch is loaded.
 *
 * @param int $batch Batch size, or zero for the saved size.
 * @return array{state: string, retried: int, batches?: int}
 */
function skyphusion_ai_embed_retry_all( $batch = 0 ) {
	if ( ! skyphusion_ai_embed_on( skyphusion_ai_embed_config() ) ) {
		return skyphusion_ai_embed_retry( array() );
	}
	$sum            = array(
		'state'   => 'retried',
		'retried' => 0,
	);
	$walk           = skyphusion_ai_embed_each_batch(
		function ( $rows ) use ( &$sum ) {
			$part            = skyphusion_ai_embed_retry( $rows );
			$sum['retried'] += (int) $part['retried'];
			if ( 'limit' === $part['state'] ) {
				$sum['state'] = 'limit';
				return false;
			}
			return true;
		},
		$batch
	);
	$sum['batches'] = $walk['batches'];
	return $sum;
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
 * The daily embeddings limit stops the retry on that post, as it stops
 * a run step. The state is then limit. The post stays failed, so the
 * next retry sends it.
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
		if ( 'limit' === $result['state'] ) {
			return array(
				'state'   => 'limit',
				'retried' => $retried,
			);
		}
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
	$check = skyphusion_ai_embed_move_check( $destination );
	if ( '' !== $check['state'] ) {
		return array(
			'state' => $check['state'],
			'moved' => 0,
		);
	}
	$created = false;
	return skyphusion_ai_embed_move_batch( $check['target'], $posts, $created );
}

/**
 * Copy stored vectors for the whole selection, one batch at a time.
 *
 * The destination is created once, before the first batch that has
 * vectors. A batch that cannot be read or written stops the copy.
 *
 * @param string $destination Index name.
 * @param int    $batch       Batch size, or zero for the saved size.
 * @return array{state: string, moved: int, batches?: int}
 */
function skyphusion_ai_embed_move_all( $destination, $batch = 0 ) {
	$check = skyphusion_ai_embed_move_check( $destination );
	if ( '' !== $check['state'] ) {
		return array(
			'state' => $check['state'],
			'moved' => 0,
		);
	}
	$target         = $check['target'];
	$created        = false;
	$sum            = array(
		'state' => 'copied',
		'moved' => 0,
	);
	$walk           = skyphusion_ai_embed_each_batch(
		function ( $rows ) use ( &$sum, &$created, $target ) {
			$part          = skyphusion_ai_embed_move_batch( $target, $rows, $created );
			$sum['moved'] += (int) $part['moved'];
			if ( 'copied' !== $part['state'] ) {
				$sum['state'] = $part['state'];
				return false;
			}
			return true;
		},
		$batch
	);
	$sum['batches'] = $walk['batches'];
	return $sum;
}

/**
 * Check a move destination.
 *
 * @param string $destination Index name.
 * @return array{state: string, target: string} An empty state means the move may go on.
 */
function skyphusion_ai_embed_move_check( $destination ) {
	$target = skyphusion_ai_embed_index_name( $destination );
	$config = skyphusion_ai_embed_config();
	if ( '' === $target || $target === (string) $config['index'] ) {
		return array(
			'state'  => 'blocked',
			'target' => '',
		);
	}
	if ( empty( $config['created'] ) ) {
		return array(
			'state'  => 'not_created',
			'target' => '',
		);
	}
	return array(
		'state'  => '',
		'target' => $target,
	);
}

/**
 * Copy the stored vectors of one batch of posts.
 *
 * @param string            $target  Checked destination index.
 * @param array<int, mixed> $posts   Posts whose vectors are copied.
 * @param bool              $created True once the destination create was sent. Set here.
 * @return array{state: string, moved: int}
 */
function skyphusion_ai_embed_move_batch( $target, $posts, &$created ) {
	$config = skyphusion_ai_embed_config();
	$ids    = array();
	foreach ( $posts as $post ) {
		if ( ! is_array( $post ) ) {
			continue;
		}
		$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		if ( '' === skyphusion_ai_embed_hash_get( $post_id ) ) {
			continue;
		}
		foreach ( skyphusion_ai_embed_passage_ids( $post_id, skyphusion_ai_embed_part_count( $post_id ) ) as $vector_id ) {
			$ids[] = $vector_id;
		}
	}
	if ( array() === $ids ) {
		return array(
			'state' => 'copied',
			'moved' => 0,
		);
	}
	$credentials = skyphusion_ai_embed_credentials();
	$model       = isset( $config['model'] ) ? (string) $config['model'] : '';
	$create      = $created ? null : skyphusion_ai_embed_create_body( $target, $model );
	$created     = true;
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
		skyphusion_ai_embed_cli_estimate( $assoc_args );
		return;
	}
	if ( 'run' === $command || 'reconcile' === $command || 'rebuild' === $command ) {
		skyphusion_ai_embed_cli_job( $command, $assoc_args );
		return;
	}
	if ( 'retry' === $command ) {
		skyphusion_ai_embed_cli_retry( $assoc_args );
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
	$tally  = skyphusion_ai_embed_tally_all( $config, skyphusion_ai_embed_cli_batch( $assoc_args ) );
	WP_CLI::log( 'embed_index=' . $config['index'] );
	WP_CLI::log( 'embed_created=' . (string) $config['created'] );
	WP_CLI::log( 'embed_model=' . (string) $config['model'] );
	WP_CLI::log( 'embed_on=' . ( skyphusion_ai_embed_on( $config ) ? '1' : '0' ) );
	WP_CLI::log( 'embed_embedded=' . (string) $tally['embedded'] );
	WP_CLI::log( 'embed_waiting=' . (string) $tally['waiting'] );
	WP_CLI::log( 'embed_failed=' . (string) $tally['failed'] );
	WP_CLI::log( 'embed_batches=' . (string) $tally['batches'] );
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
 * Batch size from the --batch flag, or zero for the saved size.
 *
 * @param array<string, string> $assoc_args Flags.
 * @return int
 */
function skyphusion_ai_embed_cli_batch( $assoc_args ) {
	if ( ! isset( $assoc_args['batch'] ) ) {
		return 0;
	}
	return skyphusion_ai_embed_batch_size( $assoc_args['batch'] );
}

/**
 * Print the estimate. The model is not called.
 *
 * @param array<string, string> $assoc_args Flags.
 * @return void
 */
function skyphusion_ai_embed_cli_estimate( $assoc_args = array() ) {
	$estimate = skyphusion_ai_embed_estimate_all( skyphusion_ai_embed_config(), skyphusion_ai_embed_cli_batch( $assoc_args ) );
	WP_CLI::log( 'embed_posts=' . (string) $estimate['posts'] );
	WP_CLI::log( 'embed_neurons=' . (string) $estimate['neurons'] );
	WP_CLI::log( 'embed_passages=' . (string) $estimate['passages'] );
	WP_CLI::log( 'embed_days=' . (string) $estimate['days'] );
	WP_CLI::log( 'embed_stored=' . (string) $estimate['stored'] );
	WP_CLI::log( 'embed_batches=' . (string) $estimate['batches'] );
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
 * Run and rebuild go one step at a time. --batch=N sets the step size.
 *
 * @param string                $command    Job name.
 * @param array<string, string> $assoc_args Flags.
 * @return void
 */
function skyphusion_ai_embed_cli_job( $command, $assoc_args = array() ) {
	if ( ! skyphusion_ai_embed_on( skyphusion_ai_embed_config() ) ) {
		skyphusion_ai_embed_cli_refuse();
		return;
	}
	$batch = skyphusion_ai_embed_cli_batch( $assoc_args );
	if ( 'reconcile' === $command ) {
		$result = skyphusion_ai_embed_reconcile_all( $batch );
		WP_CLI::log( 'embed_state=' . $result['state'] );
		WP_CLI::log( 'embed_removed=' . (string) $result['removed'] );
		WP_CLI::log( 'embed_waiting=' . (string) $result['waiting'] );
		WP_CLI::log( 'embed_batches=' . (string) ( $result['batches'] ?? 0 ) );
		WP_CLI::log( 'embed_called=0' );
		return;
	}
	if ( 'rebuild' === $command ) {
		foreach ( skyphusion_ai_embed_ids() as $post_id ) {
			skyphusion_ai_embed_hash_set( $post_id, '' );
			skyphusion_ai_embed_fail_set( $post_id, false );
		}
		skyphusion_ai_embed_cursor_start( $batch );
	}
	skyphusion_ai_embed_cli_steps( $batch );
}

/**
 * Run steps until the pass is done or stops.
 *
 * Each step prints one line. The last line is embed_state=ran when the
 * pass is done.
 *
 * @param int $batch Batch size, or zero for the saved size.
 * @return void
 */
function skyphusion_ai_embed_cli_steps( $batch ) {
	$cursor = skyphusion_ai_embed_cursor();
	if ( empty( $cursor['active'] ) ) {
		skyphusion_ai_embed_cursor_start( $batch );
	}
	$guard = 0;
	do {
		++$guard;
		$result = skyphusion_ai_embed_step( $batch );
		WP_CLI::log( 'embed_step=' . (string) $guard . ' processed=' . (string) $result['processed'] . ' embedded=' . (string) $result['embedded'] . ' remaining=' . (string) $result['remaining'] );
	} while ( 'step' === $result['state'] && 100000 > $guard );
	if ( 'done' === $result['state'] ) {
		WP_CLI::log( 'embed_state=ran' );
		return;
	}
	skyphusion_ai_embed_schedule( $result['state'] );
	WP_CLI::log( 'embed_state=' . $result['state'] );
}

/**
 * Try failed posts again. This stops while embeddings are off.
 *
 * @param array<string, string> $assoc_args Flags.
 * @return void
 */
function skyphusion_ai_embed_cli_retry( $assoc_args = array() ) {
	if ( ! skyphusion_ai_embed_on( skyphusion_ai_embed_config() ) ) {
		skyphusion_ai_embed_cli_refuse();
		return;
	}
	$result = skyphusion_ai_embed_retry_all( skyphusion_ai_embed_cli_batch( $assoc_args ) );
	WP_CLI::log( 'embed_state=' . $result['state'] );
	WP_CLI::log( 'embed_retried=' . (string) $result['retried'] );
	WP_CLI::log( 'embed_batches=' . (string) ( $result['batches'] ?? 0 ) );
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
	$result = skyphusion_ai_embed_move_all( $destination, skyphusion_ai_embed_cli_batch( $assoc_args ) );
	WP_CLI::log( 'embed_state=' . $result['state'] );
	WP_CLI::log( 'embed_moved=' . (string) $result['moved'] );
	WP_CLI::log( 'embed_batches=' . (string) ( $result['batches'] ?? 0 ) );
	WP_CLI::log( 'embed_called=0' );
}

add_action( 'save_post', 'skyphusion_ai_embed_on_save', 20, 1 );
add_action( 'before_delete_post', 'skyphusion_ai_embed_on_delete', 10, 1 );
add_action( 'admin_post_skyphusion_ai_embed_save', 'skyphusion_ai_embed_handle_save' );
add_action( 'admin_post_skyphusion_ai_embed_run', 'skyphusion_ai_embed_handle_run' );
add_action( 'admin_post_skyphusion_ai_embed_delete', 'skyphusion_ai_embed_handle_delete' );
add_action( 'wp_ajax_skyphusion_ai_embed_count', 'skyphusion_ai_embed_handle_count' );

require_once __DIR__ . '/embed-step.php';
require_once __DIR__ . '/related.php';
