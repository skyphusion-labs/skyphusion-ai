<?php
/**
 * Site assistant. It answers from stored pages and cites them.
 *
 * The control is off until an admin turns it on. The chat button is a
 * separate snippet and stays as it is. A saved chat needs consent.
 * An IP address is not stored. A chat longer than ten turns sends one
 * short summary of the older turns. The page still shows every turn.
 * A chat that was not saved is not stored to make that summary.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option for the assistant switches.
 *
 * @return string
 */
function skyphusion_ai_assistant_option_name() {
	return 'skyphusion_ai_assistant';
}

/**
 * Option for saved chats. It is not autoloaded.
 *
 * @return string
 */
function skyphusion_ai_assistant_chat_option() {
	return 'skyphusion_ai_assistant_chats';
}

/**
 * Saved chats kept on this site.
 *
 * @return int
 */
function skyphusion_ai_assistant_chat_cap() {
	return 20;
}

/**
 * Capabilities a signed-in visitor may be required to have.
 *
 * @return array<int, string>
 */
function skyphusion_ai_assistant_capabilities() {
	return array( 'read', 'edit_posts', 'publish_posts', 'edit_others_posts', 'manage_options' );
}

/**
 * Short text, capped, with tags removed.
 *
 * @param mixed $value Raw text.
 * @param int   $cap   Maximum characters.
 * @return string
 */
function skyphusion_ai_assistant_plain( $value, $cap ) {
	if ( function_exists( 'wp_strip_all_tags' ) ) {
		$text = trim( wp_strip_all_tags( (string) $value ) );
	} else {
		$text = trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- The test harness does not define wp_strip_all_tags.
	}
	$cap = (int) $cap;
	if ( 1 > $cap ) {
		return '';
	}
	if ( strlen( $text ) > $cap ) {
		$text = substr( $text, 0, $cap );
	}
	return $text;
}

/**
 * Blocked topics from a list or from lines of text.
 *
 * @param mixed $value Raw topics.
 * @return array<int, string>
 */
function skyphusion_ai_assistant_blocked_list( $value ) {
	$items = array();
	if ( is_string( $value ) ) {
		$value = preg_split( '/[\r\n,]+/', $value );
	}
	if ( ! is_array( $value ) ) {
		return $items;
	}
	foreach ( $value as $item ) {
		$topic = skyphusion_ai_assistant_plain( $item, 60 );
		if ( '' === $topic || in_array( $topic, $items, true ) ) {
			continue;
		}
		$items[] = $topic;
		if ( count( $items ) >= 100 ) {
			break;
		}
	}
	return $items;
}

/**
 * Switches. The assistant stays off until an admin turns it on.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_assistant_config() {
	$stored = array();
	if ( function_exists( 'get_option' ) ) {
		$stored = get_option( skyphusion_ai_assistant_option_name(), array() );
	}
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	$audience = 'roles';
	if ( isset( $stored['audience'] ) && in_array( (string) $stored['audience'], array( 'roles', 'public' ), true ) ) {
		$audience = (string) $stored['audience'];
	}
	$capability = 'read';
	if ( isset( $stored['capability'] ) && in_array( (string) $stored['capability'], skyphusion_ai_assistant_capabilities(), true ) ) {
		$capability = (string) $stored['capability'];
	}
	$limit = 0;
	if ( isset( $stored['limit'] ) && is_numeric( $stored['limit'] ) && (int) $stored['limit'] >= 0 ) {
		$limit = (int) $stored['limit'];
	}
	$turns = 10;
	if ( isset( $stored['turns'] ) && is_numeric( $stored['turns'] ) ) {
		$turns = (int) $stored['turns'];
		if ( 0 > $turns ) {
			$turns = 0;
		}
		if ( 10 < $turns ) {
			$turns = 10;
		}
	}
	$day = '';
	if ( isset( $stored['day'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $stored['day'] ) ) {
		$day = (string) $stored['day'];
	}
	return array(
		'enabled'       => empty( $stored['enabled'] ) ? 0 : 1,
		'save'          => empty( $stored['save'] ) ? 0 : 1,
		'audience'      => $audience,
		'capability'    => $capability,
		'rules'         => isset( $stored['rules'] ) ? skyphusion_ai_assistant_plain( $stored['rules'], 1500 ) : '',
		'blocked'       => isset( $stored['blocked'] ) ? skyphusion_ai_assistant_blocked_list( $stored['blocked'] ) : array(),
		'blocked_reply' => isset( $stored['blocked_reply'] ) ? skyphusion_ai_assistant_plain( $stored['blocked_reply'], 300 ) : '',
		'safety'        => empty( $stored['safety'] ) ? 0 : 1,
		'limit'         => $limit,
		'turns'         => $turns,
		'day'           => $day,
		'neurons'       => isset( $stored['neurons'] ) ? max( 0, (int) $stored['neurons'] ) : 0,
	);
}

/**
 * Save the switches. This does not call the model.
 *
 * @param array<string, mixed> $posted Form fields.
 * @return array{enabled: int, save: int}
 */
