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
 * Names stored on one vector, or on one post.
 *
 * @param mixed $raw A string, a list, or anything else.
 * @return array<int, string>
 */
function skyphusion_ai_related_term_list( $raw ) {
	$parts = array();
	if ( is_array( $raw ) ) {
		$parts = $raw;
	} elseif ( is_string( $raw ) ) {
		$parts = explode( ',', $raw );
	}
	$names = array();
	foreach ( $parts as $name ) {
		$name = trim( (string) $name );
		if ( '' === $name || in_array( $name, $names, true ) ) {
			continue;
		}
		$names[] = $name;
		if ( count( $names ) >= 8 ) {
			break;
		}
	}
	return $names;
}

/**
 * Categories and tags already on the post array.
 *
 * @param array<string, mixed> $post One post.
 * @return array<int, string>
 */
function skyphusion_ai_related_post_terms( $post ) {
	$names = array();
	foreach ( array( 'categories', 'tags' ) as $bucket ) {
		if ( ! isset( $post[ $bucket ] ) || ! is_array( $post[ $bucket ] ) ) {
			continue;
		}
		foreach ( skyphusion_ai_related_term_list( $post[ $bucket ] ) as $name ) {
			if ( in_array( $name, $names, true ) ) {
				continue;
			}
			$names[] = $name;
		}
	}
	return $names;
}

/**
 * Terms that both stored records share. The match order is kept.
 *
 * @param array<int, string> $own    Terms for the current page.
 * @param array<int, string> $theirs Terms for the neighbour.
 * @return array<int, string>
 */
function skyphusion_ai_related_shared( $own, $theirs ) {
	$shared = array();
	foreach ( $theirs as $name ) {
		if ( ! in_array( $name, $own, true ) || in_array( $name, $shared, true ) ) {
			continue;
		}
		$shared[] = $name;
		if ( count( $shared ) >= 3 ) {
			break;
		}
	}
	return $shared;
}

/**
 * Why this neighbour matches. The line uses stored terms or the stored score.
 *
 * It does not call a model.
 *
 * @param array<string, mixed> $metadata Neighbour metadata.
 * @param float|null           $score    Stored score, or null when the row has none.
 * @param array<int, string>   $own      Terms for the current page.
 * @return string
 */
function skyphusion_ai_related_why( $metadata, $score, $own ) {
	$terms = array();
	if ( isset( $metadata['terms'] ) ) {
		$terms = skyphusion_ai_related_term_list( $metadata['terms'] );
	}
	$shared = skyphusion_ai_related_shared( $own, $terms );
	$count  = count( $shared );
	if ( 1 === $count ) {
		/* translators: %s: one shared category or tag. */
		return sprintf( __( 'Also about %s.', 'skyphusion-ai' ), $shared[0] );
	}
	if ( 2 === $count ) {
		/* translators: 1: first shared term, 2: second shared term. */
		return sprintf( __( 'Also about %1$s and %2$s.', 'skyphusion-ai' ), $shared[0], $shared[1] );
	}
	if ( 3 <= $count ) {
		$last = (string) array_pop( $shared );
		/* translators: 1: shared terms before the last, 2: last shared term. */
		return sprintf( __( 'Also about %1$s, and %2$s.', 'skyphusion-ai' ), implode( ', ', $shared ), $last );
	}
	if ( is_numeric( $score ) ) {
		$percent = (int) round( (float) $score * 100 );
		if ( 0 > $percent ) {
			$percent = 0;
		}
		if ( 100 < $percent ) {
			$percent = 100;
		}
		/* translators: %d: how close the stored vectors are, as a whole percent. */
		return sprintf( __( '%d%% the same subject.', 'skyphusion-ai' ), $percent );
	}
	return __( 'Close in subject.', 'skyphusion-ai' );
}

/**
 * Read one stored vector and its metadata.
 *
 * An empty values list means this post is not in the index.
 *
 * @param int                   $post_id     Post id.
 * @param array<string, mixed>  $config      Saved choices.
 * @param array<string, string> $credentials Account id and token.
 * @return array{values: array<int, float>, metadata: array<string, mixed>}
 */
