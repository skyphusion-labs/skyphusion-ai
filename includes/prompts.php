<?php
/**
 * Prompts this plugin sends, with a reset and a one-call cost test.
 *
 * The empty text job, the site assistant, and the link chat use the saved
 * text. The translation helper does not send its row. The test does.
 * A spent neuron budget does not leave the site.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option that stores prompt text which differs from the default.
 *
 * @return string
 */
function skyphusion_ai_prompt_option() {
	return 'skyphusion_ai_prompts';
}

/**
 * Option that stores the last test receipt.
 *
 * @return string
 */
function skyphusion_ai_prompt_last_option() {
	return 'skyphusion_ai_prompt_last';
}

/**
 * Longest saved prompt, in characters.
 *
 * @return int
 */
function skyphusion_ai_prompt_cap() {
	return 4000;
}

/**
 * Owned prompts. The default is what the plugin sends before an edit.
 *
 * @return array<string, array{label: string, note: string, default: string}>
 */
function skyphusion_ai_prompt_definitions() {
	return array(
		'backfill_alt'         => array(
			'label'   => __( 'Alt text', 'skyphusion-ai' ),
			'note'    => __( 'The empty text job sends this prompt. {title} is the image title.', 'skyphusion-ai' ),
			'default' => 'Write one alt sentence for this image. Title: {title}',
		),
		'backfill_description' => array(
			'label'   => __( 'SEO description', 'skyphusion-ai' ),
			'note'    => __( 'The empty text job sends this prompt. {title} is the page title.', 'skyphusion-ai' ),
			'default' => 'Write one SEO description for this page. Title: {title}',
		),
		'assistant'            => array(
			'label'   => __( 'Site assistant', 'skyphusion-ai' ),
			'note'    => __( 'The site assistant sends this prompt. {pages} is the page list. {question} is the visitor question.', 'skyphusion-ai' ),
			'default' => "Answer from these pages only.\n{pages}\nQuestion: {question}",
		),
		'links'                => array(
			'label'   => __( 'Internal links', 'skyphusion-ai' ),
			'note'    => __( 'The link chat sends this prompt. {suggestions} is the suggestion list. {question} is the editor question.', 'skyphusion-ai' ),
			'default' => "Answer only from these suggestions. Do not change the post.\n{suggestions}\nQuestion: {question}",
		),
		'translate'            => array(
			'label'   => __( 'Translation', 'skyphusion-ai' ),
			'note'    => __( 'The translation helper does not send this instruction. The test does. {text} is a sample sentence.', 'skyphusion-ai' ),
			'default' => 'Translate the text. Keep the meaning. Text: {text}',
		),
	);
}

/**
 * Sample values for one test call.
 *
 * @return array<string, string>
 */
function skyphusion_ai_prompt_samples() {
	return array(
		'title'       => 'Linen shirt',
		'pages'       => '- Linen shirt',
		'question'    => 'Is this linen?',
		'phrase'      => 'linen shirt',
		'suggestions' => '- Linen shirt',
		'text'        => 'Linen shirt',
	);
}

/**
 * Saved overrides. Unknown keys are dropped.
 *
 * @return array<string, string>
 */
function skyphusion_ai_prompt_overrides() {
	if ( ! function_exists( 'get_option' ) ) {
		return array();
	}
	$stored = get_option( skyphusion_ai_prompt_option(), array() );
	if ( ! is_array( $stored ) ) {
		return array();
	}
	$out = array();
	foreach ( skyphusion_ai_prompt_definitions() as $key => $def ) {
		if ( array_key_exists( $key, $stored ) ) {
			$out[ $key ] = (string) $stored[ $key ];
		}
	}
	return $out;
}

/**
 * Write the override map. An empty map removes the option when it can.
 *
 * @param array<string, string> $overrides Saved text.
 * @return void
 */