function skyphusion_ai_assistant_apply( $posted ) {
	$config = skyphusion_ai_assistant_config();
	if ( ! is_array( $posted ) ) {
		return $config;
	}
	if ( isset( $posted['enabled'] ) ) {
		$config['enabled'] = empty( $posted['enabled'] ) ? 0 : 1;
	}
	if ( isset( $posted['save'] ) ) {
		$config['save'] = empty( $posted['save'] ) ? 0 : 1;
	}
	if ( isset( $posted['audience'] ) && in_array( (string) $posted['audience'], array( 'roles', 'public' ), true ) ) {
		$config['audience'] = (string) $posted['audience'];
	}
	if ( isset( $posted['capability'] ) && in_array( (string) $posted['capability'], skyphusion_ai_assistant_capabilities(), true ) ) {
		$config['capability'] = (string) $posted['capability'];
	}
	if ( isset( $posted['rules'] ) ) {
		$config['rules'] = skyphusion_ai_assistant_plain( $posted['rules'], 1500 );
	}
	if ( isset( $posted['blocked'] ) ) {
		$config['blocked'] = skyphusion_ai_assistant_blocked_list( $posted['blocked'] );
	}
	if ( isset( $posted['blocked_reply'] ) ) {
		$config['blocked_reply'] = skyphusion_ai_assistant_plain( $posted['blocked_reply'], 300 );
	}
	if ( isset( $posted['safety'] ) ) {
		$config['safety'] = empty( $posted['safety'] ) ? 0 : 1;
	}
	if ( isset( $posted['limit'] ) && is_numeric( $posted['limit'] ) && (int) $posted['limit'] >= 0 ) {
		$config['limit'] = (int) $posted['limit'];
	}
	if ( isset( $posted['turns'] ) && is_numeric( $posted['turns'] ) ) {
		$turns = (int) $posted['turns'];
		if ( 0 > $turns ) {
			$turns = 0;
		}
		if ( 10 < $turns ) {
			$turns = 10;
		}
		$config['turns'] = $turns;
	}
	if ( function_exists( 'update_option' ) ) {
		update_option( skyphusion_ai_assistant_option_name(), $config, false );
	}
	return skyphusion_ai_assistant_config();
}

/**
 * Sentence used when the index has no page for the question.
 *
 * @return string
 */
function skyphusion_ai_assistant_miss_text() {
	return __( 'I cannot find an answer on this site.', 'skyphusion-ai' );
}

/**
 * One result array.
 *
 * @param string            $state     Result name.
 * @param string            $answer    Sentence to show.
 * @param array<int, mixed> $citations Pages used.
 * @return array{state: string, answer: string, citations: array<int, array{title: string, url: string}>}
 */
function skyphusion_ai_assistant_result( $state, $answer, $citations ) {
	$clean = array();
	foreach ( $citations as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$clean[] = array(
			'title' => isset( $row['title'] ) ? (string) $row['title'] : '',
			'url'   => isset( $row['url'] ) ? (string) $row['url'] : '',
		);
		if ( count( $clean ) >= 5 ) {
			break;
		}
	}
	return array(
		'state'     => (string) $state,
		'answer'    => (string) $answer,
		'citations' => $clean,
	);
}

/**
 * Pages named by a search result.
 *
 * @param mixed $found Search rows, or a state array.
 * @return array<int, array{title: string, url: string}>
 */
function skyphusion_ai_assistant_citations( $found ) {
	if ( ! is_array( $found ) || isset( $found['state'] ) ) {
		return array();
	}
	$rows = array();
	foreach ( $found as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$title = isset( $row['title'] ) ? (string) $row['title'] : '';
		$url   = isset( $row['url'] ) ? (string) $row['url'] : '';
		if ( '' === $title && '' === $url ) {
			continue;
		}
		$rows[] = array(
			'title' => $title,
			'url'   => $url,
		);
	}
	return $rows;
}

/**
 * Prompt built from the question and the page titles.
 *
 * @param string                            $question  Visitor question.
 * @param array<int, array<string, string>> $citations Pages.
 * @return string
 */
function skyphusion_ai_assistant_prompt( $question, $citations ) {
	$lines = array( 'Answer from these pages only.' );
	$pages = array();
	foreach ( $citations as $row ) {
		$title = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
		if ( '' !== $title ) {
			$lines[] = '- ' . $title;
			$pages[] = '- ' . $title;
		}
	}
	$lines[] = 'Question: ' . trim( (string) $question );
	if ( ! function_exists( 'skyphusion_ai_prompt_fill' ) ) {
		return implode( "\n", $lines );
	}
	return skyphusion_ai_prompt_fill(
		'assistant',
		array(
			'pages'    => implode( "\n", $pages ),
			'question' => trim( (string) $question ),
		)
	);
}

/**
 * Text model id for the answer.
 *
 * @return string
 */
function skyphusion_ai_assistant_model() {
	if ( function_exists( 'skyphusion_ai_model_choice' ) ) {
		return skyphusion_ai_model_choice( 'writing' );
	}
	return '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
}

/**
 * Ask the text model. A spent budget does not leave the site.
 *
 * @param string $prompt      Prompt text.
 * @param bool   $regenerate  Whether this call asks for a new answer.
 * @return string|WP_Error
 */
