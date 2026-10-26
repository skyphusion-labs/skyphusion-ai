<?php
/**
 * Search words stored on this site.
 *
 * A row keeps the words, the time, the result count, and whether the
 * visitor was logged in. It does not keep an IP address, an account,
 * or a browser. Rows older than 90 days are removed. An admin can ask
 * once to group the empty searches.
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
 * Case fold used to group the same search.
 *
 * This matches the fold used for the repeat hash. It does not stem.
 *
 * @param string $words Search text.
 * @return string
 */
function skyphusion_ai_insight_fold( $words ) {
	return strtolower( (string) $words );
}

/**
 * Empty searches grouped by the same words. No request leaves the site.
 *
 * A search that found a page is left out. Rows older than 90 days are
 * left out. Case does not split a group. The words are not trimmed here.
 *
 * @param mixed $rows Stored rows.
 * @param int   $now  Unix time. Zero uses the current time.
 * @return array<int, array{words: string, count: int}>
 */
function skyphusion_ai_insight_gaps( $rows, $now = 0 ) {
	if ( ! is_array( $rows ) ) {
		return array();
	}
	$now = (int) $now;
	if ( 0 >= $now ) {
		$now = time();
	}
	$keep_after = $now - skyphusion_ai_insight_keep_seconds();
	$groups     = array();
	foreach ( $rows as $row ) {
		$clean = skyphusion_ai_insight_public_row( $row );
		if ( 0 !== $clean['results'] ) {
			continue;
		}
		if ( $clean['searched_at'] <= $keep_after ) {
			continue;
		}
		$words = $clean['words'];
		if ( '' === $words ) {
			continue;
		}
		$key = skyphusion_ai_insight_fold( $words );
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'words' => $words,
				'count' => 0,
			);
		}
		++$groups[ $key ]['count'];
	}
	$out = array_values( $groups );
	usort( $out, 'skyphusion_ai_insight_gap_sort' );
	if ( count( $out ) > 20 ) {
		$out = array_slice( $out, 0, 20 );
	}
	return $out;
}

/**
 * Order groups by count, then by the folded words.
 *
 * @param array{words: string, count: int} $left  One group.
 * @param array{words: string, count: int} $right One group.
 * @return int
 */
function skyphusion_ai_insight_gap_sort( $left, $right ) {
	$left_count  = isset( $left['count'] ) ? (int) $left['count'] : 0;
	$right_count = isset( $right['count'] ) ? (int) $right['count'] : 0;
	if ( $left_count === $right_count ) {
		$left_words  = isset( $left['words'] ) ? skyphusion_ai_insight_fold( $left['words'] ) : '';
		$right_words = isset( $right['words'] ) ? skyphusion_ai_insight_fold( $right['words'] ) : '';
		return strcmp( $left_words, $right_words );
	}
	if ( $left_count < $right_count ) {
		return 1;
	}
	return -1;
}

/**
 * Lines sent for one ask. Each line is a count and the words.
 *
 * @param array<int, array{words: string, count: int}> $gaps Groups.
 * @return string
 */
function skyphusion_ai_insight_gap_text( $gaps ) {
	if ( ! is_array( $gaps ) ) {
		return '';
	}
	$lines = array();
	foreach ( $gaps as $gap ) {
		if ( ! is_array( $gap ) ) {
			continue;
		}
		$words = isset( $gap['words'] ) ? (string) $gap['words'] : '';
		if ( '' === $words ) {
			continue;
		}
		$count   = isset( $gap['count'] ) ? (int) $gap['count'] : 0;
		$lines[] = (string) $count . ' ' . $words;
	}
	return implode( "\n", $lines );
}

/**
 * Transient that holds the last suggestion. It has no user id.
 *
 * @return string
 */
function skyphusion_ai_insight_next_key() {
	return 'skyphusion_ai_write_next';
}

/**
 * Cut stored suggestion text. Tags are removed.
 *
 * @param string $text Raw text.
 * @return string
 */
function skyphusion_ai_insight_clip( $text ) {
	$text     = trim( (string) $text );
	$stripped = preg_replace( '/<[^>]*>/', '', $text );
	$text     = trim( is_string( $stripped ) ? $stripped : $text );
	$cap      = 1000;
	if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
		if ( mb_strlen( $text ) > $cap ) {
			return (string) mb_substr( $text, 0, $cap );
		}
		return $text;
	}
	if ( strlen( $text ) > $cap ) {
		return substr( $text, 0, $cap );
	}
	return $text;
}