function skyphusion_ai_prompt_store( $overrides ) {
	if ( ! function_exists( 'update_option' ) ) {
		return;
	}
	if ( array() === $overrides && function_exists( 'delete_option' ) ) {
		delete_option( skyphusion_ai_prompt_option() );
		return;
	}
	update_option( skyphusion_ai_prompt_option(), $overrides, false );
}

/**
 * Strip tags and cap the length.
 *
 * @param string $text Raw prompt.
 * @return string
 */
function skyphusion_ai_prompt_clean( $text ) {
	$text     = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
	$stripped = preg_replace( '/<[^>]*>/', '', $text );
	$text     = trim( is_string( $stripped ) ? $stripped : $text );
	$cap      = skyphusion_ai_prompt_cap();
	if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
		if ( mb_strlen( $text ) > $cap ) {
			return mb_substr( $text, 0, $cap );
		}
		return $text;
	}
	if ( strlen( $text ) > $cap ) {
		return substr( $text, 0, $cap );
	}
	return $text;
}

/**
 * Save one prompt. Text that matches the default is not stored.
 *
 * @param string $key  Prompt key.
 * @param string $text Prompt text.
 * @return void
 */
function skyphusion_ai_prompt_assign( $key, $text ) {
	$defs = skyphusion_ai_prompt_definitions();
	if ( ! isset( $defs[ $key ] ) ) {
		return;
	}
	$text   = skyphusion_ai_prompt_clean( $text );
	$stored = skyphusion_ai_prompt_overrides();
	if ( $text === $defs[ $key ]['default'] ) {
		unset( $stored[ $key ] );
	} else {
		$stored[ $key ] = $text;
	}
	skyphusion_ai_prompt_store( $stored );
}

/**
 * Restore one prompt to the default.
 *
 * @param string $key Prompt key.
 * @return void
 */
function skyphusion_ai_prompt_reset( $key ) {
	$stored = skyphusion_ai_prompt_overrides();
	unset( $stored[ $key ] );
	skyphusion_ai_prompt_store( $stored );
}

/**
 * Prompt text before placeholders are filled.
 *
 * @param string $key Prompt key.
 * @return string
 */
function skyphusion_ai_prompt_raw( $key ) {
	$defs = skyphusion_ai_prompt_definitions();
	if ( ! isset( $defs[ $key ] ) ) {
		return '';
	}
	$stored = skyphusion_ai_prompt_overrides();
	if ( array_key_exists( $key, $stored ) ) {
		return $stored[ $key ];
	}
	return $defs[ $key ]['default'];
}

/**
 * Let a filter replace the filled prompt.
 *
 * The test harness defines apply_filters with two parameters. A third
 * argument fatals there. WordPress accepts the key as well.
 *
 * @param string $text Filled prompt.
 * @param string $key  Prompt key.
 * @return string
 */
function skyphusion_ai_prompt_apply( $text, $key ) {
	if ( ! function_exists( 'apply_filters' ) ) {
		return (string) $text;
	}
	$ref = new ReflectionFunction( 'apply_filters' );
	if ( $ref->isVariadic() || $ref->getNumberOfParameters() >= 3 ) {
		return (string) apply_filters( 'skyphusion_ai_prompt', $text, $key );
	}
	return (string) apply_filters( 'skyphusion_ai_prompt', $text );
}

/**
 * Fill placeholders, then run the filter.
 *
 * An empty {pages} value drops the placeholder line so the assistant
 * prompt stays one line break, which is the text it already sent.
 *
 * @param string               $key  Prompt key.
 * @param array<string,string> $vars Placeholder values.
 * @return string
 */
function skyphusion_ai_prompt_fill( $key, $vars ) {
	$text = skyphusion_ai_prompt_raw( $key );
	if ( isset( $vars['pages'] ) && '' === (string) $vars['pages'] ) {
		$text = str_replace( "{pages}\n", '', $text );
	}
	foreach ( $vars as $name => $value ) {
		if ( ! is_string( $name ) || ! preg_match( '/^[a-z]+$/', $name ) ) {
			continue;
		}
		$text = str_replace( '{' . $name . '}', (string) $value, $text );
	}
	return skyphusion_ai_prompt_apply( $text, (string) $key );
}

