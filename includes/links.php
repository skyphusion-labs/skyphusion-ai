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
 * Pages to check for inbound links. The test stand-in is $GLOBALS['skyphusion_ai_link_posts'].
 *
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_link_posts() {
	if ( isset( $GLOBALS['skyphusion_ai_link_posts'] ) && is_array( $GLOBALS['skyphusion_ai_link_posts'] ) ) {
		return $GLOBALS['skyphusion_ai_link_posts'];
	}
	return array();
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
	return array();
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
	echo '<h3>' . esc_html__( 'Pages that nothing links to', 'skyphusion-ai' ) . '</h3>';
	$orphans = skyphusion_ai_link_orphans( skyphusion_ai_link_posts(), skyphusion_ai_link_linked() );
	if ( array() === $orphans ) {
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
		array( 'post', 'product' ),
		'side'
	);
}

add_action( 'add_meta_boxes', 'skyphusion_ai_link_boot' );
