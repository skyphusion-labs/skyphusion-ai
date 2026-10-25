<?php
/**
 * Source links under the AI Search answer.
 *
 * The search page already receives source text. This file keeps a URL
 * beside each source and prints that list under the sentence.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raw source address from one chunk.
 *
 * The item key wins. A string field named url is used only when that key
 * is empty. A top-level key is the same field when the row is the item.
 *
 * @param mixed $row One search chunk.
 * @return string
 */
function skyphusion_ai_source_candidate( $row ) {
	if ( ! is_array( $row ) ) {
		return '';
	}
	$item = array();
	if ( isset( $row['item'] ) && is_array( $row['item'] ) ) {
		$item = $row['item'];
	}
	$key = '';
	if ( isset( $item['key'] ) && is_string( $item['key'] ) ) {
		$key = trim( $item['key'] );
	}
	if ( '' === $key && isset( $row['key'] ) && is_string( $row['key'] ) ) {
		$key = trim( $row['key'] );
	}
	if ( '' !== $key ) {
		return $key;
	}
	if ( isset( $row['url'] ) && is_string( $row['url'] ) ) {
		return trim( $row['url'] );
	}
	if ( isset( $item['url'] ) && is_string( $item['url'] ) ) {
		return trim( $item['url'] );
	}
	return '';
}

/**
 * HTTP or HTTPS source URL, or an empty string.
 *
 * A file name, a javascript URL, a data URL, and any address with
 * userinfo are dropped. The kept value is the trimmed address.
 *
 * @param mixed $row One search chunk.
 * @return string
 */
function skyphusion_ai_source_url( $row ) {
	$raw = skyphusion_ai_source_candidate( $row );
	if ( '' === $raw || preg_match( '/\s/', $raw ) ) {
		return '';
	}
	if ( function_exists( 'wp_parse_url' ) ) {
		$parts = wp_parse_url( $raw );
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$parts = parse_url( $raw );
	}
	if ( ! is_array( $parts ) ) {
		return '';
	}
	if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return '';
	}
	if ( ! isset( $parts['scheme'] ) || ! isset( $parts['host'] ) ) {
		return '';
	}
	$scheme = strtolower( (string) $parts['scheme'] );
	if ( 'https' !== $scheme && 'http' !== $scheme ) {
		return '';
	}
	if ( '' === (string) $parts['host'] ) {
		return '';
	}
	return $raw;
}

/**
 * Answer row with one URL per source.
 *
 * Indexes match. A missing URL is an empty string. The source text is
 * not changed.
 *
 * @param string            $answer      Answer sentence.
 * @param array<int, mixed> $sources     Source text.
 * @param array<int, mixed> $source_urls URL for each source.
 * @return array
 */
function skyphusion_ai_answer_with_urls( $answer, $sources, $source_urls ) {
	$text = array();
	if ( is_array( $sources ) ) {
		foreach ( $sources as $source ) {
			$text[] = is_string( $source ) ? $source : '';
		}
	}
	$urls = array();
	if ( is_array( $source_urls ) ) {
		foreach ( $source_urls as $url ) {
			$urls[] = is_string( $url ) ? $url : '';
		}
	}
	return array(
		'answer'      => is_string( $answer ) ? $answer : '',
		'sources'     => $text,
		'source_urls' => $urls,
	);
}

/**
 * True when this chunk should not be listed.
 *
 * An empty row is skipped. A repeated text row is skipped. A repeated
 * URL with no text is skipped.
 *
 * @param string              $text      Source text.
 * @param mixed               $row       One search chunk.
 * @param array<string, bool> $seen      Text already listed.
 * @param array<string, bool> $seen_urls URLs already listed.
 * @return bool
 */
function skyphusion_ai_source_skip( $text, $row, $seen, $seen_urls ) {
	$url = skyphusion_ai_source_url( $row );
	if ( '' === $text && '' === $url ) {
		return true;
	}
	if ( '' !== $text && isset( $seen[ $text ] ) ) {
		return true;
	}
	if ( '' === $text && '' !== $url && isset( $seen_urls[ $url ] ) ) {
		return true;
	}
	return false;
}