/**
 * Last test receipt. Missing fields read as empty.
 *
 * @return array{key: string, neurons: int, text: string, error: string}
 */
function skyphusion_ai_prompt_last() {
	$empty = array(
		'key'     => '',
		'neurons' => 0,
		'text'    => '',
		'error'   => '',
	);
	if ( ! function_exists( 'get_option' ) ) {
		return $empty;
	}
	$row = get_option( skyphusion_ai_prompt_last_option(), array() );
	if ( ! is_array( $row ) ) {
		return $empty;
	}
	$empty['key']     = isset( $row['key'] ) ? (string) $row['key'] : '';
	$empty['neurons'] = isset( $row['neurons'] ) ? (int) $row['neurons'] : 0;
	$empty['text']    = isset( $row['text'] ) ? (string) $row['text'] : '';
	$empty['error']   = isset( $row['error'] ) ? (string) $row['error'] : '';
	return $empty;
}

/**
 * Store the last test. The answer is capped so the option stays small.
 *
 * @param string $key     Prompt key.
 * @param int    $neurons Neurons this call added.
 * @param string $text    Answer text.
 * @param string $error   Error text. Empty on success.
 * @return array{key: string, neurons: int, text: string, error: string}
 */
function skyphusion_ai_prompt_remember( $key, $neurons, $text, $error ) {
	$text = trim( (string) $text );
	if ( function_exists( 'mb_substr' ) ) {
		$text = mb_substr( $text, 0, 240 );
	} else {
		$text = substr( $text, 0, 240 );
	}
	$row = array(
		'key'     => (string) $key,
		'neurons' => (int) $neurons,
		'text'    => $text,
		'error'   => (string) $error,
	);
	if ( function_exists( 'update_option' ) ) {
		update_option( skyphusion_ai_prompt_last_option(), $row, false );
	}
	return $row;
}

/**
 * Writing model for the cost test.
 *
 * @return string
 */
function skyphusion_ai_prompt_model() {
	if ( function_exists( 'skyphusion_ai_model_choice' ) ) {
		$chosen = skyphusion_ai_model_choice( 'writing' );
		if ( '' !== $chosen ) {
			return $chosen;
		}
	}
	return '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
}

/**
 * Read the answer text from a Workers AI body.
 *
 * @param mixed $decoded Response JSON.
 * @return string
 */
function skyphusion_ai_prompt_read( $decoded ) {
	if ( ! is_array( $decoded ) || ! function_exists( 'cloudflare_ai_provider_read_text' ) ) {
		return '';
	}
	$read = cloudflare_ai_provider_read_text( $decoded );
	if ( isset( $read['candidates'][0]['text'] ) ) {
		return trim( (string) $read['candidates'][0]['text'] );
	}
	return '';
}

/**
 * Run one call for one prompt and record the neuron cost.
 *
 * @param string $key Prompt key.
 * @return array{key: string, neurons: int, text: string, error: string}
 */
