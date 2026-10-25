<?php
/**
 * Search words stored on this site.
 *
 * A row keeps the words, the time, the result count, and whether the
 * visitor was logged in. It does not keep an IP address, an account,
 * or a browser. Rows older than 90 days are removed.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option that holds the rows. It is not autoloaded.
 *
 * @return string
 */
function skyphusion_ai_insight_option_name() {
	return 'skyphusion_ai_insights';
}

/**
 * Most rows kept for one UTC day.
 *
 * @return int
 */
function skyphusion_ai_insight_daily_cap() {
	return 100;
}

/**
 * Seconds a row is kept.
 *
 * @return int
 */
function skyphusion_ai_insight_keep_seconds() {
	return 90 * 86400;
}

/**
 * Seconds before the same visitor and the same words are stored again.
 *
 * @return int
 */
function skyphusion_ai_insight_repeat_seconds() {
	return 3600;
}

/**
 * Longest search text that is stored.
 *
 * @return int
 */
function skyphusion_ai_insight_word_cap() {
	return 200;
}

/**
 * Outcome names copied from the search-count row. Anything else is blank.
 *
 * @param mixed $value Raw outcome.
 * @return string
 */
function skyphusion_ai_insight_outcome( $value ) {
	if ( ! is_string( $value ) ) {
		return '';
	}
	if ( ! function_exists( 'skyphusion_ai_search_outcome_keys' ) ) {
		return '';
	}
	if ( ! in_array( $value, skyphusion_ai_search_outcome_keys(), true ) ) {
		return '';
	}
	return $value;
}

/**
 * Search text, cut to the cap.
 *
 * @param mixed $value Raw words.
 * @return string
 */
function skyphusion_ai_insight_words( $value ) {
	$words = trim( (string) $value );
	$cap   = skyphusion_ai_insight_word_cap();
	if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
		if ( mb_strlen( $words ) > $cap ) {
			return (string) mb_substr( $words, 0, $cap );
		}
		return $words;
	}
	if ( strlen( $words ) > $cap ) {
		return substr( $words, 0, $cap );
	}
	return $words;
}

/**
 * True when this client is a bot. The browser string is not stored.
 *
 * @param mixed $agent User agent, or an empty string.
 * @return bool
 */
