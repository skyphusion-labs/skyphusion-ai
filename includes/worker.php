<?php
/**
 * Optional assistant Worker on the merchant account.
 *
 * A blank URL keeps the PHP call. The token is not stored in the option
 * and not printed on the page.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option for the deployed Worker URL.
 *
 * @return string
 */
function skyphusion_ai_worker_option_name() {
	return 'skyphusion_ai_worker';
}

/**
 * Script name on the merchant account.
 *
 * @return string
 */
function skyphusion_ai_worker_script_name() {
	return 'skyphusion-ai';
}

/**
 * Saved Worker URL. Empty until a deploy succeeds.
 *
 * @return string
 */
function skyphusion_ai_worker_url() {
	$stored = array();
	if ( function_exists( 'get_option' ) ) {
		$stored = get_option( skyphusion_ai_worker_option_name(), array() );
	}
	if ( ! is_array( $stored ) || ! isset( $stored['url'] ) ) {
		return '';
	}
	return skyphusion_ai_worker_url_clean( $stored['url'] );
}

/**
 * Keep an https workers.dev URL. Drop anything else.
 *
 * @param mixed $url Raw URL.
 * @return string
 */
function skyphusion_ai_worker_url_clean( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		return '';
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
		return '';
	}
	if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
		return '';
	}
	if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
		return '';
	}
	$host = strtolower( (string) $parts['host'] );
	if ( ! str_ends_with( $host, '.workers.dev' ) ) {
		return '';
	}
	$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
	if ( '/' === $path ) {
		$path = '';
	}
	return 'https://' . $host . $path;
}

/**
 * Join SSE text chunks. One body is one answer.
 *
 * @param string $body Response body.
 * @return string
 */
function skyphusion_ai_worker_sse_text( $body ) {
	$text = '';
	foreach ( preg_split( "/\r\n|\n|\r/", (string) $body ) as $line ) {
		$line = trim( $line );
		if ( ! str_starts_with( $line, 'data:' ) ) {
			continue;
		}
		$data = trim( substr( $line, 5 ) );
		if ( '' === $data || '[DONE]' === $data ) {
			continue;
		}
		$parsed = json_decode( $data, true );
		if ( ! is_array( $parsed ) ) {
			continue;
		}
		if ( isset( $parsed['text'] ) && is_string( $parsed['text'] ) ) {
			$text .= $parsed['text'];
		} elseif ( isset( $parsed['response'] ) && is_string( $parsed['response'] ) ) {
			$text .= $parsed['response'];
		}
	}
	return $text;
}

/**
 * Neuron figure from the last SSE chunk that has one.
 *
 * @param string $body Response body.
 * @return int
 */
function skyphusion_ai_worker_sse_neurons( $body ) {
	$neurons = 0;
	foreach ( preg_split( "/\r\n|\n|\r/", (string) $body ) as $line ) {
		$line = trim( $line );
		if ( ! str_starts_with( $line, 'data:' ) ) {
			continue;
		}
		$parsed = json_decode( trim( substr( $line, 5 ) ), true );
		if ( is_array( $parsed ) && isset( $parsed['usage']['neurons'] ) ) {
			$neurons = (int) $parsed['usage']['neurons'];
		}
	}
	if ( 0 > $neurons ) {
		return 0;
	}
	return $neurons;
}

/**
 * Multipart body for the script upload. The token is not in it.
 *
 * @param string $script Worker source.
 * @param string $origin Allowed browser origin.
 * @param string $model  Default model id.
 * @return string
 */
function skyphusion_ai_worker_multipart( $script, $origin, $model ) {
	$metadata = wp_json_encode(
		array(
			'main_module'        => 'index.js',
			'compatibility_date' => '2026-10-01',
			'bindings'           => array(
				array(
					'type' => 'ai',
					'name' => 'AI',
				),
				array(
					'type' => 'plain_text',
					'name' => 'ALLOWED_ORIGIN',
					'text' => $origin,
				),
				array(
					'type' => 'plain_text',
					'name' => 'MODEL',
					'text' => $model,
				),
			),
		)
	);
	$boundary = 'skyphusionaiworker';
	$body     = '--' . $boundary . "\r\n";
	$body    .= "Content-Disposition: form-data; name=\"metadata\"\r\n";
	$body    .= "Content-Type: application/json\r\n\r\n";
	$body    .= (string) $metadata . "\r\n";
	$body    .= '--' . $boundary . "\r\n";
	$body    .= "Content-Disposition: form-data; name=\"index.js\"; filename=\"index.js\"\r\n";
	$body    .= "Content-Type: application/javascript+module\r\n\r\n";
	$body    .= (string) $script . "\r\n";
	$body    .= '--' . $boundary . "--\r\n";
	return $body;
}