function skyphusion_ai_assistant_text( $prompt, $regenerate = false ) {
	if ( skyphusion_ai_assistant_limit_blocked( skyphusion_ai_assistant_config() ) ) {
		return new WP_Error( 'skyphusion_ai_assistant_limit', __( 'The assistant limit is spent. It resumes at 00:00 UTC. The other tools continue.', 'skyphusion-ai' ) );
	}
	if ( function_exists( 'skyphusion_ai_prompt_cache_read' ) ) {
		$cached = skyphusion_ai_prompt_cache_read( 'assistant', skyphusion_ai_assistant_model(), $prompt, '', $regenerate );
		if ( is_string( $cached ) ) {
			if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
				skyphusion_ai_neuron_record( 'text', skyphusion_ai_assistant_model(), array(), 'assistant' );
			}
			return $cached;
		}
	}
	if ( function_exists( 'skyphusion_ai_neuron_refusal' ) ) {
		$refusal = skyphusion_ai_neuron_refusal();
		if ( is_wp_error( $refusal ) ) {
			return $refusal;
		}
	}
	if ( function_exists( 'skyphusion_ai_tool_refusal' ) ) {
		$tool_refusal = skyphusion_ai_tool_refusal( 'assistant' );
		if ( is_wp_error( $tool_refusal ) ) {
			return $tool_refusal;
		}
	}
	$model       = skyphusion_ai_assistant_model();
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
	$url = '';
	if ( function_exists( 'cloudflare_ai_provider_run_url' ) ) {
		$url = cloudflare_ai_provider_run_url( $credentials['account_id'], $gateway, $model );
	}
	if ( '' === $url || '' === $credentials['token'] || ! function_exists( 'wp_remote_post' ) ) {
		return new WP_Error( 'skyphusion_ai_assistant', __( 'The assistant could not answer.', 'skyphusion-ai' ) );
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
			skyphusion_ai_neuron_record( 'text', $model, $response, 'assistant' );
		}
		return new WP_Error( 'skyphusion_ai_assistant', __( 'The assistant could not answer.', 'skyphusion-ai' ) );
	}
	$raw = (string) wp_remote_retrieve_body( $response );
	if ( function_exists( 'skyphusion_ai_worker_sse_text' ) && '' !== skyphusion_ai_worker_sse_text( $raw ) ) {
		$decoded = array(
			'usage' => array(
				'neurons' => skyphusion_ai_worker_sse_neurons( $raw ),
			),
		);
	} else {
		$decoded = json_decode( $raw, true );
	}
	if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
		skyphusion_ai_neuron_record( 'text', $model, $decoded, 'assistant' );
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		if ( function_exists( 'skyphusion_ai_gateway_note' ) ) {
			skyphusion_ai_gateway_note( $url, 'text', $response );
		}
		return new WP_Error( 'skyphusion_ai_assistant', __( 'The assistant could not answer.', 'skyphusion-ai' ) );
	}
	$read = array();
	if ( function_exists( 'cloudflare_ai_provider_read_text' ) ) {
		$read = cloudflare_ai_provider_read_text( is_array( $decoded ) ? $decoded : array() );
	}
	$text = '';
	if ( function_exists( 'skyphusion_ai_worker_sse_text' ) ) {
		$text = trim( skyphusion_ai_worker_sse_text( $raw ) );
	}
	if ( '' === $text && isset( $read['candidates'][0]['text'] ) ) {
		$text = trim( (string) $read['candidates'][0]['text'] );
	}
	if ( '' !== $text && function_exists( 'skyphusion_ai_prompt_cache_write' ) ) {
		skyphusion_ai_prompt_cache_write( 'assistant', $model, $prompt, '', $text );
	}
	return $text;
}

/**
 * Keep a chat when saving is on and the visitor consented.
 *
 * @param string $question Visitor question.
 * @param string $answer   Answer text.
 * @param bool   $consent  True when the checkbox was ticked.
 * @return void
 */
function skyphusion_ai_assistant_remember( $question, $answer, $consent ) {
	$config = skyphusion_ai_assistant_config();
	if ( empty( $config['save'] ) || ! $consent ) {
		return;
	}
	if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
		return;
	}
	$rows = get_option( skyphusion_ai_assistant_chat_option(), array() );
	if ( ! is_array( $rows ) ) {
		$rows = array();
	}
	$rows[] = array(
		'question' => $question,
		'answer'   => $answer,
		'at'       => time(),
	);
	$cap    = skyphusion_ai_assistant_chat_cap();
	if ( count( $rows ) > $cap ) {
		$rows = array_slice( $rows, -$cap );
	}
	update_option( skyphusion_ai_assistant_chat_option(), $rows, false );
}

/**
 * True when this visitor may use the assistant.
 *
 * Logged-out visitors are allowed only while the audience is public.
 * A capability flag does not admit a logged-out visitor.
 *
 * @param array<string, mixed> $config Saved choices.
 * @return bool
 */
function skyphusion_ai_assistant_allowed( $config ) {
	if ( isset( $config['audience'] ) && 'public' === (string) $config['audience'] ) {
		return true;
	}
	$user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	if ( 0 === $user ) {
		return false;
	}
	$capability = isset( $config['capability'] ) ? (string) $config['capability'] : 'read';
	if ( ! function_exists( 'current_user_can' ) ) {
		return false;
	}
	return current_user_can( $capability );
}

/**
 * True when today's assistant limit is spent.
 *
 * Zero means this job has no limit of its own.
 *
 * @param array<string, mixed> $config Saved choices.
 * @return bool
 */
function skyphusion_ai_assistant_limit_blocked( $config ) {
	$limit = isset( $config['limit'] ) ? (int) $config['limit'] : 0;
	if ( 1 > $limit ) {
		return false;
	}
	$today = function_exists( 'skyphusion_ai_neuron_today' ) ? skyphusion_ai_neuron_today() : gmdate( 'Y-m-d' );
	if ( ! isset( $config['day'] ) || (string) $config['day'] !== $today ) {
		return false;
	}
	return (int) $config['neurons'] >= $limit;
}

/**
 * Add measured neurons to today's assistant total.
 *
 * @param int $neurons Neurons from the call.
 * @return void
 */
function skyphusion_ai_assistant_add_neurons( $neurons ) {
	if ( ! function_exists( 'update_option' ) ) {
		return;
	}
	$neurons = (int) $neurons;
	if ( 0 > $neurons ) {
		$neurons = 0;
	}
	$config = skyphusion_ai_assistant_config();
	$today  = function_exists( 'skyphusion_ai_neuron_today' ) ? skyphusion_ai_neuron_today() : gmdate( 'Y-m-d' );
	$used   = 0;
	if ( isset( $config['day'] ) && (string) $config['day'] === $today ) {
		$used = (int) $config['neurons'];
	}
	$config['day']     = $today;
	$config['neurons'] = $used + $neurons;
	update_option( skyphusion_ai_assistant_option_name(), $config, false );
}