function skyphusion_ai_related_stored( $post_id, $config, $credentials ) {
	$empty = array(
		'values'   => array(),
		'metadata' => array(),
	);
	$url   = skyphusion_ai_vectorize_url( $credentials['account_id'], (string) $config['index'], 'get_by_ids' );
	$body  = wp_json_encode( array( 'ids' => array( skyphusion_ai_embed_vector_id( $post_id ) ) ) );
	$read  = skyphusion_ai_vectorize_request( 'POST', $url, $credentials['token'], (string) $body, 'application/json' );
	if ( is_wp_error( $read ) ) {
		return $empty;
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
				return $empty;
			}
			$values[] = (float) $number;
		}
		$metadata = array();
		if ( isset( $row['metadata'] ) && is_array( $row['metadata'] ) ) {
			$metadata = $row['metadata'];
		}
		return array(
			'values'   => $values,
			'metadata' => $metadata,
		);
	}
	return $empty;
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
	$stored = skyphusion_ai_related_stored( $post_id, $config, $credentials );
	return $stored['values'];
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
	$stored      = skyphusion_ai_related_stored( $post_id, $config, $credentials );
	$vector      = $stored['values'];
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
	$own   = skyphusion_ai_embed_vector_id( $post_id );
	$rows  = array();
	$terms = skyphusion_ai_related_term_list( isset( $stored['metadata']['terms'] ) ? $stored['metadata']['terms'] : array() );
	foreach ( skyphusion_ai_related_post_terms( $post ) as $name ) {
		if ( ! in_array( $name, $terms, true ) ) {
			$terms[] = $name;
		}
	}
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
		$title = isset( $metadata['title'] ) ? (string) $metadata['title'] : '';
		$link  = isset( $metadata['url'] ) ? skyphusion_ai_embed_public_url( (string) $metadata['url'] ) : '';
		$score = null;
		if ( isset( $match['score'] ) && is_numeric( $match['score'] ) ) {
			$score = (float) $match['score'];
		}
		$rows[] = array(
			'id'     => $id,
			'title'  => $title,
			'url'    => $link,
			'reason' => skyphusion_ai_related_why( $metadata, $score, $terms ),
			'score'  => null === $score ? 0 : $score,
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
	$config = skyphusion_ai_embed_config();
	$show   = ! empty( $config['related_why'] );
	$html   = '<section class="skyphusion-ai-related"><ul>';
	foreach ( $rows as $row ) {
		$html .= '<li>';
		if ( '' !== $row['url'] ) {
			$html .= '<a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['title'] ) . '</a>';
		} else {
			$html .= esc_html( $row['title'] );
		}
		if ( $show && isset( $row['reason'] ) && '' !== (string) $row['reason'] ) {
			$html .= ' <span class="skyphusion-ai-related-why">' . esc_html( (string) $row['reason'] ) . '</span>';
		}
		$html .= '</li>';
	}
	$html .= '</ul></section>';
	return $html;
}

/**
 * Put the list after the content, before it, or after one paragraph.
 *
 * A missing paragraph puts the list at the end.
 *
 * @param string               $content Page body.
 * @param string               $html    Related list.
 * @param array<string, mixed> $config  Saved choices.
 * @return string
 */
function skyphusion_ai_related_place( $content, $html, $config ) {
	$place = 'after';
	if ( isset( $config['related_place'] ) && in_array( (string) $config['related_place'], array( 'after', 'before', 'paragraph' ), true ) ) {
		$place = (string) $config['related_place'];
	}
	if ( 'before' === $place ) {
		return $html . $content;
	}
	if ( 'paragraph' === $place ) {
		$index = 1;
		if ( isset( $config['related_paragraph'] ) && is_numeric( $config['related_paragraph'] ) ) {
			$index = (int) $config['related_paragraph'];
		}
		if ( 1 > $index ) {
			$index = 1;
		}
		$parts = preg_split( '/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( is_array( $parts ) && 1 < count( $parts ) ) {
			$seen  = 0;
			$out   = '';
			$count = count( $parts );
			for ( $i = 0; $i < $count; $i++ ) {
				$out .= $parts[ $i ];
				if ( '</p>' !== strtolower( (string) $parts[ $i ] ) ) {
					continue;
				}
				++$seen;
				if ( $index !== $seen ) {
					continue;
				}
				$rest = '';
				for ( $next = $i + 1; $next < $count; $next++ ) {
					$rest .= $parts[ $next ];
				}
				return $out . $html . $rest;
			}
		}
	}
	return $content . $html;
}