/**
 * Store the last suggestion, or clear it.
 *
 * The value is the model text and an error sentence. It does not store
 * an IP address, an account, or a browser.
 *
 * @param string $text  Suggestion.
 * @param string $error Error sentence.
 * @return array{text: string, error: string}
 */
function skyphusion_ai_insight_remember( $text, $error ) {
	$row = array(
		'text'  => skyphusion_ai_insight_clip( $text ),
		'error' => skyphusion_ai_insight_clip( $error ),
	);
	if ( '' === $row['text'] && '' === $row['error'] && function_exists( 'delete_transient' ) ) {
		delete_transient( skyphusion_ai_insight_next_key() );
		return $row;
	}
	if ( function_exists( 'set_transient' ) ) {
		set_transient( skyphusion_ai_insight_next_key(), $row, 600 );
	}
	return $row;
}

/**
 * Last suggestion, or two empty strings.
 *
 * @return array{text: string, error: string}
 */
function skyphusion_ai_insight_next() {
	$blank = array(
		'text'  => '',
		'error' => '',
	);
	if ( ! function_exists( 'get_transient' ) ) {
		return $blank;
	}
	$stored = get_transient( skyphusion_ai_insight_next_key() );
	if ( ! is_array( $stored ) ) {
		return $blank;
	}
	$blank['text']  = isset( $stored['text'] ) ? (string) $stored['text'] : '';
	$blank['error'] = isset( $stored['error'] ) ? (string) $stored['error'] : '';
	return $blank;
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
	$gaps  = skyphusion_ai_insight_gaps( $rows, time() );
	$next  = skyphusion_ai_insight_next();
	$html  = '<section class="skyphusion-ai-insights">';
	$html .= '<h2>' . esc_html__( 'Searches', 'skyphusion-ai' ) . '</h2>';
	$html .= '<p>' . esc_html__( 'Searches stay on this site. An IP address is not stored.', 'skyphusion-ai' ) . '</p>';
	if ( array() === $rows ) {
		$html .= '<p>' . esc_html__( 'No search is stored yet.', 'skyphusion-ai' ) . '</p>';
	} elseif ( array() === $gaps ) {
		$html .= '<p>' . esc_html__( 'Every stored search found a page.', 'skyphusion-ai' ) . '</p>';
	} else {
		$html .= '<ul>';
		foreach ( $gaps as $gap ) {
			$label = $gap['words'];
			if ( 1 < $gap['count'] ) {
				$label .= ' (' . (string) (int) $gap['count'] . ')';
			}
			$html .= '<li>' . esc_html( $label ) . '</li>';
		}
		$html .= '</ul>';
		$html .= '<p>' . esc_html__( 'This asks once for a topic to write.', 'skyphusion-ai' ) . '</p>';
		if ( function_exists( 'admin_url' ) && function_exists( 'esc_url' ) && function_exists( 'esc_attr' ) ) {
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$html .= '<input type="hidden" name="action" value="skyphusion_ai_insight_write_next" />';
			if ( function_exists( 'wp_nonce_field' ) ) {
				$html .= wp_nonce_field( 'skyphusion_ai_insight_write_next', '_wpnonce', true, false );
			}
			$html .= '<button type="submit">' . esc_html__( 'Suggest what to write', 'skyphusion-ai' ) . '</button>';
			$html .= '</form>';
		}
	}
	if ( '' !== $next['error'] ) {
		$html .= '<p>' . esc_html( $next['error'] ) . '</p>';
	}
	if ( '' !== $next['text'] ) {
		$html .= '<p>' . esc_html( $next['text'] ) . '</p>';
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
			'form'    => array(
				'method' => true,
				'action' => true,
			),
			'input'   => array(
				'type'  => true,
				'name'  => true,
				'id'    => true,
				'value' => true,
			),
			'button'  => array(
				'type' => true,
			),
		)
	);
}

/**
 * Ask once for a topic to write. The page view does not call this.
 *
 * @return string|WP_Error
 */