/**
 * Read one Cloudflare JSON response. The token is not part of the error.
 *
 * @param string $method       HTTP method.
 * @param string $url          API URL.
 * @param string $token        Account token. Not stored.
 * @param string $body         Request body.
 * @param string $content_type Content type.
 * @return array<string, mixed>|WP_Error
 */
function skyphusion_ai_worker_api( $method, $url, $token, $body, $content_type ) {
	if ( '' === $url || '' === $token || ! function_exists( 'wp_remote_request' ) ) {
		return new WP_Error( 'skyphusion_ai_worker', __( 'Add the Cloudflare account before deploying the Worker.', 'skyphusion-ai' ) );
	}
	$response = wp_remote_request(
		$url,
		array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => $content_type,
			),
			'body'    => $body,
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'skyphusion_ai_worker', __( 'The site could not reach Cloudflare.', 'skyphusion-ai' ) );
	}
	$raw     = (string) wp_remote_retrieve_body( $response );
	$decoded = json_decode( $raw, true );
	$status  = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
		$message = __( 'Cloudflare did not accept the Worker.', 'skyphusion-ai' );
		if ( is_array( $decoded ) && isset( $decoded['errors'][0]['message'] ) ) {
			$message = (string) $decoded['errors'][0]['message'];
		}
		$message = str_replace( $token, '', $message );
		return new WP_Error( 'skyphusion_ai_worker', $message );
	}
	return $decoded;
}

/**
 * Deploy the assistant Worker. Save the URL only after success.
 *
 * @return array{url: string}|WP_Error
 */
function skyphusion_ai_worker_deploy() {
	$credentials = array(
		'account_id' => '',
		'token'      => '',
	);
	if ( function_exists( 'skyphusion_ai_embed_credentials' ) ) {
		$credentials = skyphusion_ai_embed_credentials();
	}
	$account = isset( $credentials['account_id'] ) ? (string) $credentials['account_id'] : '';
	$token   = isset( $credentials['token'] ) ? (string) $credentials['token'] : '';
	if ( '' === $account || '' === $token ) {
		return new WP_Error( 'skyphusion_ai_worker', __( 'Add the Cloudflare account before deploying the Worker.', 'skyphusion-ai' ) );
	}
	$script = '';
	$path   = dirname( __DIR__ ) . '/worker/index.js';
	if ( is_readable( $path ) ) {
		$script = (string) file_get_contents( $path );
	}
	if ( '' === $script ) {
		return new WP_Error( 'skyphusion_ai_worker', __( 'The Worker script is missing.', 'skyphusion-ai' ) );
	}
	$origin = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
	$origin = rtrim( $origin, '/' );
	$model  = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
	if ( function_exists( 'skyphusion_ai_assistant_model' ) ) {
		$model = skyphusion_ai_assistant_model();
	}
	$script_name = skyphusion_ai_worker_script_name();
	$base        = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( $account );
	$uploaded    = skyphusion_ai_worker_api(
		'PUT',
		$base . '/workers/scripts/' . rawurlencode( $script_name ),
		$token,
		skyphusion_ai_worker_multipart( $script, $origin, $model ),
		'multipart/form-data; boundary=skyphusionaiworker'
	);
	if ( is_wp_error( $uploaded ) ) {
		return $uploaded;
	}
	$sub = skyphusion_ai_worker_api( 'GET', $base . '/workers/subdomain', $token, '', 'application/json' );
	if ( is_wp_error( $sub ) ) {
		return $sub;
	}
	$subdomain = '';
	if ( isset( $sub['result']['subdomain'] ) ) {
		$subdomain = strtolower( (string) $sub['result']['subdomain'] );
	}
	if ( ! preg_match( '/^[a-z0-9-]{1,63}$/', $subdomain ) ) {
		return new WP_Error( 'skyphusion_ai_worker', __( 'Cloudflare did not return a workers.dev name.', 'skyphusion-ai' ) );
	}
	$enabled = skyphusion_ai_worker_api(
		'POST',
		$base . '/workers/scripts/' . rawurlencode( $script_name ) . '/subdomain',
		$token,
		wp_json_encode( array( 'enabled' => true ) ),
		'application/json'
	);
	if ( is_wp_error( $enabled ) ) {
		return $enabled;
	}
	$url = skyphusion_ai_worker_url_clean( 'https://' . $script_name . '.' . $subdomain . '.workers.dev' );
	if ( '' === $url ) {
		return new WP_Error( 'skyphusion_ai_worker', __( 'Cloudflare did not return a workers.dev name.', 'skyphusion-ai' ) );
	}
	if ( function_exists( 'update_option' ) ) {
		update_option( skyphusion_ai_worker_option_name(), array( 'url' => $url ), false );
	}
	return array( 'url' => skyphusion_ai_worker_url() );
}