/**
 * Place the list when the switch is on. The shortcode is left as it is.
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
	$html = skyphusion_ai_related_html( $post );
	if ( '' === $html ) {
		return $content;
	}
	return skyphusion_ai_related_place( $content, $html, $config );
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

/**
 * Post meta that holds one saved related list.
 *
 * @return string
 */
function skyphusion_ai_related_list_key() {
	return 'skyphusion_ai_related_list';
}

/**
 * Build related lists now and store them. The embeddings model is not called.
 *
 * @param array<int, mixed> $posts Posts to refresh.
 * @return array{state: string, saved: int}
 */
function skyphusion_ai_related_refresh( $posts ) {
	$saved = 0;
	foreach ( $posts as $post ) {
		if ( ! is_array( $post ) ) {
			continue;
		}
		$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		$rows    = skyphusion_ai_related_rows( $post );
		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $post_id, skyphusion_ai_related_list_key(), $rows );
		}
		++$saved;
	}
	return array(
		'state' => 'refreshed',
		'saved' => $saved,
	);
}

/**
 * Saved list, or null when this post has not been refreshed.
 *
 * @param int $post_id Post id.
 * @return array<int, array<string, mixed>>|null
 */
function skyphusion_ai_related_saved( $post_id ) {
	if ( ! function_exists( 'get_post_meta' ) ) {
		return null;
	}
	$value = get_post_meta( (int) $post_id, skyphusion_ai_related_list_key(), true );
	if ( ! is_array( $value ) ) {
		return null;
	}
	return $value;
}

/**
 * WP-CLI for related refresh and related show.
 *
 * @param array<int, string>    $args       Positional arguments.
 * @param array<string, string> $assoc_args Flags.
 * @return void
 */
function skyphusion_ai_related_cli( $args, $assoc_args ) {
	if ( ! class_exists( 'WP_CLI' ) ) {
		return;
	}
	$command = '';
	if ( isset( $args[0] ) ) {
		$command = (string) $args[0];
	}
	if ( 'refresh' === $command ) {
		$posts = skyphusion_ai_embed_collect();
		$one   = 0;
		if ( ! isset( $assoc_args['all'] ) && isset( $args[1] ) ) {
			$one = (int) $args[1];
		}
		if ( $one > 0 ) {
			$post  = skyphusion_ai_embed_load( $one );
			$posts = is_array( $post ) ? array( $post ) : array();
		}
		$result = skyphusion_ai_related_refresh( $posts );
		WP_CLI::log( 'related_state=' . $result['state'] );
		WP_CLI::log( 'related_saved=' . (string) $result['saved'] );
		WP_CLI::log( 'embed_called=0' );
		return;
	}
	if ( 'show' === $command ) {
		$post_id = isset( $args[1] ) ? (int) $args[1] : 0;
		$saved   = skyphusion_ai_related_saved( $post_id );
		$rows    = $saved;
		if ( null === $rows ) {
			$post = skyphusion_ai_embed_load( $post_id );
			$rows = is_array( $post ) ? skyphusion_ai_related_rows( $post ) : array();
		}
		if ( ! is_array( $rows ) || array() === $rows ) {
			WP_CLI::log( 'related_state=empty' );
			return;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id     = isset( $row['id'] ) ? (string) $row['id'] : '';
			$score  = isset( $row['score'] ) ? (string) $row['score'] : '0';
			$title  = isset( $row['title'] ) ? (string) $row['title'] : '';
			$reason = isset( $row['reason'] ) ? (string) $row['reason'] : '';
			WP_CLI::log( $id . ' ' . $score . ' ' . $title . ' ' . $reason );
		}
		return;
	}
	WP_CLI::log( 'related_state=unknown' );
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'skyphusion-ai related', 'skyphusion_ai_related_cli' );
}