function skyphusion_ai_prompt_test( $key ) {
	$key  = (string) $key;
	$defs = skyphusion_ai_prompt_definitions();
	if ( ! isset( $defs[ $key ] ) ) {
		return skyphusion_ai_prompt_remember( $key, 0, '', __( 'That prompt is not on this screen.', 'skyphusion-ai' ) );
	}
	$prompt = trim( skyphusion_ai_prompt_fill( $key, skyphusion_ai_prompt_samples() ) );
	if ( '' === $prompt ) {
		return skyphusion_ai_prompt_remember( $key, 0, '', __( 'The prompt is empty.', 'skyphusion-ai' ) );
	}
	if ( function_exists( 'skyphusion_ai_neuron_refusal' ) ) {
		$refusal = skyphusion_ai_neuron_refusal();
		if ( is_wp_error( $refusal ) ) {
			return skyphusion_ai_prompt_remember( $key, 0, '', (string) $refusal->message );
		}
	}
	if ( function_exists( 'skyphusion_ai_tool_refusal' ) ) {
		$tool_refusal = skyphusion_ai_tool_refusal( 'text' );
		if ( is_wp_error( $tool_refusal ) ) {
			return skyphusion_ai_prompt_remember( $key, 0, '', skyphusion_ai_tool_refusal_text() );
		}
	}
	$model       = skyphusion_ai_prompt_model();
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
	$failed = __( 'The prompt test could not run.', 'skyphusion-ai' );
	if ( '' === $url || '' === $credentials['token'] || ! function_exists( 'wp_remote_post' ) ) {
		return skyphusion_ai_prompt_remember( $key, 0, '', $failed );
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
		return skyphusion_ai_prompt_remember( $key, 0, '', $failed );
	}
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$before  = 0;
	if ( function_exists( 'skyphusion_ai_neuron_state' ) ) {
		$state  = skyphusion_ai_neuron_state();
		$before = (int) $state['neurons'];
	}
	$after = $before;
	if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
		$recorded = skyphusion_ai_neuron_record( 'text', $model, $decoded );
		$after    = (int) $recorded['neurons'];
	}
	$neurons = $after - $before;
	if ( $neurons < 0 ) {
		$neurons = 0;
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		if ( function_exists( 'skyphusion_ai_gateway_note' ) ) {
			skyphusion_ai_gateway_note( $url, 'text', $response );
		}
		return skyphusion_ai_prompt_remember( $key, $neurons, '', $failed );
	}
	return skyphusion_ai_prompt_remember( $key, $neurons, skyphusion_ai_prompt_read( $decoded ), '' );
}

/**
 * Print the prompts, the reset, and the test.
 *
 * @return void
 */
function skyphusion_ai_prompt_render() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'esc_html' ) ) {
		return;
	}
	echo '<hr />';
	echo '<h2>' . esc_html__( 'Prompts', 'skyphusion-ai' ) . '</h2>';
	echo '<p>' . esc_html__( 'These are the prompts this plugin sends on its own behalf.', 'skyphusion-ai' ) . '</p>';
	echo '<p>' . esc_html__( 'Reset restores one prompt. A test runs one call and shows the neuron cost.', 'skyphusion-ai' ) . '</p>';
	echo '<p>' . esc_html__( 'The empty text job, the site assistant, and the link chat use the saved text.', 'skyphusion-ai' ) . '</p>';
	$last = skyphusion_ai_prompt_last();
	if ( '' === $last['key'] && '' === $last['error'] ) {
		echo '<p>' . esc_html__( 'No test has run.', 'skyphusion-ai' ) . '</p>';
	} else {
		if ( '' !== $last['error'] ) {
			echo '<p>' . esc_html( $last['error'] ) . '</p>';
		}
		if ( '' !== $last['key'] && '' === $last['error'] ) {
			$defs  = skyphusion_ai_prompt_definitions();
			$label = isset( $defs[ $last['key'] ] ) ? $defs[ $last['key'] ]['label'] : $last['key'];
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: prompt name, 2: neurons used by the test. */
					__( 'The %1$s test used %2$d neurons.', 'skyphusion-ai' ),
					$label,
					(int) $last['neurons']
				)
			) . '</p>';
			if ( '' !== $last['text'] ) {
				echo '<p>' . esc_html( $last['text'] ) . '</p>';
			}
		}
	}
	echo '<div class="skyphusion-ai-prompts">';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="skyphusion_ai_prompt_save" />';
	if ( function_exists( 'wp_nonce_field' ) ) {
		wp_nonce_field( 'skyphusion_ai_prompt_save' );
	}
	foreach ( skyphusion_ai_prompt_definitions() as $key => $def ) {
		echo '<h3>' . esc_html( $def['label'] ) . '</h3>';
		echo '<p>' . esc_html( $def['note'] ) . '</p>';
		echo '<textarea name="skyphusion_ai_prompt[' . esc_attr( $key ) . ']" rows="6" cols="60">';
		echo esc_html( skyphusion_ai_prompt_raw( $key ) );
		echo '</textarea>';
	}
	echo '<p><button type="submit">' . esc_html__( 'Save the prompts', 'skyphusion-ai' ) . '</button></p>';
	echo '</form>';
	foreach ( skyphusion_ai_prompt_definitions() as $key => $def ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="skyphusion_ai_prompt_reset" />';
		echo '<input type="hidden" name="skyphusion_ai_prompt_key" value="' . esc_attr( $key ) . '" />';
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( 'skyphusion_ai_prompt_reset' );
		}
		echo '<button type="submit">' . esc_html(
			sprintf(
				/* translators: %s is the prompt name. */
				__( 'Reset %s', 'skyphusion-ai' ),
				$def['label']
			)
		) . '</button>';
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="skyphusion_ai_prompt_test" />';
		echo '<input type="hidden" name="skyphusion_ai_prompt_key" value="' . esc_attr( $key ) . '" />';
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( 'skyphusion_ai_prompt_test' );
		}
		echo '<button type="submit">' . esc_html(
			sprintf(
				/* translators: %s is the prompt name. */
				__( 'Test %s', 'skyphusion-ai' ),
				$def['label']
			)
		) . '</button>';
		echo '</form>';
	}
	echo '</div>';
}

