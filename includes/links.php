<?php
/**
 * Suggest internal links from a phrase that is already in the post.
 *
 * The screen only suggests. It does not change the post. It also lists
 * pages that nothing links to.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * True when the phrase is in the post text.
 *
 * A phrase shorter than three characters is ignored.
 *
 * @param string $phrase  Candidate phrase.
 * @param string $content Post text.
 * @return bool
 */
function skyphusion_ai_link_phrase_in( $phrase, $content ) {
	$phrase = trim( (string) $phrase );
	if ( function_exists( 'mb_strlen' ) ) {
		$length = mb_strlen( $phrase );
	} else {
		$length = strlen( $phrase );
	}
	if ( 3 > $length ) {
		return false;
	}
	$haystack = strtolower( (string) $content );
	$needle   = strtolower( $phrase );
	return false !== strpos( $haystack, $needle );
}

/**
 * True when the phrase is a whole word, and not inside an email.
 *
 * @param string $phrase  Candidate phrase.
 * @param string $content Post text.
 * @return bool
 */
function skyphusion_ai_link_phrase_word( $phrase, $content ) {
	$phrase = trim( (string) $phrase );
	if ( ! skyphusion_ai_link_phrase_in( $phrase, $content ) ) {
		return false;
	}
	$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/iu';
	$found   = preg_match_all( $pattern, (string) $content, $matches, PREG_OFFSET_CAPTURE );
	if ( ! is_int( $found ) || 1 > $found ) {
		return false;
	}
	foreach ( $matches[0] as $hit ) {
		$offset = (int) $hit[1];
		$length = strlen( (string) $hit[0] );
		$before = substr( (string) $content, 0, $offset );
		$start  = strrpos( $before, ' ' );
		$start  = false === $start ? 0 : $start + 1;
		$after  = strpos( (string) $content, ' ', $offset + $length );
		$end    = false === $after ? strlen( (string) $content ) : $after;
		$token  = substr( (string) $content, $start, $end - $start );
		if ( false === strpos( $token, '@' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Sentence that already contains the phrase.
 *
 * @param string $phrase  Candidate phrase.
 * @param string $content Post text.
 * @return string
 */
function skyphusion_ai_link_sentence( $phrase, $content ) {
	$text = preg_replace( '/\s+/', ' ', trim( (string) $content ) );
	if ( ! is_string( $text ) || '' === $text ) {
		return '';
	}
	$parts = preg_split( '/(?<=[.!?])\s+/', $text );
	if ( ! is_array( $parts ) ) {
		return '';
	}
	foreach ( $parts as $part ) {
		if ( ! skyphusion_ai_link_phrase_word( $phrase, $part ) ) {
			continue;
		}
		if ( 240 < strlen( $part ) ) {
			return substr( $part, 0, 240 );
		}
		return $part;
	}
	return '';
}

/**
 * True when the content already links to this address.
 *
 * A bare slash is not a link. It would match every page.
 *
 * @param string $url     Page address.
 * @param string $content Post text.
 * @return bool
 */
function skyphusion_ai_link_url_in( $url, $content ) {
	$url = trim( (string) $url );
	if ( 8 > strlen( $url ) ) {
		return false;
	}
	if ( false !== strpos( (string) $content, $url ) ) {
		return true;
	}
	$path = '';
	if ( function_exists( 'wp_parse_url' ) ) {
		$parts = wp_parse_url( $url );
		if ( is_array( $parts ) && isset( $parts['path'] ) ) {
			$path = (string) $parts['path'];
		}
	}
	if ( 2 > strlen( $path ) || '/' === $path ) {
		return false;
	}
	return false !== strpos( (string) $content, $path );
}

/**
 * Post id from a related row. The vector id is p plus the post id.
 *
 * @param array<string, mixed> $row Related row.
 * @return int
 */
function skyphusion_ai_link_row_post_id( $row ) {
	if ( isset( $row['post_id'] ) ) {
		return (int) $row['post_id'];
	}
	$id = isset( $row['id'] ) ? (string) $row['id'] : '';
	if ( preg_match( '/^p(\d+)$/', $id, $match ) ) {
		return (int) $match[1];
	}
	return 0;
}

/**
 * Suggestions whose phrase is already in the post.
 *
 * @param string                           $content Post text.
 * @param array<int, array<string, mixed>> $rows  Neighbour rows.
 * @param int                              $post_id Current post id.
 * @return array<int, array{phrase: string, title: string, url: string, post_id: int}>
 */
function skyphusion_ai_link_suggestions( $content, $rows, $post_id ) {
	$found = array();
	if ( ! is_array( $rows ) ) {
		return $found;
	}
	$post_id = (int) $post_id;
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$title = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
		$other = skyphusion_ai_link_row_post_id( $row );
		if ( $other > 0 && $other === $post_id ) {
			continue;
		}
		if ( ! skyphusion_ai_link_phrase_in( $title, $content ) ) {
			continue;
		}
		$url     = isset( $row['url'] ) ? (string) $row['url'] : '';
		$found[] = array(
			'phrase'  => $title,
			'title'   => $title,
			'url'     => $url,
			'post_id' => $other,
		);
		if ( count( $found ) >= 5 ) {
			break;
		}
	}
	return $found;
}

/**
 * Pages whose id is not in the linked set.
 *
 * @param array<int, array<string, mixed>> $posts  Pages to check.
 * @param array<int, int>                  $linked Ids that already have a link.
 * @return array<int, array{post_id: int, title: string, url: string}>
 */
function skyphusion_ai_link_orphans( $posts, $linked ) {
	$out = array();
	if ( ! is_array( $posts ) ) {
		return $out;
	}
	$have = array();
	if ( is_array( $linked ) ) {
		foreach ( $linked as $id ) {
			$have[ (int) $id ] = true;
		}
	}
	foreach ( $posts as $post ) {
		if ( ! is_array( $post ) ) {
			continue;
		}
		$post_id = isset( $post['post_id'] ) ? (int) $post['post_id'] : 0;
		if ( 1 > $post_id || isset( $have[ $post_id ] ) ) {
			continue;
		}
		$out[] = array(
			'post_id' => $post_id,
			'title'   => isset( $post['title'] ) ? (string) $post['title'] : '',
			'url'     => isset( $post['url'] ) ? (string) $post['url'] : '',
		);
		if ( count( $out ) >= 20 ) {
			break;
		}
	}
	return $out;
}

/**
 * Neighbour rows. The test stand-in is $GLOBALS['skyphusion_ai_link_rows'].
 *
 * @param array<string, mixed> $post Post fields.
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_link_rows( $post ) {
	if ( isset( $GLOBALS['skyphusion_ai_link_rows'] ) && is_array( $GLOBALS['skyphusion_ai_link_rows'] ) ) {
		return $GLOBALS['skyphusion_ai_link_rows'];
	}
	if ( function_exists( 'skyphusion_ai_related_rows' ) ) {
		return skyphusion_ai_related_rows( $post );
	}
	return array();
}

/**
 * Post text. The test stand-in is $GLOBALS['skyphusion_ai_link_content'].
 *
 * @param int $post_id Post id.
 * @return string
 */
function skyphusion_ai_link_content( $post_id ) {
	if ( isset( $GLOBALS['skyphusion_ai_link_content'] ) && is_string( $GLOBALS['skyphusion_ai_link_content'] ) ) {
		return $GLOBALS['skyphusion_ai_link_content'];
	}
	if ( ! function_exists( 'get_post' ) ) {
		return '';
	}
	$post = get_post( (int) $post_id );
	if ( is_object( $post ) && isset( $post->post_content ) ) {
		return (string) $post->post_content;
	}
	if ( is_array( $post ) && isset( $post['post_content'] ) ) {
		return (string) $post['post_content'];
	}
	return '';
}

/**
 * Return links whose phrase is already in the other page.
 *
 * The current page is skipped. Nothing is written.
 *
 * @param string                           $title   Current title.
 * @param array<int, array<string, mixed>> $posts   Published pages.
 * @param int                              $post_id Current post id.
 * @return array<int, array{phrase: string, title: string, url: string, post_id: int, sentence: string}>
 */
function skyphusion_ai_link_returns( $title, $posts, $post_id ) {
	$found   = array();
	$title   = trim( (string) $title );
	$post_id = (int) $post_id;
	if ( ! is_array( $posts ) ) {
		return $found;
	}
	foreach ( $posts as $post ) {
		if ( ! is_array( $post ) ) {
			continue;
		}
		$other = isset( $post['post_id'] ) ? (int) $post['post_id'] : 0;
		if ( 1 > $other || $other === $post_id ) {
			continue;
		}
		$content = isset( $post['content'] ) ? (string) $post['content'] : '';
		if ( ! skyphusion_ai_link_phrase_word( $title, $content ) ) {
			continue;
		}
		$found[] = array(
			'phrase'   => $title,
			'title'    => isset( $post['title'] ) ? (string) $post['title'] : '',
			'url'      => isset( $post['url'] ) ? (string) $post['url'] : '',
			'post_id'  => $other,
			'sentence' => skyphusion_ai_link_sentence( $title, $content ),
		);
		if ( count( $found ) >= 5 ) {
			break;
		}
	}
	return $found;
}

/**
 * Ids that another page already links to.
 *
 * @param array<int, array<string, mixed>> $posts Published pages.
 * @return array<int, int>
 */
function skyphusion_ai_link_inbound_ids( $posts ) {
	$ids = array();
	if ( ! is_array( $posts ) ) {
		return $ids;
	}
	foreach ( $posts as $post ) {
		if ( ! is_array( $post ) ) {
			continue;
		}
		$from    = isset( $post['post_id'] ) ? (int) $post['post_id'] : 0;
		$content = isset( $post['content'] ) ? (string) $post['content'] : '';
		if ( '' === $content ) {
			continue;
		}
		foreach ( $posts as $other ) {
			if ( ! is_array( $other ) ) {
				continue;
			}
			$to = isset( $other['post_id'] ) ? (int) $other['post_id'] : 0;
			if ( 1 > $to || $to === $from ) {
				continue;
			}
			$url = isset( $other['url'] ) ? (string) $other['url'] : '';
			if ( skyphusion_ai_link_url_in( $url, $content ) ) {
				$ids[ $to ] = $to;
			}
		}
	}
	return array_values( $ids );
}

/**
 * Rows the editor can scan. A password-protected page stays out.
 *
 * @param array<int, mixed> $posts Raw posts.
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_link_public_rows( $posts ) {
	$rows = array();
	if ( ! is_array( $posts ) ) {
		return $rows;
	}
	foreach ( $posts as $post ) {
		if ( is_object( $post ) ) {
			$post = get_object_vars( $post );
		}
		if ( ! is_array( $post ) ) {
			continue;
		}
		$password = isset( $post['post_password'] ) ? (string) $post['post_password'] : '';
		if ( '' !== $password ) {
			continue;
		}
		$status = isset( $post['post_status'] ) ? (string) $post['post_status'] : '';
		$type   = isset( $post['post_type'] ) ? (string) $post['post_type'] : '';
		if ( 'publish' !== $status || ( 'post' !== $type && 'page' !== $type ) ) {
			continue;
		}
		$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		if ( 1 > $post_id ) {
			continue;
		}
		$url = '';
		if ( function_exists( 'get_permalink' ) ) {
			$url = (string) get_permalink( $post_id );
		} elseif ( isset( $post['url'] ) ) {
			$url = (string) $post['url'];
		}
		$rows[] = array(
			'post_id' => $post_id,
			'title'   => isset( $post['post_title'] ) ? (string) $post['post_title'] : '',
			'url'     => $url,
			'content' => isset( $post['post_content'] ) ? (string) $post['post_content'] : '',
		);
	}
	return $rows;
}

/**
 * Pages to check for inbound links. The test stand-in is $GLOBALS['skyphusion_ai_link_posts'].
 *
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_link_posts() {
	if ( isset( $GLOBALS['skyphusion_ai_link_posts'] ) && is_array( $GLOBALS['skyphusion_ai_link_posts'] ) ) {
		return $GLOBALS['skyphusion_ai_link_posts'];
	}
	if ( ! function_exists( 'get_posts' ) ) {
		return array();
	}
	$loaded = get_posts(
		array(
			'post_type'   => array( 'post', 'page' ),
			'post_status' => 'publish',
			'numberposts' => -1,
		)
	);
	if ( ! is_array( $loaded ) ) {
		return array();
	}
	return skyphusion_ai_link_public_rows( $loaded );
}

/**
 * Ids that already have an inbound link. The test stand-in is $GLOBALS['skyphusion_ai_link_linked'].
 *
 * @return array<int, int>
 */
function skyphusion_ai_link_linked() {
	if ( isset( $GLOBALS['skyphusion_ai_link_linked'] ) && is_array( $GLOBALS['skyphusion_ai_link_linked'] ) ) {
		return $GLOBALS['skyphusion_ai_link_linked'];
	}
	return skyphusion_ai_link_inbound_ids( skyphusion_ai_link_posts() );
}

/**
 * Title of the post on the screen.
 *
 * @param int $post_id Post id.
 * @return string
 */
function skyphusion_ai_link_title( $post_id ) {
	if ( isset( $GLOBALS['skyphusion_ai_link_title'] ) && is_string( $GLOBALS['skyphusion_ai_link_title'] ) ) {
		return $GLOBALS['skyphusion_ai_link_title'];
	}
	if ( ! function_exists( 'get_post' ) ) {
		return '';
	}
	$post = get_post( (int) $post_id );
	if ( is_object( $post ) && isset( $post->post_title ) ) {
		return (string) $post->post_title;
	}
	if ( is_array( $post ) && isset( $post['post_title'] ) ) {
		return (string) $post['post_title'];
	}
	return '';
}

/**
 * Print suggestions and pages that nothing links to.
 *
 * @param mixed $post Post object, or null in the test.
 * @return void
 */
function skyphusion_ai_link_render( $post = null ) {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	if ( ! function_exists( 'esc_html' ) ) {
		return;
	}
	$post_id = 0;
	if ( is_object( $post ) && isset( $post->ID ) ) {
		$post_id = (int) $post->ID;
	} elseif ( is_array( $post ) && isset( $post['ID'] ) ) {
		$post_id = (int) $post['ID'];
	}
	echo '<section class="skyphusion-ai-links">';
	echo '<p>' . esc_html__( 'These are suggestions. The post is not changed.', 'skyphusion-ai' ) . '</p>';
	$rows        = skyphusion_ai_link_rows(
		array(
			'ID' => $post_id,
		)
	);
	$suggestions = skyphusion_ai_link_suggestions( skyphusion_ai_link_content( $post_id ), $rows, $post_id );
	if ( array() === $suggestions ) {
		$config = function_exists( 'skyphusion_ai_embed_config' ) ? skyphusion_ai_embed_config() : array();
		if ( empty( $config['created'] ) && array() === $rows ) {
			echo '<p>' . esc_html__( 'The embedding index is needed first.', 'skyphusion-ai' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'No phrase in this post matches another page.', 'skyphusion-ai' ) . '</p>';
		}
	} else {
		echo '<ul>';
		foreach ( $suggestions as $row ) {
			echo '<li>';
			if ( '' !== $row['url'] ) {
				echo '<a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['title'] ) . '</a>';
			} else {
				echo esc_html( $row['title'] );
			}
			echo '</li>';
		}
		echo '</ul>';
	}
	$title = '';
	if ( is_object( $post ) && isset( $post->post_title ) ) {
		$title = (string) $post->post_title;
	} elseif ( is_array( $post ) && isset( $post['post_title'] ) ) {
		$title = (string) $post['post_title'];
	}
	if ( '' === $title ) {
		$title = skyphusion_ai_link_title( $post_id );
	}
	echo '<h3>' . esc_html__( 'Return links', 'skyphusion-ai' ) . '</h3>';
	echo '<p>' . esc_html__( 'A return link is a phrase already in the other page. Nothing is inserted.', 'skyphusion-ai' ) . '</p>';
	$returns = skyphusion_ai_link_returns( $title, skyphusion_ai_link_posts(), $post_id );
	if ( array() === $returns ) {
		echo '<p>' . esc_html__( 'No other page already contains this title.', 'skyphusion-ai' ) . '</p>';
	} else {
		echo '<ul>';
		foreach ( $returns as $row ) {
			echo '<li>';
			echo esc_html( $row['phrase'] );
			echo ' ';
			if ( '' !== $row['url'] ) {
				echo '<a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['title'] ) . '</a>';
			} else {
				echo esc_html( $row['title'] );
			}
			if ( '' !== $row['sentence'] ) {
				echo ' ' . esc_html( $row['sentence'] );
			}
			echo '</li>';
		}
		echo '</ul>';
	}
	echo '<h3>' . esc_html__( 'Pages that nothing links to', 'skyphusion-ai' ) . '</h3>';
	$pages   = skyphusion_ai_link_posts();
	$orphans = skyphusion_ai_link_orphans( $pages, skyphusion_ai_link_linked() );
	if ( array() === $pages ) {
		echo '<p>' . esc_html__( 'No published page was found.', 'skyphusion-ai' ) . '</p>';
	} elseif ( array() === $orphans ) {
		echo '<p>' . esc_html__( 'Every stored page has a link.', 'skyphusion-ai' ) . '</p>';
	} else {
		echo '<ul>';
		foreach ( $orphans as $row ) {
			echo '<li>' . esc_html( $row['title'] ) . '</li>';
		}
		echo '</ul>';
	}
	echo '</section>';
}

/**
 * Register the box on the post editor.
 *
 * @return void
 */
function skyphusion_ai_link_boot() {
	if ( ! function_exists( 'add_meta_box' ) ) {
		return;
	}
	add_meta_box(
		'skyphusion_ai_links',
		__( 'Internal links', 'skyphusion-ai' ),
		'skyphusion_ai_link_render',
		array( 'post', 'page', 'product' ),
		'side'
	);
}

add_action( 'add_meta_boxes', 'skyphusion_ai_link_boot' );