function skyphusion_ai_insight_write_next() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'skyphusion_ai_insight_cap', __( 'You cannot manage these settings.', 'skyphusion-ai' ) );
	}
	$gaps = skyphusion_ai_insight_gaps( skyphusion_ai_insight_load(), time() );
	if ( array() === $gaps ) {
		skyphusion_ai_insight_remember( '', '' );
		return new WP_Error( 'skyphusion_ai_insight_empty', __( 'Every stored search found a page.', 'skyphusion-ai' ) );
	}
	$prompt = '';
	if ( function_exists( 'skyphusion_ai_prompt_fill' ) ) {
		$prompt = trim(
			skyphusion_ai_prompt_fill(
				'write_next',
				array(
					'gaps' => skyphusion_ai_insight_gap_text( $gaps ),
				)
			)
		);
	}
	if ( '' === $prompt ) {
		return new WP_Error( 'skyphusion_ai_insight_empty', __( 'The prompt is empty.', 'skyphusion-ai' ) );
	}
	if ( function_exists( 'skyphusion_ai_neuron_refusal' ) ) {
		$refusal = skyphusion_ai_neuron_refusal();
		if ( is_wp_error( $refusal ) ) {
			return $refusal;
		}
	}
	if ( function_exists( 'skyphusion_ai_tool_refusal' ) ) {
		$tool_refusal = skyphusion_ai_tool_refusal( 'text' );
		if ( is_wp_error( $tool_refusal ) ) {
			return $tool_refusal;
		}
	}
	$model = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
	if ( function_exists( 'skyphusion_ai_prompt_model' ) ) {
		$model = skyphusion_ai_prompt_model();
	}
	$credentials = array(
		'account_id' => '',
		'token'      => '',
	);
	if ( function_exists( 'skyphusion_ai_embed_credentials' ) ) {
		$credentials = skyphusion_ai_embed_credentials();
	}
	$gateway = '';
	if ( function_exists( 'skyphusion_ai_settings' ) ) {
		$settings = skyphusion_ai_settings();
		if ( isset( $settings['gateway_id'] ) ) {
			$gateway = (string) $settings['gateway_id'];
		}
	}
	$url    = '';
	$failed = __( 'The topic list could not run.', 'skyphusion-ai' );
	if ( function_exists( 'cloudflare_ai_provider_run_url' ) ) {
		$url = cloudflare_ai_provider_run_url( $credentials['account_id'], $gateway, $model );
	}
	if ( '' === $url || '' === $credentials['token'] || ! function_exists( 'wp_remote_post' ) ) {
		return new WP_Error( 'skyphusion_ai_insight_http', $failed );
	}
	$body = array();
	if ( function_exists( 'cloudflare_ai_provider_chat_body' ) ) {
		$body = cloudflare_ai_provider_chat_body(
			array(
				'model'    => $model,
				'messages' => array(
					array(
						'role' => 'user',
						'text' => $prompt,
					),
				),
			)
		);
	}
	$headers = array(
		'Content-Type'  => 'application/json',
		'Authorization' => 'Bearer ' . $credentials['token'],
	);
	if ( function_exists( 'skyphusion_ai_gateway_metadata_header' ) ) {
		$headers = array_merge( $headers, skyphusion_ai_gateway_metadata_header( $url, 'text', $model ) );
	}
	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 30,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $response ) ) {
		if ( function_exists( 'skyphusion_ai_gateway_note' ) ) {
			skyphusion_ai_gateway_note( $url, 'text', $response );
		}
		if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
			skyphusion_ai_neuron_record( 'text', $model, $response, 'text' );
		}
		skyphusion_ai_insight_remember( '', $failed );
		return new WP_Error( 'skyphusion_ai_insight_http', $failed );
	}
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
		skyphusion_ai_neuron_record( 'text', $model, $decoded, 'text' );
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		if ( function_exists( 'skyphusion_ai_gateway_note' ) ) {
			skyphusion_ai_gateway_note( $url, 'text', $response );
		}
		skyphusion_ai_insight_remember( '', $failed );
		return new WP_Error( 'skyphusion_ai_insight_http', $failed );
	}
	$text = '';
	if ( function_exists( 'skyphusion_ai_prompt_read' ) ) {
		$text = skyphusion_ai_insight_clip( skyphusion_ai_prompt_read( $decoded ) );
	}
	if ( '' === $text ) {
		skyphusion_ai_insight_remember( '', $failed );
		return new WP_Error( 'skyphusion_ai_insight_http', $failed );
	}
	skyphusion_ai_insight_remember( $text, '' );
	return $text;
}

/**
 * Read the ask form. The page view does not call the model.
 *
 * @return void
 */
function skyphusion_ai_insight_handle_write_next() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( function_exists( 'check_admin_referer' ) ) {
		check_admin_referer( 'skyphusion_ai_insight_write_next' );
	}
	skyphusion_ai_insight_write_next();
	if ( function_exists( 'wp_safe_redirect' ) ) {
		wp_safe_redirect( admin_url( 'options-general.php?page=cloudflare-ai-search' ) );
		exit;
	}
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
add_action( 'admin_post_skyphusion_ai_insight_write_next', 'skyphusion_ai_insight_handle_write_next' );