/**
 * Where to send the admin after a prompt action.
 *
 * @return void
 */
function skyphusion_ai_prompt_redirect() {
	if ( function_exists( 'wp_safe_redirect' ) ) {
		wp_safe_redirect( admin_url( 'options-general.php?page=cloudflare-ai-search' ) );
		exit;
	}
}

/**
 * Store the posted prompts.
 *
 * @return void
 */
function skyphusion_ai_prompt_handle_save() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( function_exists( 'check_admin_referer' ) ) {
		check_admin_referer( 'skyphusion_ai_prompt_save' );
	}
	if ( ! isset( $_POST['skyphusion_ai_prompt'] ) || ! is_array( $_POST['skyphusion_ai_prompt'] ) ) {
		skyphusion_ai_prompt_redirect();
		return;
	}
	foreach ( array_keys( skyphusion_ai_prompt_definitions() ) as $key ) {
		if ( ! isset( $_POST['skyphusion_ai_prompt'][ $key ] ) ) {
			continue;
		}
		$raw = wp_kses( wp_unslash( $_POST['skyphusion_ai_prompt'][ $key ] ), array() );
		skyphusion_ai_prompt_assign( $key, (string) $raw );
	}
	skyphusion_ai_prompt_redirect();
}

/**
 * Restore one posted prompt.
 *
 * @return void
 */
function skyphusion_ai_prompt_handle_reset() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( function_exists( 'check_admin_referer' ) ) {
		check_admin_referer( 'skyphusion_ai_prompt_reset' );
	}
	$key = '';
	if ( isset( $_POST['skyphusion_ai_prompt_key'] ) ) {
		$key = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_prompt_key'] ) );
	}
	skyphusion_ai_prompt_reset( $key );
	skyphusion_ai_prompt_redirect();
}

/**
 * Run the posted prompt test.
 *
 * @return void
 */
function skyphusion_ai_prompt_handle_test() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( function_exists( 'check_admin_referer' ) ) {
		check_admin_referer( 'skyphusion_ai_prompt_test' );
	}
	$key = '';
	if ( isset( $_POST['skyphusion_ai_prompt_key'] ) ) {
		$key = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_prompt_key'] ) );
	}
	skyphusion_ai_prompt_test( $key );
	skyphusion_ai_prompt_redirect();
}

add_action( 'admin_post_skyphusion_ai_prompt_save', 'skyphusion_ai_prompt_handle_save' );
add_action( 'admin_post_skyphusion_ai_prompt_reset', 'skyphusion_ai_prompt_handle_reset' );
add_action( 'admin_post_skyphusion_ai_prompt_test', 'skyphusion_ai_prompt_handle_test' );