/**
 * True when the question matches a blocked topic.
 *
 * @param string               $question Visitor question.
 * @param array<string, mixed> $config   Saved choices.
 * @return bool
 */
function skyphusion_ai_assistant_topic_blocked( $question, $config ) {
	$hay  = strtolower( (string) $question );
	$list = array();
	if ( isset( $config['blocked'] ) && is_array( $config['blocked'] ) ) {
		$list = $config['blocked'];
	}
	foreach ( $list as $topic ) {
		$topic = strtolower( trim( (string) $topic ) );
		if ( '' === $topic ) {
			continue;
		}
		if ( false !== strpos( $hay, $topic ) ) {
			return true;
		}
	}
	return false;
}

/**
 * How many turns a chat can send before the older ones are summarized.
 *
 * @return int
 */
function skyphusion_ai_assistant_compact_limit() {
	return 10;
}

/**
 * Question and answer pairs. An address is not copied.
 *
 * The saved consent log is not this list.
 *
 * @param mixed $history Earlier pairs.
 * @return array<int, array{question: string, answer: string}>
 */
function skyphusion_ai_assistant_history_rows( $history ) {
	$rows = array();
	if ( ! is_array( $history ) ) {
		return $rows;
	}
	foreach ( $history as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$asked = isset( $row['question'] ) ? trim( (string) $row['question'] ) : '';
		$reply = isset( $row['answer'] ) ? trim( (string) $row['answer'] ) : '';
		if ( '' === $asked && '' === $reply ) {
			continue;
		}
		$rows[] = array(
			'question' => $asked,
			'answer'   => $reply,
		);
	}
	return $rows;
}

/**
 * Lines for one pair.
 *
 * @param mixed $history Earlier pairs.
 * @return string[]
 */
function skyphusion_ai_assistant_history_pairs( $history ) {
	$pairs = array();
	foreach ( skyphusion_ai_assistant_history_rows( $history ) as $row ) {
		$pairs[] = 'Visitor: ' . $row['question'] . "\n" . 'Assistant: ' . $row['answer'];
	}
	return $pairs;
}

/**
 * Earlier pairs that fit in the turn window.
 *
 * The saved consent log is not this list.
 *
 * @param mixed $history Earlier pairs.
 * @param int   $turns   How many pairs to keep.
 * @return string
 */
function skyphusion_ai_assistant_history_text( $history, $turns ) {
	$turns = (int) $turns;
	if ( 1 > $turns ) {
		return '';
	}
	$pairs = skyphusion_ai_assistant_history_pairs( $history );
	if ( count( $pairs ) > $turns ) {
		$pairs = array_slice( $pairs, -1 * $turns );
	}
	return implode( "\n", $pairs );
}

/**
 * One short block for the turns that are older than the window.
 *
 * This is one text call. It does not store the chat.
 *
 * @param string[] $older Older pair lines.
 * @return string
 */
function skyphusion_ai_assistant_summary( $older ) {
	if ( ! is_array( $older ) || array() === $older ) {
		return '';
	}
	$prompt = __( 'Summarize these earlier turns in one short block.', 'skyphusion-ai' ) . "\n" . implode( "\n", $older );
	$text   = skyphusion_ai_assistant_text( $prompt );
	if ( is_wp_error( $text ) ) {
		return '';
	}
	return skyphusion_ai_assistant_plain( $text, 400 );
}

/**
 * Text to send for earlier turns.
 *
 * Ten turns or fewer use the window. More than ten turns send one summary
 * of the older turns plus the latest turns. The page transcript is separate.
 *
 * @param mixed $history Earlier pairs.
 * @param int   $turns   How many latest pairs to send in full.
 * @return string
 */
function skyphusion_ai_assistant_compact_history( $history, $turns ) {
	$turns = (int) $turns;
	if ( 1 > $turns ) {
		return '';
	}
	$limit = skyphusion_ai_assistant_compact_limit();
	if ( $turns > $limit ) {
		$turns = $limit;
	}
	$pairs = skyphusion_ai_assistant_history_pairs( $history );
	if ( count( $pairs ) <= $limit ) {
		return skyphusion_ai_assistant_history_text( $history, $turns );
	}
	$older   = array_slice( $pairs, 0, count( $pairs ) - $turns );
	$latest  = array_slice( $pairs, -1 * $turns );
	$summary = skyphusion_ai_assistant_summary( $older );
	$tail    = implode( "\n", $latest );
	if ( '' === $summary ) {
		return $tail;
	}
	return __( 'Earlier talk:', 'skyphusion-ai' ) . "\n" . $summary . "\n" . $tail;
}

/**
 * Hidden fields that repeat the transcript. They do not store an address.
 *
 * @param mixed $rows Transcript rows.
 * @return string
 */
function skyphusion_ai_assistant_turn_fields( $rows ) {
	if ( ! is_array( $rows ) || ! function_exists( 'esc_attr' ) ) {
		return '';
	}
	$html = '';
	foreach ( skyphusion_ai_assistant_history_rows( $rows ) as $row ) {
		$html .= '<input type="hidden" name="skyphusion_ai_assistant_turn_question[]" value="' . esc_attr( $row['question'] ) . '" />';
		$html .= '<input type="hidden" name="skyphusion_ai_assistant_turn_answer[]" value="' . esc_attr( $row['answer'] ) . '" />';
	}
	return $html;
}

/**
 * True when public mode must check Turnstile first.
 *
 * Missing keys skip the check. The check function is not used to detect keys.
 *
 * @param array<string, mixed> $config Saved choices.
 * @return bool
 */