/**
 * WP-CLI deploy. Same function as the settings button.
 *
 * @return void
 */
function skyphusion_ai_worker_cli_deploy() {
	$result = skyphusion_ai_worker_deploy();
	if ( is_wp_error( $result ) ) {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		return;
	}
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::success( $result['url'] );
	}
}

/**
 * Print the deploy control.
 *
 * @return void
 */
function skyphusion_ai_worker_render() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'esc_html' ) ) {
		return;
	}
	$url = skyphusion_ai_worker_url();
	echo '<hr />';
	echo '<h2>' . esc_html__( 'Assistant Worker', 'skyphusion-ai' ) . '</h2>';
	echo '<p>' . esc_html__( 'Deploy the assistant onto your Cloudflare account. A blank address keeps the PHP call. The page does not show the token.', 'skyphusion-ai' ) . '</p>';
	if ( '' === $url ) {
		echo '<p>' . esc_html__( 'Not deployed.', 'skyphusion-ai' ) . '</p>';
	} else {
		echo '<p>' . esc_html( $url ) . '</p>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="skyphusion_ai_worker_deploy" />';
	if ( function_exists( 'wp_nonce_field' ) ) {
		wp_nonce_field( 'skyphusion_ai_worker_deploy' );
	}
	echo '<button type="submit">' . esc_html__( 'Deploy the Worker', 'skyphusion-ai' ) . '</button>';
	echo '</form>';
	echo '<p>' . esc_html__( 'The same deploy runs as wp skyphusion-ai worker deploy.', 'skyphusion-ai' ) . '</p>';
}

/**
 * Settings button. Same deploy as WP-CLI.
 *
 * @return void
 */
function skyphusion_ai_worker_handle_deploy() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( function_exists( 'check_admin_referer' ) ) {
		check_admin_referer( 'skyphusion_ai_worker_deploy' );
	}
	skyphusion_ai_worker_deploy();
	if ( function_exists( 'wp_safe_redirect' ) ) {
		wp_safe_redirect( admin_url( 'options-general.php?page=cloudflare-ai-search' ) );
		exit;
	}
}

/**
 * Edge form. The browser calls the Worker. PHP is not on that request.
 *
 * @param string $url   Worker URL.
 * @param string $model Model id.
 * @return string
 */
function skyphusion_ai_worker_edge_html( $url, $model ) {
	$html  = '<form class="skyphusion-ai-assistant-edge" data-worker="' . esc_url( $url ) . '" data-model="' . esc_attr( $model ) . '">';
	$html .= '<p><label>' . esc_html__( 'Ask this site', 'skyphusion-ai' ) . ' ';
	$html .= '<input type="text" name="skyphusion_ai_assistant_question" value="" /></label></p>';
	$html .= '<button type="submit">' . esc_html__( 'Ask', 'skyphusion-ai' ) . '</button>';
	$html .= '<pre class="skyphusion-ai-assistant-stream"></pre>';
	$html .= '</form>';
	$html .= '<script>(function(){var forms=document.querySelectorAll(".skyphusion-ai-assistant-edge");forms.forEach(function(form){form.addEventListener("submit",function(event){event.preventDefault();var out=form.querySelector(".skyphusion-ai-assistant-stream");var input=form.querySelector("input");out.textContent="";fetch(form.getAttribute("data-worker"),{method:"POST",headers:{"content-type":"application/json"},body:JSON.stringify({question:input.value,model:form.getAttribute("data-model")})}).then(function(response){var reader=response.body.getReader();var decoder=new TextDecoder();var pending="";function pump(){return reader.read().then(function(result){if(result.done){return;}pending+=decoder.decode(result.value,{stream:true});var parts=pending.split("\\n");pending=parts.pop();parts.forEach(function(line){line=line.trim();if(line.indexOf("data:")!==0){return;}var data=line.slice(5).trim();if(!data||data==="[DONE]"){return;}try{var parsed=JSON.parse(data);if(parsed.text){out.textContent+=parsed.text;}}catch(error){}});return pump();});}return pump();});});});})();</script>';
	return $html;
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'skyphusion-ai worker deploy', 'skyphusion_ai_worker_cli_deploy' );
}

add_action( 'admin_post_skyphusion_ai_worker_deploy', 'skyphusion_ai_worker_handle_deploy' );