function skyphusion_ai_insight_is_bot( $agent ) {
	if ( ! is_string( $agent ) || '' === $agent ) {
		return false;
	}
	$agent = strtolower( $agent );
	foreach ( array( 'bot', 'spider', 'crawl', 'slurp', 'wget', 'curl' ) as $needle ) {
		if ( false !== strpos( $agent, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * One stored row. Extra keys, including an address, are dropped.
 *
 * @param mixed $row Raw row.
 * @return array{words: string, searched_at: int, results: int, logged_in: int, outcome: string, dedupe: string}
 */
function skyphusion_ai_insight_public_row( $row ) {
	$blank = array(
		'words'       => '',
		'searched_at' => 0,
		'results'     => 0,
		'logged_in'   => 0,
		'outcome'     => '',
		'dedupe'      => '',
	);
	if ( ! is_array( $row ) ) {
		return $blank;
	}
	$results = isset( $row['results'] ) ? (int) $row['results'] : 0;
	if ( $results < 0 ) {
		$results = 0;
	}
	$blank['words']       = isset( $row['words'] ) ? (string) $row['words'] : '';
	$blank['searched_at'] = isset( $row['searched_at'] ) ? (int) $row['searched_at'] : 0;
	$blank['results']     = $results;
	$blank['logged_in']   = empty( $row['logged_in'] ) ? 0 : 1;
	$blank['outcome']     = skyphusion_ai_insight_outcome( isset( $row['outcome'] ) ? $row['outcome'] : '' );
	$blank['dedupe']      = isset( $row['dedupe'] ) ? (string) $row['dedupe'] : '';
	return $blank;
}

/**
 * Apply one event to the stored rows.
 *
 * The visitor token is used only to build the dedupe hash. It is not
 * copied onto the row.
 *
 * @param mixed                $rows  Stored rows.
 * @param array<string, mixed> $event One search.
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_insight_apply( $rows, $event ) {
	if ( ! is_array( $rows ) ) {
		$rows = array();
	}
	if ( ! is_array( $event ) ) {
		$event = array();
	}
	$now        = isset( $event['now'] ) ? (int) $event['now'] : time();
	$keep_after = $now - skyphusion_ai_insight_keep_seconds();
	$kept       = array();
	foreach ( $rows as $row ) {
		$clean = skyphusion_ai_insight_public_row( $row );
		if ( $clean['searched_at'] <= $keep_after ) {
			continue;
		}
		$kept[] = $clean;
	}
	if ( ! empty( $event['bot'] ) ) {
		return $kept;
	}
	$words = skyphusion_ai_insight_words( isset( $event['words'] ) ? $event['words'] : '' );
	if ( '' === $words ) {
		return $kept;
	}
	$visitor = isset( $event['visitor'] ) ? (string) $event['visitor'] : '';
	$dedupe  = hash( 'sha256', $visitor . "\n" . strtolower( $words ) );
	$window  = $now - skyphusion_ai_insight_repeat_seconds();
	foreach ( $kept as $row ) {
		if ( $row['dedupe'] === $dedupe && $row['searched_at'] >= $window ) {
			return $kept;
		}
	}
	$day   = gmdate( 'Y-m-d', $now );
	$today = 0;
	foreach ( $kept as $row ) {
		if ( gmdate( 'Y-m-d', $row['searched_at'] ) === $day ) {
			++$today;
		}
	}
	if ( $today >= skyphusion_ai_insight_daily_cap() ) {
		return $kept;
	}
	$results = isset( $event['results'] ) ? (int) $event['results'] : 0;
	if ( $results < 0 ) {
		$results = 0;
	}
	$kept[] = array(
		'words'       => $words,
		'searched_at' => $now,
		'results'     => $results,
		'logged_in'   => empty( $event['logged_in'] ) ? 0 : 1,
		'outcome'     => skyphusion_ai_insight_outcome( isset( $event['outcome'] ) ? $event['outcome'] : '' ),
		'dedupe'      => $dedupe,
	);
	return $kept;
}

/**
 * Rows from the test stand-in, or from the option.
 *
 * @return array<int, mixed>
 */
function skyphusion_ai_insight_load() {
	if ( isset( $GLOBALS['skyphusion_ai_insight_rows'] ) && is_array( $GLOBALS['skyphusion_ai_insight_rows'] ) ) {
		return $GLOBALS['skyphusion_ai_insight_rows'];
	}
	if ( ! function_exists( 'get_option' ) ) {
		return array();
	}
	$stored = get_option( skyphusion_ai_insight_option_name(), array() );
	if ( ! is_array( $stored ) ) {
		return array();
	}
	return $stored;
}

/**
 * Write the rows. The test stand-in wins over the option.
 *
 * @param array<int, mixed> $rows Rows to keep.
 * @return void
 */
function skyphusion_ai_insight_store( $rows ) {
	if ( isset( $GLOBALS['skyphusion_ai_insight_rows'] ) && is_array( $GLOBALS['skyphusion_ai_insight_rows'] ) ) {
		$GLOBALS['skyphusion_ai_insight_rows'] = $rows;
		return;
	}
	if ( function_exists( 'update_option' ) ) {
		update_option( skyphusion_ai_insight_option_name(), $rows, false );
	}
}

/**
 * Load, apply, and store one event.
 *
 * @param array<string, mixed> $event One search.
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_insight_capture( $event ) {
	if ( ! is_array( $event ) ) {
		return array();
	}
	$next = skyphusion_ai_insight_apply( skyphusion_ai_insight_load(), $event );
	skyphusion_ai_insight_store( $next );
	return $next;
}

/**
 * HTML for the settings screen.
 *
 * @param mixed $rows Stored rows.
 * @return string
 */
function skyphusion_ai_insight_html( $rows ) {
	if ( ! is_array( $rows ) ) {
		$rows = array();
	}
	$gaps = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$results = isset( $row['results'] ) ? (int) $row['results'] : 0;
		if ( 0 !== $results ) {
			continue;
		}
		$words = isset( $row['words'] ) ? (string) $row['words'] : '';
		if ( '' === $words ) {
			continue;
		}
		$gaps[] = $words;
	}
	if ( count( $gaps ) > 20 ) {
		$gaps = array_slice( $gaps, -20 );
	}
	$html  = '<section class="skyphusion-ai-insights">';
	$html .= '<h2>' . esc_html__( 'Searches', 'skyphusion-ai' ) . '</h2>';
	$html .= '<p>' . esc_html__( 'Searches stay on this site. An IP address is not stored.', 'skyphusion-ai' ) . '</p>';
	if ( array() === $rows ) {
		$html .= '<p>' . esc_html__( 'No search is stored yet.', 'skyphusion-ai' ) . '</p>';
	} elseif ( array() === $gaps ) {
		$html .= '<p>' . esc_html__( 'Every stored search found a page.', 'skyphusion-ai' ) . '</p>';
	} else {
		$html .= '<ul>';
		foreach ( $gaps as $words ) {
			$html .= '<li>' . esc_html( $words ) . '</li>';
		}
		$html .= '</ul>';
	}
	$html .= '</section>';
	return $html;
}

/**
 * Print the list for an admin. Other roles see nothing.
 *
 * @return void
 */
function skyphusion_ai_insight_render() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'esc_html' ) || ! function_exists( 'wp_kses' ) ) {
		return;
	}
	echo wp_kses(
		skyphusion_ai_insight_html( skyphusion_ai_insight_load() ),
		array(
			'section' => array(
				'class' => true,
			),
			'h2'      => array(),
			'p'       => array(),
			'ul'      => array(),
			'li'      => array(),
		)
	);
}