function skyphusion_ai_assistant_turnstile_required( $config ) {
	if ( ! isset( $config['audience'] ) || 'public' !== (string) $config['audience'] ) {
		return false;
	}
	if ( ! function_exists( 'cfturnstile_check' ) || ! function_exists( 'get_option' ) ) {
		return false;
	}
	$site   = get_option( 'cfturnstile_key', '' );
	$secret = get_option( 'cfturnstile_secret', '' );
	if ( ! is_string( $site ) || '' === trim( $site ) ) {
		return false;
	}
	if ( ! is_string( $secret ) || '' === trim( $secret ) ) {
		return false;
	}
	return true;
}

/**
 * Model id for the optional question check.
 *
 * @return string
 */
function skyphusion_ai_assistant_safety_model() {
	return '@cf/meta/llama-guard-3-8b';
}

/**
 * True when the question check says safe.
 *
 * A failed check does not call the writing model.
 *
 * @param string $question Visitor question.
 * @return bool
 */
function skyphusion_ai_assistant_safety_ok( $question ) {
	$model       = skyphusion_ai_assistant_safety_model();
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
	$url = '';
	if ( function_exists( 'cloudflare_ai_provider_run_url' ) ) {
		$url = cloudflare_ai_provider_run_url( $credentials['account_id'], $gateway, $model );
	}
	if ( '' === $url || '' === $credentials['token'] || ! function_exists( 'wp_remote_post' ) ) {
		return false;
	}
	$body = array();
	if ( function_exists( 'cloudflare_ai_provider_chat_body' ) ) {
		$body = cloudflare_ai_provider_chat_body(
			array(
				'model'    => $model,
				'messages' => array(
					array(
						'role' => 'user',
						'text' => (string) $question,
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
	$before = 0;
	if ( function_exists( 'skyphusion_ai_neuron_state' ) ) {
		$before = (int) skyphusion_ai_neuron_state()['neurons'];
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
		if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
			skyphusion_ai_neuron_record( 'text', $model, $response, 'assistant' );
		}
		return false;
	}
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
		skyphusion_ai_neuron_record( 'text', $model, is_array( $decoded ) ? $decoded : array(), 'assistant' );
	}
	$after = $before;
	if ( function_exists( 'skyphusion_ai_neuron_state' ) ) {
		$after = (int) skyphusion_ai_neuron_state()['neurons'];
	}
	skyphusion_ai_assistant_add_neurons( $after - $before );
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return false;
	}
	$read = array();
	if ( function_exists( 'cloudflare_ai_provider_read_text' ) ) {
		$read = cloudflare_ai_provider_read_text( is_array( $decoded ) ? $decoded : array() );
	}
	$text = '';
	if ( isset( $read['candidates'][0]['text'] ) ) {
		$text = strtolower( trim( (string) $read['candidates'][0]['text'] ) );
	}
	return 'safe' === $text;
}

/**
 * Marker for the admin usage bar. Empty when this job has no limit.
 *
 * @param array<string, mixed> $config Saved choices.
 * @return string
 */
function skyphusion_ai_assistant_usage_marker( $config ) {
	$limit = isset( $config['limit'] ) ? (int) $config['limit'] : 0;
	if ( 1 > $limit ) {
		return '';
	}
	$today = function_exists( 'skyphusion_ai_neuron_today' ) ? skyphusion_ai_neuron_today() : gmdate( 'Y-m-d' );
	$used  = 0;
	if ( isset( $config['day'] ) && (string) $config['day'] === $today ) {
		$used = (int) $config['neurons'];
	}
	$percent = (int) floor( ( $used * 100 ) / $limit );
	if ( 100 < $percent ) {
		$percent = 100;
	}
	if ( 100 <= $percent ) {
		return 'usage-red';
	}
	if ( 80 <= $percent ) {
		return 'usage-amber';
	}
	return 'usage-ok';
}

/**
 * Answer one question from the embedding index.
 *
 * The third argument is earlier pairs for this question only. The consent
 * log is not copied into the prompt.
 *
 * @param string $question Visitor question.
 * @param bool   $consent  True when the visitor agreed to save the chat.
 * @param mixed  $history  Earlier pairs. Not the consent log.
 * @return array{state: string, answer: string, citations: array<int, array{title: string, url: string}>}
 */
function skyphusion_ai_assistant_ask( $question, $consent, $history = array() ) {
	$config = skyphusion_ai_assistant_config();
	if ( empty( $config['enabled'] ) ) {
		return skyphusion_ai_assistant_result( 'off', '', array() );
	}
	$question = trim( (string) $question );
	if ( '' === $question ) {
		return skyphusion_ai_assistant_result( 'empty', skyphusion_ai_assistant_miss_text(), array() );
	}
	if ( ! skyphusion_ai_assistant_allowed( $config ) ) {
		return skyphusion_ai_assistant_result( 'closed', '', array() );
	}
	if ( skyphusion_ai_assistant_topic_blocked( $question, $config ) ) {
		$reply = isset( $config['blocked_reply'] ) ? (string) $config['blocked_reply'] : '';
		if ( '' === $reply ) {
			$reply = skyphusion_ai_assistant_miss_text();
		}
		return skyphusion_ai_assistant_result( 'blocked', $reply, array() );
	}
	if ( skyphusion_ai_assistant_limit_blocked( $config ) ) {
		return skyphusion_ai_assistant_result( 'blocked', __( 'The assistant limit is spent. It resumes at 00:00 UTC. The other tools continue.', 'skyphusion-ai' ), array() );
	}
	if ( skyphusion_ai_assistant_turnstile_required( $config ) && ! cfturnstile_check() ) {
		return skyphusion_ai_assistant_result( 'closed', '', array() );
	}
	if ( function_exists( 'skyphusion_ai_neuron_refusal' ) ) {
		$refusal = skyphusion_ai_neuron_refusal();
		if ( is_wp_error( $refusal ) ) {
			$text = function_exists( 'skyphusion_ai_neuron_refusal_text' ) ? skyphusion_ai_neuron_refusal_text() : '';
			return skyphusion_ai_assistant_result( 'blocked', $text, array() );
		}
	}
	if ( function_exists( 'skyphusion_ai_tool_refusal' ) ) {
		$tool_refusal = skyphusion_ai_tool_refusal( 'assistant' );
		if ( is_wp_error( $tool_refusal ) ) {
			$text = function_exists( 'skyphusion_ai_tool_refusal_text' ) ? skyphusion_ai_tool_refusal_text() : '';
			return skyphusion_ai_assistant_result( 'blocked', $text, array() );
		}
	}
	if ( ! empty( $config['safety'] ) && ! skyphusion_ai_assistant_safety_ok( $question ) ) {
		return skyphusion_ai_assistant_result( 'blocked', skyphusion_ai_assistant_miss_text(), array() );
	}
	$found = array( 'state' => 'blocked' );
	if ( function_exists( 'skyphusion_ai_embed_search' ) ) {
		$found = skyphusion_ai_embed_search( $question, 5 );
	}
	if ( is_array( $found ) && isset( $found['state'] ) && 'not_created' === $found['state'] ) {
		return skyphusion_ai_assistant_result( 'not_created', __( 'The assistant needs the embedding index first.', 'skyphusion-ai' ), array() );
	}
	$citations = skyphusion_ai_assistant_citations( $found );
	if ( array() === $citations ) {
		return skyphusion_ai_assistant_result( 'empty', skyphusion_ai_assistant_miss_text(), array() );
	}
	$prompt = skyphusion_ai_assistant_prompt( $question, $citations );
	$rules  = isset( $config['rules'] ) ? trim( (string) $config['rules'] ) : '';
	if ( '' !== $rules ) {
		$prompt .= "\n" . $rules;
	}
	$before = 0;
	if ( function_exists( 'skyphusion_ai_neuron_state' ) ) {
		$before = (int) skyphusion_ai_neuron_state()['neurons'];
	}
	$earlier = skyphusion_ai_assistant_compact_history( $history, isset( $config['turns'] ) ? (int) $config['turns'] : 10 );
	if ( '' !== $earlier ) {
		$prompt .= "\n" . $earlier;
	}
	$text  = skyphusion_ai_assistant_text( $prompt );
	$after = $before;
	if ( function_exists( 'skyphusion_ai_neuron_state' ) ) {
		$after = (int) skyphusion_ai_neuron_state()['neurons'];
	}
	skyphusion_ai_assistant_add_neurons( $after - $before );
	if ( is_wp_error( $text ) ) {
		return skyphusion_ai_assistant_result( 'blocked', __( 'The assistant could not answer.', 'skyphusion-ai' ), $citations );
	}
	if ( '' === $text ) {
		return skyphusion_ai_assistant_result( 'empty', skyphusion_ai_assistant_miss_text(), $citations );
	}
	skyphusion_ai_assistant_remember( $question, $text, (bool) $consent );
	$result               = skyphusion_ai_assistant_result( 'answered', $text, $citations );
	$transcript           = skyphusion_ai_assistant_history_rows( $history );
	$transcript[]         = array(
		'question' => $question,
		'answer'   => $text,
	);
	$result['transcript'] = $transcript;
	return $result;
}

/**
 * HTML for one result.
 *
 * @param array<string, mixed> $result Ask result.
 * @return string
 */
function skyphusion_ai_assistant_html( $result ) {
	if ( ! is_array( $result ) ) {
		return '';
	}
	$answer = isset( $result['answer'] ) ? (string) $result['answer'] : '';
	$rows   = array();
	if ( isset( $result['citations'] ) && is_array( $result['citations'] ) ) {
		$rows = $result['citations'];
	}
	$turns = array();
	if ( isset( $result['transcript'] ) && is_array( $result['transcript'] ) ) {
		$turns = skyphusion_ai_assistant_history_rows( $result['transcript'] );
	}
	if ( '' === $answer && array() === $rows && array() === $turns ) {
		return '';
	}
	$html = '<section class="skyphusion-ai-assistant">';
	if ( array() !== $turns ) {
		$html .= '<div class="skyphusion-ai-assistant-transcript">';
		foreach ( $turns as $turn ) {
			if ( '' !== $turn['question'] ) {
				$html .= '<p>' . esc_html( $turn['question'] ) . '</p>';
			}
			if ( '' !== $turn['answer'] ) {
				$html .= '<p>' . esc_html( $turn['answer'] ) . '</p>';
			}
		}
		$html .= '</div>';
	} elseif ( '' !== $answer ) {
		$html .= '<p>' . esc_html( $answer ) . '</p>';
	}
	if ( array() !== $rows ) {
		$html .= '<ul>';
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = isset( $row['title'] ) ? (string) $row['title'] : '';
			$url   = isset( $row['url'] ) ? (string) $row['url'] : '';
			$html .= '<li>';
			if ( '' !== $url ) {
				$html .= '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
			} else {
				$html .= esc_html( $title );
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
	}
	$html .= '</section>';
	return $html;
}

/**
 * Print the switches for an admin.
 *
 * @return void
 */
function skyphusion_ai_assistant_render() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'esc_html' ) ) {
		return;
	}
	$config = skyphusion_ai_assistant_config();
	echo '<hr />';
	echo '<h2>' . esc_html__( 'Site assistant', 'skyphusion-ai' ) . '</h2>';
	echo '<p>' . esc_html__( 'This assistant is off until you turn it on. The chat button stays as it is.', 'skyphusion-ai' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="skyphusion_ai_assistant_save" />';
	if ( function_exists( 'wp_nonce_field' ) ) {
		wp_nonce_field( 'skyphusion_ai_assistant_save' );
	}
	echo '<p><label><input type="checkbox" name="skyphusion_ai_assistant_enabled" value="1"';
	if ( ! empty( $config['enabled'] ) ) {
		echo ' checked="checked"';
	}
	echo ' /> ' . esc_html__( 'Show the site assistant.', 'skyphusion-ai' ) . '</label></p>';
	echo '<p><label><input type="checkbox" name="skyphusion_ai_assistant_save" value="1"';
	if ( ! empty( $config['save'] ) ) {
		echo ' checked="checked"';
	}
	echo ' /> ' . esc_html__( 'Save chats after the visitor agrees.', 'skyphusion-ai' ) . '</label></p>';
	echo '<p><label>' . esc_html__( 'Who can ask.', 'skyphusion-ai' ) . ' ';
	echo '<select name="skyphusion_ai_assistant_audience">';
	$audiences = array(
		'roles'  => __( 'Signed-in people with one capability.', 'skyphusion-ai' ),
		'public' => __( 'Every visitor.', 'skyphusion-ai' ),
	);
	foreach ( $audiences as $audience => $label ) {
		echo '<option value="' . esc_attr( $audience ) . '"';
		if ( $audience === $config['audience'] ) {
			echo ' selected="selected"';
		}
		echo '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></label></p>';
	echo '<p><label>' . esc_html__( 'Capability.', 'skyphusion-ai' ) . ' ';
	echo '<select name="skyphusion_ai_assistant_capability">';
	foreach ( skyphusion_ai_assistant_capabilities() as $capability ) {
		echo '<option value="' . esc_attr( $capability ) . '"';
		if ( $capability === $config['capability'] ) {
			echo ' selected="selected"';
		}
		echo '>' . esc_html( $capability ) . '</option>';
	}
	echo '</select></label></p>';
	echo '<p><label>' . esc_html__( 'Extra instructions.', 'skyphusion-ai' ) . ' ';
	echo '<input type="text" name="skyphusion_ai_assistant_rules" value="' . esc_attr( (string) $config['rules'] ) . '" /></label></p>';
	echo '<p><label>' . esc_html__( 'Blocked topics, one per line.', 'skyphusion-ai' ) . ' ';
	echo '<input type="text" name="skyphusion_ai_assistant_blocked" value="' . esc_attr( implode( "\n", $config['blocked'] ) ) . '" /></label></p>';
	echo '<p><label>' . esc_html__( 'Reply for a blocked topic.', 'skyphusion-ai' ) . ' ';
	echo '<input type="text" name="skyphusion_ai_assistant_blocked_reply" value="' . esc_attr( (string) $config['blocked_reply'] ) . '" /></label></p>';
	echo '<p><label><input type="checkbox" name="skyphusion_ai_assistant_safety" value="1"';
	if ( ! empty( $config['safety'] ) ) {
		echo ' checked="checked"';
	}
	echo ' /> ' . esc_html__( 'Check the question before answering.', 'skyphusion-ai' ) . '</label></p>';
	echo '<p><label>' . esc_html__( 'Assistant daily limit, in neurons. Zero means this job has no limit of its own.', 'skyphusion-ai' ) . ' ';
	echo '<input type="number" name="skyphusion_ai_assistant_limit" value="' . esc_attr( (string) $config['limit'] ) . '" min="0" step="1" /></label></p>';
	echo '<p><label>' . esc_html__( 'Earlier turns to send. Zero sends only this question.', 'skyphusion-ai' ) . ' ';
	echo '<input type="number" name="skyphusion_ai_assistant_turns" value="' . esc_attr( (string) $config['turns'] ) . '" min="0" max="10" step="1" /></label></p>';
	echo '<p>' . esc_html__( 'A chat longer than ten turns sends one short summary of the older turns.', 'skyphusion-ai' ) . '</p>';
	echo '<p>' . esc_html__( 'The page still shows every turn.', 'skyphusion-ai' ) . '</p>';
	$marker = skyphusion_ai_assistant_usage_marker( $config );
	if ( '' !== $marker ) {
		echo '<p class="' . esc_attr( $marker ) . '">' . esc_html__( 'Assistant use today.', 'skyphusion-ai' ) . '</p>';
	}
	echo '<button type="submit">' . esc_html__( 'Save the assistant choice', 'skyphusion-ai' ) . '</button>';
	echo '</form>';
}

/**
 * Store the switches from the settings form.
 *
 * @return void
 */
function skyphusion_ai_assistant_handle_save() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( function_exists( 'check_admin_referer' ) ) {
		check_admin_referer( 'skyphusion_ai_assistant_save' );
	}
	$enabled = '';
	$save    = '';
	if ( isset( $_POST['skyphusion_ai_assistant_enabled'] ) ) {
		$enabled = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_assistant_enabled'] ) );
	}
	if ( isset( $_POST['skyphusion_ai_assistant_save'] ) ) {
		$save = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_assistant_save'] ) );
	}
	$posted = array(
		'enabled' => $enabled,
		'save'    => $save,
	);
	if ( isset( $_POST['skyphusion_ai_assistant_audience'] ) ) {
		$posted['audience'] = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_assistant_audience'] ) );
	}
	if ( isset( $_POST['skyphusion_ai_assistant_capability'] ) ) {
		$posted['capability'] = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_assistant_capability'] ) );
	}
	if ( isset( $_POST['skyphusion_ai_assistant_rules'] ) ) {
		$posted['rules'] = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_assistant_rules'] ) );
	}
	if ( isset( $_POST['skyphusion_ai_assistant_blocked'] ) ) {
		$posted['blocked'] = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_assistant_blocked'] ) );
	}
	if ( isset( $_POST['skyphusion_ai_assistant_blocked_reply'] ) ) {
		$posted['blocked_reply'] = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_assistant_blocked_reply'] ) );
	}
	$posted['safety'] = isset( $_POST['skyphusion_ai_assistant_safety'] ) ? '1' : '';
	if ( isset( $_POST['skyphusion_ai_assistant_limit'] ) ) {
		$posted['limit'] = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_assistant_limit'] ) );
	}
	if ( isset( $_POST['skyphusion_ai_assistant_turns'] ) ) {
		$posted['turns'] = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_assistant_turns'] ) );
	}
	skyphusion_ai_assistant_apply( $posted );
	if ( function_exists( 'wp_safe_redirect' ) ) {
		wp_safe_redirect( admin_url( 'options-general.php?page=cloudflare-ai-search' ) );
		exit;
	}
}

/**
 * Shortcode. It prints nothing while the assistant is off.
 *
 * @return string
 */
function skyphusion_ai_assistant_shortcode() {
	$config = skyphusion_ai_assistant_config();
	if ( empty( $config['enabled'] ) || ! skyphusion_ai_assistant_allowed( $config ) ) {
		return '';
	}
	if ( function_exists( 'skyphusion_ai_worker_url' ) && function_exists( 'skyphusion_ai_worker_edge_html' ) ) {
		$worker_url = skyphusion_ai_worker_url();
		if ( '' !== $worker_url ) {
			$model = function_exists( 'skyphusion_ai_assistant_model' ) ? skyphusion_ai_assistant_model() : '';
			return skyphusion_ai_worker_edge_html( $worker_url, $model );
		}
	}
	$html = '';
	$last = array();
	if ( function_exists( 'get_transient' ) ) {
		$stored = get_transient( 'skyphusion_ai_assistant_last' );
		if ( is_array( $stored ) ) {
			$last  = $stored;
			$html .= skyphusion_ai_assistant_html( $last );
		}
	}
	$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	$html .= '<input type="hidden" name="action" value="skyphusion_ai_assistant" />';
	if ( function_exists( 'wp_nonce_field' ) ) {
		$html .= wp_nonce_field( 'skyphusion_ai_assistant', '_wpnonce', true, false );
	}
	$transcript = array();
	if ( isset( $last['transcript'] ) && is_array( $last['transcript'] ) ) {
		$transcript = $last['transcript'];
	}
	$html .= skyphusion_ai_assistant_turn_fields( $transcript );
	$html .= '<p><label>' . esc_html__( 'Ask this site', 'skyphusion-ai' ) . ' ';
	$html .= '<input type="text" name="skyphusion_ai_assistant_question" value="" /></label></p>';
	$html .= '<p><label><input type="checkbox" name="skyphusion_ai_assistant_consent" value="1" /> ';
	$html .= esc_html__( 'Save this chat on this site.', 'skyphusion-ai' ) . '</label></p>';
	$html .= '<button type="submit">' . esc_html__( 'Ask', 'skyphusion-ai' ) . '</button>';
	$html .= '</form>';
	return $html;
}

/**
 * Answer a posted question and return to the page.
 *
 * @return void
 */
function skyphusion_ai_assistant_handle() {
	if ( function_exists( 'check_admin_referer' ) ) {
		check_admin_referer( 'skyphusion_ai_assistant' );
	}
	$question = '';
	if ( isset( $_POST['skyphusion_ai_assistant_question'] ) ) {
		$question = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_assistant_question'] ) );
	}
	$consent   = isset( $_POST['skyphusion_ai_assistant_consent'] );
	$questions = array();
	$answers   = array();
	if ( isset( $_POST['skyphusion_ai_assistant_turn_question'] ) && is_array( $_POST['skyphusion_ai_assistant_turn_question'] ) ) {
		$questions = array_map( 'sanitize_text_field', wp_unslash( $_POST['skyphusion_ai_assistant_turn_question'] ) );
	}
	if ( isset( $_POST['skyphusion_ai_assistant_turn_answer'] ) && is_array( $_POST['skyphusion_ai_assistant_turn_answer'] ) ) {
		$answers = array_map( 'sanitize_text_field', wp_unslash( $_POST['skyphusion_ai_assistant_turn_answer'] ) );
	}
	$cap = 30;
	if ( count( $questions ) > $cap ) {
		$questions = array_slice( $questions, -1 * $cap );
	}
	if ( count( $answers ) > $cap ) {
		$answers = array_slice( $answers, -1 * $cap );
	}
	$count = count( $questions );
	if ( count( $answers ) < $count ) {
		$count = count( $answers );
	}
	$history = array();
	for ( $index = 0; $index < $count; $index++ ) {
		$history[] = array(
			'question' => $questions[ $index ],
			'answer'   => $answers[ $index ],
		);
	}
	$result = skyphusion_ai_assistant_ask( $question, $consent, $history );
	if ( function_exists( 'set_transient' ) ) {
		set_transient( 'skyphusion_ai_assistant_last', $result, 120 );
	}
	if ( function_exists( 'wp_safe_redirect' ) ) {
		$target = function_exists( 'wp_get_referer' ) ? wp_get_referer() : '';
		if ( ! is_string( $target ) || '' === $target ) {
			$target = home_url( '/' );
		}
		wp_safe_redirect( $target );
		exit;
	}
}

/**
 * Register the shortcode.
 *
 * @return void
 */
function skyphusion_ai_assistant_boot() {
	if ( function_exists( 'add_shortcode' ) ) {
		add_shortcode( 'skyphusion_ai_assistant', 'skyphusion_ai_assistant_shortcode' );
	}
}

add_action( 'init', 'skyphusion_ai_assistant_boot' );
add_action( 'admin_post_skyphusion_ai_assistant_save', 'skyphusion_ai_assistant_handle_save' );
add_action( 'admin_post_skyphusion_ai_assistant', 'skyphusion_ai_assistant_handle' );
add_action( 'admin_post_nopriv_skyphusion_ai_assistant', 'skyphusion_ai_assistant_handle' );