/**
 * Remember a URL that was listed.
 *
 * @param array<string, bool> $seen_urls URLs already listed.
 * @param mixed               $row       One search chunk.
 * @return void
 */
function skyphusion_ai_source_remember( &$seen_urls, $row ) {
	$url = skyphusion_ai_source_url( $row );
	if ( '' !== $url ) {
		$seen_urls[ $url ] = true;
	}
}

/**
 * Append the URL for one listed source.
 *
 * @param array<int, string> $source_urls URL list.
 * @param mixed              $row         One search chunk.
 * @return void
 */
function skyphusion_ai_source_push( &$source_urls, $row ) {
	$source_urls[] = skyphusion_ai_source_url( $row );
}

/**
 * Visible source text, at most 240 characters.
 *
 * The URL is shown when the source text is empty. A cut ends with
 * three ASCII dots.
 *
 * @param string $text Source text.
 * @param string $url  Source URL.
 * @return string
 */
function skyphusion_ai_source_label( $text, $url ) {
	$label = $text;
	if ( '' === $label ) {
		$label = $url;
	}
	$limit = 240;
	if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
		if ( mb_strlen( $label, 'UTF-8' ) <= $limit ) {
			return $label;
		}
		$keep = $limit - 3;
		return mb_substr( $label, 0, $keep, 'UTF-8' ) . '...';
	}
	if ( strlen( $label ) <= $limit ) {
		return $label;
	}
	return substr( $label, 0, $limit - 3 ) . '...';
}

/**
 * Print one source row. A URL is an anchor. Text alone is not.
 *
 * @param string $url   HTTP URL, or an empty string.
 * @param string $label Visible text.
 * @return void
 */
function skyphusion_ai_source_item_html( $url, $label ) {
	if ( '' !== $url ) {
		echo '<li><a href="' . esc_url( $url ) . '" rel="nofollow">';
		echo esc_html( $label );
		echo '</a></li>';
		return;
	}
	echo '<li>' . esc_html( $label ) . '</li>';
}

/**
 * Print the source list, or nothing when every row is empty.
 *
 * A missing or short source_urls list means those rows have no URL.
 * The question, the token, and any error are not read.
 *
 * @param mixed $result Query result, or an old cache row.
 * @return void
 */
function skyphusion_ai_lead_sources_html( $result ) {
	if ( ! is_array( $result ) ) {
		return;
	}
	$sources = array();
	if ( isset( $result['sources'] ) && is_array( $result['sources'] ) ) {
		$sources = array_values( $result['sources'] );
	}
	$urls = array();
	if ( isset( $result['source_urls'] ) && is_array( $result['source_urls'] ) ) {
		$urls = array_values( $result['source_urls'] );
	}
	$count = count( $sources );
	if ( count( $urls ) > $count ) {
		$count = count( $urls );
	}
	$rows = array();
	for ( $index = 0; $index < $count; $index++ ) {
		$text = '';
		if ( isset( $sources[ $index ] ) && is_string( $sources[ $index ] ) ) {
			$text = trim( $sources[ $index ] );
		}
		$url = '';
		if ( isset( $urls[ $index ] ) && is_string( $urls[ $index ] ) ) {
			$url = skyphusion_ai_source_url( array( 'key' => $urls[ $index ] ) );
		}
		if ( '' === $text && '' === $url ) {
			continue;
		}
		$rows[] = array(
			'url'   => $url,
			'label' => skyphusion_ai_source_label( $text, $url ),
		);
	}
	if ( array() === $rows ) {
		return;
	}
	echo '<ul class="cloudflare-ai-search-sources">';
	foreach ( $rows as $row ) {
		skyphusion_ai_source_item_html( $row['url'], $row['label'] );
	}
	echo '</ul>';
}