/**
 * Record the main search after the page has its result count.
 *
 * Browser snippets do not reach this function.
 *
 * @return void
 */
function skyphusion_ai_insight_on_shutdown() {
	if ( function_exists( 'is_admin' ) && is_admin() ) {
		return;
	}
	if ( ! function_exists( 'is_search' ) || ! is_search() ) {
		return;
	}
	$words = '';
	if ( function_exists( 'get_search_query' ) ) {
		$words = (string) get_search_query( false );
	}
	$results = 0;
	if ( isset( $GLOBALS['wp_query'] ) && is_object( $GLOBALS['wp_query'] ) && isset( $GLOBALS['wp_query']->found_posts ) ) {
		$results = (int) $GLOBALS['wp_query']->found_posts;
	}
	$agent = '';
	if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
		$agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
	}
	$visitor = '';
	if ( isset( $_COOKIE['skyphusion_ai_visit'] ) ) {
		$visitor = sanitize_text_field( wp_unslash( $_COOKIE['skyphusion_ai_visit'] ) );
	}
	if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $visitor ) ) {
		$visitor = '';
	}
	if ( '' === $visitor && function_exists( 'setcookie' ) && ! headers_sent() ) {
		$visitor = bin2hex( random_bytes( 16 ) );
		if ( '' !== $visitor ) {
			$path   = defined( 'COOKIEPATH' ) ? (string) COOKIEPATH : '/';
			$domain = defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '';
			$secure = function_exists( 'is_ssl' ) && is_ssl();
			setcookie( 'skyphusion_ai_visit', $visitor, time() + skyphusion_ai_insight_repeat_seconds(), $path, $domain, $secure, true );
		}
	}
	$outcome = '';
	if ( isset( $GLOBALS['skyphusion_ai_search_outcome_last'] ) ) {
		$outcome = (string) $GLOBALS['skyphusion_ai_search_outcome_last'];
	}
	$logged = function_exists( 'is_user_logged_in' ) && is_user_logged_in();
	skyphusion_ai_insight_capture(
		array(
			'words'     => $words,
			'results'   => $results,
			'logged_in' => $logged,
			'outcome'   => $outcome,
			'visitor'   => $visitor,
			'bot'       => skyphusion_ai_insight_is_bot( $agent ),
			'now'       => time(),
		)
	);
}

add_action( 'shutdown', 'skyphusion_ai_insight_on_shutdown' );
