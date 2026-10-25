<?php
/**
 * Site assistant. It answers from stored pages and cites them.
 *
 * The control is off until an admin turns it on. The chat button is a
 * separate snippet and stays as it is. A saved chat needs consent.
 * An IP address is not stored.
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
 * Switches. Both default off.
 *
 * @return array{enabled: int, save: int}
 */
function skyphusion_ai_assistant_config() {
	$stored = array();
	if ( function_exists( 'get_option' ) ) {
		$stored = get_option( skyphusion_ai_assistant_option_name(), array() );
	}
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return array(
		'enabled' => empty( $stored['enabled'] ) ? 0 : 1,
		'save'    => empty( $stored['save'] ) ? 0 : 1,
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
 * @param string $prompt Prompt text.
 * @return string|WP_Error
 */
function skyphusion_ai_assistant_text( $prompt ) {
	if ( function_exists( 'skyphusion_ai_neuron_refusal' ) ) {
		$refusal = skyphusion_ai_neuron_refusal();
		if ( is_wp_error( $refusal ) ) {
			return $refusal;
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
		return new WP_Error( 'skyphusion_ai_assistant', __( 'The assistant could not answer.', 'skyphusion-ai' ) );
	}
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
		skyphusion_ai_neuron_record( 'text', $model, $decoded );
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error( 'skyphusion_ai_assistant', __( 'The assistant could not answer.', 'skyphusion-ai' ) );
	}
	$read = array();
	if ( function_exists( 'cloudflare_ai_provider_read_text' ) ) {
		$read = cloudflare_ai_provider_read_text( is_array( $decoded ) ? $decoded : array() );
	}
	$text = '';
	if ( isset( $read['candidates'][0]['text'] ) ) {
		$text = trim( (string) $read['candidates'][0]['text'] );
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
 * Answer one question from the embedding index.
 *
 * @param string $question Visitor question.
 * @param bool   $consent  True when the visitor agreed to save the chat.
 * @return array{state: string, answer: string, citations: array<int, array{title: string, url: string}>}
 */
function skyphusion_ai_assistant_ask( $question, $consent ) {
	$config = skyphusion_ai_assistant_config();
	if ( empty( $config['enabled'] ) ) {
		return skyphusion_ai_assistant_result( 'off', '', array() );
	}
	$question = trim( (string) $question );
	if ( '' === $question ) {
		return skyphusion_ai_assistant_result( 'empty', skyphusion_ai_assistant_miss_text(), array() );
	}
	if ( function_exists( 'skyphusion_ai_neuron_refusal' ) ) {
		$refusal = skyphusion_ai_neuron_refusal();
		if ( is_wp_error( $refusal ) ) {
			$text = function_exists( 'skyphusion_ai_neuron_refusal_text' ) ? skyphusion_ai_neuron_refusal_text() : '';
			return skyphusion_ai_assistant_result( 'blocked', $text, array() );
		}
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
	$text = skyphusion_ai_assistant_text( skyphusion_ai_assistant_prompt( $question, $citations ) );
	if ( is_wp_error( $text ) ) {
		return skyphusion_ai_assistant_result( 'blocked', __( 'The assistant could not answer.', 'skyphusion-ai' ), $citations );
	}
	if ( '' === $text ) {
		return skyphusion_ai_assistant_result( 'empty', skyphusion_ai_assistant_miss_text(), $citations );
	}
	skyphusion_ai_assistant_remember( $question, $text, (bool) $consent );
	return skyphusion_ai_assistant_result( 'answered', $text, $citations );
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
	if ( '' === $answer && array() === $rows ) {
		return '';
	}
	$html = '<section class="skyphusion-ai-assistant">';
	if ( '' !== $answer ) {
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
	skyphusion_ai_assistant_apply(
		array(
			'enabled' => $enabled,
			'save'    => $save,
		)
	);
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
	if ( empty( $config['enabled'] ) ) {
		return '';
	}
	$html = '';
	if ( function_exists( 'get_transient' ) ) {
		$last = get_transient( 'skyphusion_ai_assistant_last' );
		if ( is_array( $last ) ) {
			$html .= skyphusion_ai_assistant_html( $last );
		}
	}
	$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	$html .= '<input type="hidden" name="action" value="skyphusion_ai_assistant" />';
	if ( function_exists( 'wp_nonce_field' ) ) {
		$html .= wp_nonce_field( 'skyphusion_ai_assistant', '_wpnonce', true, false );
	}
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
	$consent = isset( $_POST['skyphusion_ai_assistant_consent'] );
	$result  = skyphusion_ai_assistant_ask( $question, $consent );
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
