<?php
/**
 * Daily neuron budget for Workers AI.
 *
 * The public search box and the chat button are not counted. PHP does not
 * see those calls. The AI Search instance rate limit remains their cap.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option that stores today's neuron total.
 *
 * The row is the UTC day, the neuron total, the estimated part of that
 * total, a count per feature, and the two mail flags. It does not store a
 * prompt, an answer, a token, or a model id.
 *
 * @return string
 */
function skyphusion_ai_neuron_option() {
	return 'skyphusion_ai_neurons';
}

/**
 * Feature names that can be counted.
 *
 * @return string[]
 */
function skyphusion_ai_neuron_features() {
	return array(
		'text',
		'vision',
		'image',
		'speech',
		'embeddings',
		'search',
	);
}

/**
 * A UTC day with every total at zero.
 *
 * @param string $day Y-m-d in UTC.
 * @return array{day: string, neurons: int, estimated: int, features: array<string, int>, mailed_80: int, mailed_100: int}
 */
function skyphusion_ai_neuron_blank( $day ) {
	$features = array();
	foreach ( skyphusion_ai_neuron_features() as $feature ) {
		$features[ $feature ] = 0;
	}
	return array(
		'day'        => $day,
		'neurons'    => 0,
		'estimated'  => 0,
		'features'   => $features,
		'mailed_80'  => 0,
		'mailed_100' => 0,
	);
}

/**
 * Today's UTC date.
 *
 * @return string
 */
function skyphusion_ai_neuron_today() {
	return gmdate( 'Y-m-d' );
}

/**
 * A stored cap that is a whole number of 1 or more, or null.
 *
 * @param mixed $value Raw value.
 * @return int|null
 */
function skyphusion_ai_neuron_valid_cap( $value ) {
	if ( is_int( $value ) && $value >= 1 ) {
		return $value;
	}
	if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,17}$/', $value ) ) {
		return (int) $value;
	}
	return null;
}

/**
 * The daily cap. The default is 10,000, the free allowance.
 *
 * @return int
 */
function skyphusion_ai_neuron_cap() {
	$value = null;
	if ( function_exists( 'skyphusion_ai_settings' ) ) {
		$settings = skyphusion_ai_settings();
		if ( is_array( $settings ) && array_key_exists( 'neuron_cap', $settings ) ) {
			$value = $settings['neuron_cap'];
		}
	}
	$cap = skyphusion_ai_neuron_valid_cap( $value );
	if ( null === $cap ) {
		return 10000;
	}
	return $cap;
}

/**
 * Turn a typed cap into a whole number.
 *
 * Null means the field was absent: keep the previous cap, or 10,000 when
 * there is no previous cap. An empty string returns to 10,000. Any other
 * rejected value returns null so the caller can keep the previous cap and
 * say so.
 *
 * @param mixed $typed    Submission, or null when the field was absent.
 * @param mixed $previous Stored cap.
 * @return int|null
 */
function skyphusion_ai_neuron_parse_cap( $typed, $previous ) {
	$kept = skyphusion_ai_neuron_valid_cap( $previous );
	if ( null === $kept ) {
		$kept = 10000;
	}
	if ( null === $typed ) {
		return $kept;
	}
	if ( is_int( $typed ) ) {
		$typed = (string) $typed;
	}
	if ( ! is_string( $typed ) ) {
		return null;
	}
	$typed = trim( $typed );
	if ( '' === $typed ) {
		return 10000;
	}
	return skyphusion_ai_neuron_valid_cap( $typed );
}

/**
 * The cap submitted with the settings form, or null when that field is absent.
 *
 * Options.php has already checked the settings nonce. The value is still
 * untrusted until skyphusion_ai_neuron_parse_cap() accepts it.
 *
 * @return mixed
 */
function skyphusion_ai_neuron_posted_cap() {
	if ( ! isset( $_POST['skyphusion_ai_settings'] ) || ! is_array( $_POST['skyphusion_ai_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checks the settings nonce before this filter.
		return null;
	}
	if ( ! array_key_exists( 'neuron_cap', $_POST['skyphusion_ai_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checks the settings nonce before this filter.
		return null;
	}
	$typed = $_POST['skyphusion_ai_settings']['neuron_cap']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash runs on the next line when that function exists. The value is parsed as a whole number below, or rejected.
	if ( function_exists( 'wp_unslash' ) ) {
		$typed = wp_unslash( $typed );
	}
	return $typed;
}

/**
 * Keep the neuron cap on the settings option.
 *
 * The settings sanitizer rebuilds the row from its own defaults. This filter
 * puts the cap back after that rebuild.
 *
 * @param mixed  $value    Sanitized settings.
 * @param string $option   Option name. Unused.
 * @param mixed  $original Previous stored value.
 * @return mixed
 */
function skyphusion_ai_neuron_sanitize_option( $value, $option = '', $original = null ) {
	if ( '' !== $option && 'skyphusion_ai_settings' !== $option ) {
		return $value;
	}
	if ( ! is_array( $value ) ) {
		return $value;
	}
	$previous = ( is_array( $original ) && isset( $original['neuron_cap'] ) ) ? $original['neuron_cap'] : null;
	$posted   = skyphusion_ai_neuron_posted_cap();
	$parsed   = skyphusion_ai_neuron_parse_cap( $posted, $previous );
	if ( null === $parsed ) {
		if ( function_exists( 'skyphusion_ai_reject' ) ) {
			skyphusion_ai_reject(
				'neuron_cap',
				__( 'Daily neuron budget has to be a whole number of 1 or more. The previous budget was kept.', 'skyphusion-ai' )
			);
		}
		$parsed = skyphusion_ai_neuron_parse_cap( null, $previous );
	}
	$value['neuron_cap'] = $parsed;
	return $value;
}

/**
 * Today's row. A stored row from another UTC day reads as zero.
 *
 * Reading does not write.
 *
 * @return array{day: string, neurons: int, estimated: int, features: array<string, int>, mailed_80: int, mailed_100: int}
 */
function skyphusion_ai_neuron_state() {
	$today = skyphusion_ai_neuron_today();
	$blank = skyphusion_ai_neuron_blank( $today );
	if ( ! function_exists( 'get_option' ) ) {
		return $blank;
	}
	$stored = get_option( skyphusion_ai_neuron_option(), array() );
	if ( ! is_array( $stored ) || ! isset( $stored['day'] ) || $stored['day'] !== $today ) {
		return $blank;
	}
	$blank['neurons']   = max( 0, (int) ( isset( $stored['neurons'] ) ? $stored['neurons'] : 0 ) );
	$blank['estimated'] = max( 0, (int) ( isset( $stored['estimated'] ) ? $stored['estimated'] : 0 ) );
	if ( $blank['estimated'] > $blank['neurons'] ) {
		$blank['estimated'] = $blank['neurons'];
	}
	if ( isset( $stored['features'] ) && is_array( $stored['features'] ) ) {
		foreach ( skyphusion_ai_neuron_features() as $feature ) {
			$value = isset( $stored['features'][ $feature ] ) ? (int) $stored['features'][ $feature ] : 0;
			if ( $value < 0 ) {
				$value = 0;
			}
			$blank['features'][ $feature ] = $value;
		}
	}
	$blank['mailed_80']  = empty( $stored['mailed_80'] ) ? 0 : 1;
	$blank['mailed_100'] = empty( $stored['mailed_100'] ) ? 0 : 1;
	return $blank;
}

/**
 * True when today's total is already at the cap.
 *
 * @return bool
 */
function skyphusion_ai_neuron_at_cap() {
	$state = skyphusion_ai_neuron_state();
	return (int) $state['neurons'] >= skyphusion_ai_neuron_cap();
}

/**
 * The refusal shown when the cap is spent. It does not include the token.
 *
 * @return string
 */
function skyphusion_ai_neuron_refusal_text() {
	return __( 'Workers AI is paused until 00:00 UTC. Today\'s neuron budget is spent. The call stayed on this site.', 'skyphusion-ai' );
}

/**
 * WP_Error when the next Workers AI call must stay on the site, or null.
 *
 * @return WP_Error|null
 */
function skyphusion_ai_neuron_refusal() {
	if ( ! skyphusion_ai_neuron_at_cap() ) {
		return null;
	}
	if ( ! class_exists( 'WP_Error' ) ) {
		return null;
	}
	return new WP_Error( 'skyphusion_ai_neuron_cap', skyphusion_ai_neuron_refusal_text() );
}

/**
 * Thousandths of a dollar per million tokens, from the README rates.
 *
 * Neurons are $0.011 per 1,000, so a price in dollars per million tokens
 * converts as ceil(tokens * milli / 11000). An unknown model uses the
 * default text rate. Token math is always an estimate. A neuron figure from
 * Cloudflare is not.
 *
 * @param string $model Model id.
 * @return array{0: int, 1: int}
 */
function skyphusion_ai_neuron_rate( $model ) {
	$llama = array( 293, 2253 );
	$rates = array(
		'@cf/meta/llama-3.3-70b-instruct-fp8-fast' => $llama,
		'@cf/baai/bge-large-en-v1.5'               => array( 204, 0 ),
	);
	if ( isset( $rates[ $model ] ) ) {
		return $rates[ $model ];
	}
	return $llama;
}

/**
 * Neurons for one token count at one README rate, rounded up.
 *
 * @param int $tokens Token count.
 * @param int $milli  Thousandths of a dollar per million tokens.
 * @return int
 */
function skyphusion_ai_neuron_from_tokens( $tokens, $milli ) {
	$tokens = (int) $tokens;
	$milli  = (int) $milli;
	if ( $tokens < 1 || $milli < 1 ) {
		return 0;
	}
	return intdiv( ( $tokens * $milli ) + 10999, 11000 );
}

/**
 * Usage objects Cloudflare may put on a Workers AI or AI Search body.
 *
 * @param mixed $decoded Response JSON.
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_neuron_usage_rows( $decoded ) {
	$rows = array();
	if ( ! is_array( $decoded ) ) {
		return $rows;
	}
	if ( isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ) {
		$rows[] = $decoded['usage'];
	}
	if ( isset( $decoded['result'] ) && is_array( $decoded['result'] ) ) {
		if ( isset( $decoded['result']['usage'] ) && is_array( $decoded['result']['usage'] ) ) {
			$rows[] = $decoded['result']['usage'];
		}
		$rows[] = $decoded['result'];
	}
	$top = array();
	foreach ( array( 'neurons', 'neuron_count', 'total_neurons' ) as $skyphusion_ai_neuron_key ) {
		if ( isset( $decoded[ $skyphusion_ai_neuron_key ] ) ) {
			$top[ $skyphusion_ai_neuron_key ] = $decoded[ $skyphusion_ai_neuron_key ];
		}
	}
	if ( array() !== $top ) {
		$rows[] = $top;
	}
	return $rows;
}

/**
 * First non-negative number under one of the keys, or null.
 *
 * @param array<int, array<string, mixed>> $rows Usage rows.
 * @param string[]                         $keys Key names, in preference order.
 * @return int|null
 */
function skyphusion_ai_neuron_pick( $rows, $keys ) {
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		foreach ( $keys as $key ) {
			if ( ! isset( $row[ $key ] ) || ! is_numeric( $row[ $key ] ) ) {
				continue;
			}
			$number = (int) $row[ $key ];
			if ( $number < 0 ) {
				continue;
			}
			return $number;
		}
	}
	return null;
}

/**
 * Neurons for one response.
 *
 * A neuron figure wins. Otherwise the token counts are priced with the
 * README rates and marked estimated. No figure and no tokens is unknown,
 * and the caller adds no neurons.
 *
 * @param string $model   Model id. Empty uses the default text rate.
 * @param mixed  $decoded Response JSON.
 * @return array{known: bool, neurons: int, estimated: bool}
 */
function skyphusion_ai_neuron_measure( $model, $decoded ) {
	$rows    = skyphusion_ai_neuron_usage_rows( $decoded );
	$neurons = skyphusion_ai_neuron_pick( $rows, array( 'neurons', 'neuron_count', 'total_neurons' ) );
	if ( null !== $neurons ) {
		return array(
			'known'     => true,
			'neurons'   => $neurons,
			'estimated' => false,
		);
	}
	$prompt     = skyphusion_ai_neuron_pick( $rows, array( 'prompt_tokens', 'input_tokens' ) );
	$completion = skyphusion_ai_neuron_pick( $rows, array( 'completion_tokens', 'output_tokens' ) );
	if ( null === $prompt && null === $completion ) {
		return array(
			'known'     => false,
			'neurons'   => 0,
			'estimated' => false,
		);
	}
	$rate  = skyphusion_ai_neuron_rate( $model );
	$total = skyphusion_ai_neuron_from_tokens( (int) $prompt, $rate[0] );
	$total = $total + skyphusion_ai_neuron_from_tokens( (int) $completion, $rate[1] );
	return array(
		'known'     => true,
		'neurons'   => $total,
		'estimated' => true,
	);
}

/**
 * Mail text for one threshold.
 *
 * @param string $level 80 or 100.
 * @param int    $used  Neurons used.
 * @param int    $cap   Daily cap.
 * @param int    $estimated Estimated part of the total.
 * @return array{subject: string, body: string}
 */
function skyphusion_ai_neuron_mail_text( $level, $used, $cap, $estimated ) {
	if ( '100' === $level ) {
		$subject = __( 'Workers AI reached today\'s neuron budget', 'skyphusion-ai' );
	} else {
		$subject = __( 'Workers AI used 80% of today\'s neuron budget', 'skyphusion-ai' );
	}
	$body = sprintf(
		/* translators: 1: neurons used, 2: daily cap, 3: estimated neurons. */
		__( 'Today\'s Workers AI use is %1$d of %2$d neurons. %3$d of those neurons are estimates. At %2$d neurons, the next Workers AI call stays on this site until 00:00 UTC. The public search box and the chat button are not in this total.', 'skyphusion-ai' ),
		$used,
		$cap,
		$estimated
	);
	return array(
		'subject' => $subject,
		'body'    => $body,
	);
}

/**
 * Send the 80% mail and the 100% mail, once each per UTC day.
 *
 * A failed mail still sets the flag. The admin notice does not read the flag.
 *
 * @param array{neurons: int, estimated: int, mailed_80: int, mailed_100: int} $state Row being saved.
 * @return array{neurons: int, estimated: int, mailed_80: int, mailed_100: int}
 */
function skyphusion_ai_neuron_mail( $state ) {
	if ( ! function_exists( 'wp_mail' ) || ! function_exists( 'get_option' ) ) {
		return $state;
	}
	$email = get_option( 'admin_email', '' );
	if ( ! is_string( $email ) || '' === $email ) {
		return $state;
	}
	if ( function_exists( 'is_email' ) && ! is_email( $email ) ) {
		return $state;
	}
	$cap  = skyphusion_ai_neuron_cap();
	$used = (int) $state['neurons'];
	if ( $cap < 1 ) {
		return $state;
	}
	$at_80  = ( $used * 5 ) >= ( $cap * 4 );
	$at_100 = $used >= $cap;
	if ( $at_80 && empty( $state['mailed_80'] ) ) {
		$text = skyphusion_ai_neuron_mail_text( '80', $used, $cap, (int) $state['estimated'] );
		wp_mail( $email, $text['subject'], $text['body'] );
		$state['mailed_80'] = 1;
	}
	if ( $at_100 && empty( $state['mailed_100'] ) ) {
		$text = skyphusion_ai_neuron_mail_text( '100', $used, $cap, (int) $state['estimated'] );
		wp_mail( $email, $text['subject'], $text['body'] );
		$state['mailed_100'] = 1;
	}
	return $state;
}

/**
 * Add one call to today's row.
 *
 * The write happens before this function returns, so the next call sees it.
 *
 * @param string $feature   Feature name.
 * @param int    $neurons   Neurons to add.
 * @param bool   $estimated Whether those neurons are an estimate.
 * @return array{day: string, neurons: int, estimated: int, features: array<string, int>, mailed_80: int, mailed_100: int}
 */
function skyphusion_ai_neuron_add( $feature, $neurons, $estimated ) {
	$state = skyphusion_ai_neuron_state();
	if ( ! in_array( $feature, skyphusion_ai_neuron_features(), true ) ) {
		$feature = 'text';
	}
	$neurons = (int) $neurons;
	if ( $neurons < 0 ) {
		$neurons = 0;
	}
	$state['neurons']             += $neurons;
	$state['features'][ $feature ] = (int) $state['features'][ $feature ] + 1;
	if ( $estimated && $neurons > 0 ) {
		$state['estimated'] += $neurons;
	}
	$state = skyphusion_ai_neuron_mail( $state );
	if ( function_exists( 'update_option' ) ) {
		update_option( skyphusion_ai_neuron_option(), $state, false );
	}
	return $state;
}

/**
 * Count one response under a feature.
 *
 * A response with no neuron figure and no token figure still counts as a
 * call and adds no neurons.
 *
 * @param string $feature Feature name.
 * @param string $model   Model id.
 * @param mixed  $decoded Response JSON.
 * @return array{day: string, neurons: int, estimated: int, features: array<string, int>, mailed_80: int, mailed_100: int}
 */
function skyphusion_ai_neuron_record( $feature, $model, $decoded ) {
	$measured = skyphusion_ai_neuron_measure( $model, $decoded );
	$neurons  = $measured['known'] ? (int) $measured['neurons'] : 0;
	return skyphusion_ai_neuron_add( $feature, $neurons, $measured['known'] && $measured['estimated'] );
}

/**
 * Model id from a Workers AI URL or from the JSON body.
 *
 * @param string $url     Request URL.
 * @param mixed  $payload Request JSON.
 * @return string
 */
function skyphusion_ai_neuron_model_from_call( $url, $payload ) {
	if ( is_array( $payload ) && isset( $payload['model'] ) && function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		$model = cloudflare_ai_provider_model_id( (string) $payload['model'] );
		if ( '' !== $model ) {
			return $model;
		}
	}
	if ( is_string( $url ) && preg_match( '/(@cf\/[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+)/', $url, $match ) && function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		return cloudflare_ai_provider_model_id( $match[1] );
	}
	return '';
}

/**
 * True when the request carries an image.
 *
 * @param mixed $payload Request JSON.
 * @return bool
 */
function skyphusion_ai_neuron_payload_has_image( $payload ) {
	if ( ! is_array( $payload ) ) {
		return false;
	}
	foreach ( $payload as $key => $value ) {
		if ( 'image_url' === $key ) {
			return true;
		}
		if ( 'type' === $key && is_string( $value ) && 'image_url' === $value ) {
			return true;
		}
		if ( is_string( $value ) && 0 === strpos( $value, 'data:image/' ) ) {
			return true;
		}
		if ( is_array( $value ) && skyphusion_ai_neuron_payload_has_image( $value ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Feature name for one Workers AI call.
 *
 * @param string $model   Model id.
 * @param mixed  $payload Request JSON.
 * @return string
 */
function skyphusion_ai_neuron_feature( $model, $payload ) {
	if ( function_exists( 'cloudflare_ai_provider_embeddings_model' ) && cloudflare_ai_provider_embeddings_model() === $model ) {
		return 'embeddings';
	}
	$entry = null;
	if ( function_exists( 'cloudflare_ai_provider_catalog_entry' ) ) {
		$entry = cloudflare_ai_provider_catalog_entry( $model );
	}
	if ( ! is_array( $entry ) && function_exists( 'skyphusion_ai_account_model_entry' ) ) {
		$entry = skyphusion_ai_account_model_entry( $model );
	}
	if ( ! is_array( $entry ) ) {
		return 'text';
	}
	if ( 'embeddings' === $entry['kind'] ) {
		return 'embeddings';
	}
	if ( 'image' === $entry['kind'] ) {
		return 'image';
	}
	if ( 'speech' === $entry['kind'] ) {
		return 'speech';
	}
	if ( ! empty( $entry['vision'] ) && skyphusion_ai_neuron_payload_has_image( $payload ) ) {
		return 'vision';
	}
	return 'text';
}

/**
 * Empty when the call may leave the site. Otherwise the refusal text.
 *
 * On an allowed call, the feature and the model are held until
 * skyphusion_ai_neuron_note() records the response. The prompt is not held.
 *
 * @param string $url     Request URL.
 * @param mixed  $payload Request JSON.
 * @return string
 */
function skyphusion_ai_neuron_closed_message( $url, $payload ) {
	unset( $GLOBALS['skyphusion_ai_neuron_call'] );
	$refusal = skyphusion_ai_neuron_refusal();
	if ( is_wp_error( $refusal ) ) {
		return skyphusion_ai_neuron_refusal_text();
	}
	$model                                = skyphusion_ai_neuron_model_from_call( (string) $url, $payload );
	$GLOBALS['skyphusion_ai_neuron_call'] = array(
		'feature' => skyphusion_ai_neuron_feature( $model, $payload ),
		'model'   => $model,
	);
	return '';
}

/**
 * Record the response held by skyphusion_ai_neuron_closed_message().
 *
 * @param mixed $data Response data.
 * @return mixed The same data.
 */
function skyphusion_ai_neuron_note( $data ) {
	$call = array();
	if ( isset( $GLOBALS['skyphusion_ai_neuron_call'] ) && is_array( $GLOBALS['skyphusion_ai_neuron_call'] ) ) {
		$call = $GLOBALS['skyphusion_ai_neuron_call'];
	}
	unset( $GLOBALS['skyphusion_ai_neuron_call'] );
	if ( isset( $call['feature'] ) ) {
		$model = isset( $call['model'] ) ? (string) $call['model'] : '';
		skyphusion_ai_neuron_record( (string) $call['feature'], $model, $data );
	}
	return $data;
}

/**
 * POST an embeddings body, or refuse without leaving the site.
 *
 * @param string               $url   Workers AI URL.
 * @param string               $token Connectors token. It is not put in an error.
 * @param array<string, mixed> $body  Embeddings body.
 * @return array<string, mixed>|WP_Error
 */
function skyphusion_ai_neuron_embeddings_post( $url, $token, $body ) {
	$refusal = skyphusion_ai_neuron_refusal();
	if ( is_wp_error( $refusal ) ) {
		return $refusal;
	}
	$model = '@cf/baai/bge-large-en-v1.5';
	if ( function_exists( 'cloudflare_ai_provider_embeddings_model' ) ) {
		$model = cloudflare_ai_provider_embeddings_model();
	}
	$headers = array(
		'Content-Type'  => 'application/json',
		'Authorization' => 'Bearer ' . $token,
	);
	if ( function_exists( 'skyphusion_ai_gateway_metadata_header' ) ) {
		$headers = array_merge( $headers, skyphusion_ai_gateway_metadata_header( $url, 'embeddings', $model ) );
	}
	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 30,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $response ) || ! function_exists( 'wp_remote_retrieve_body' ) ) {
		return $response;
	}
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	skyphusion_ai_neuron_record( 'embeddings', $model, $decoded );
	return $response;
}

/**
 * Count an AI Search answer when the body has a usage figure.
 *
 * This does not refuse the call. The call ceiling from issue 14 stays.
 *
 * @param mixed $decoded Response JSON.
 * @return array{answer: string, sources: array<int, string>}
 */
function skyphusion_ai_neuron_count_search( $decoded ) {
	$measured = skyphusion_ai_neuron_measure( '', $decoded );
	if ( $measured['known'] ) {
		skyphusion_ai_neuron_add( 'search', (int) $measured['neurons'], (bool) $measured['estimated'] );
	}
	return skyphusion_ai_read_answer( $decoded );
}

/**
 * Notice level for today: 100, 80, or an empty string.
 *
 * @return string
 */
function skyphusion_ai_neuron_notice_code() {
	$state = skyphusion_ai_neuron_state();
	$cap   = skyphusion_ai_neuron_cap();
	$used  = (int) $state['neurons'];
	if ( $cap < 1 ) {
		return '';
	}
	if ( $used >= $cap ) {
		return '100';
	}
	if ( ( $used * 5 ) >= ( $cap * 4 ) ) {
		return '80';
	}
	return '';
}

/**
 * Admin notice text for one level.
 *
 * @param string $code 80 or 100.
 * @return string
 */
function skyphusion_ai_neuron_notice_text( $code ) {
	$state     = skyphusion_ai_neuron_state();
	$cap       = skyphusion_ai_neuron_cap();
	$used      = (int) $state['neurons'];
	$estimated = (int) $state['estimated'];
	if ( '100' === $code ) {
		return sprintf(
			/* translators: 1: neurons used, 2: daily cap, 3: estimated neurons. */
			__( 'Workers AI is paused until 00:00 UTC. Today\'s use is %1$d of %2$d neurons. %3$d of those neurons are estimates. The public search box and the chat button are not in this total.', 'skyphusion-ai' ),
			$used,
			$cap,
			$estimated
		);
	}
	return sprintf(
		/* translators: 1: neurons used, 2: daily cap, 3: estimated neurons. */
		__( 'Workers AI has used %1$d of %2$d neurons today. Calls pause at %2$d until 00:00 UTC. %3$d of those neurons are estimates. The public search box and the chat button are not in this total.', 'skyphusion-ai' ),
		$used,
		$cap,
		$estimated
	);
}

/**
 * Settings text for the cap field, including today's total.
 *
 * @return string
 */
function skyphusion_ai_neuron_settings_text() {
	$state = skyphusion_ai_neuron_state();
	return sprintf(
		/* translators: 1: neurons used today, 2: estimated neurons. */
		__( 'The default is 10,000 neurons, the free daily allowance. Today (UTC): %1$d neurons, %2$d of them estimates. The public search box and the chat button are not counted. PHP does not see those calls. The AI Search instance rate limit is the cap for them.', 'skyphusion-ai' ),
		(int) $state['neurons'],
		(int) $state['estimated']
	);
}

/**
 * Admin bar title.
 *
 * @return string
 */
function skyphusion_ai_neuron_bar_title() {
	$state = skyphusion_ai_neuron_state();
	return sprintf(
		/* translators: 1: neurons used today, 2: daily cap. */
		__( '%1$d / %2$d neurons', 'skyphusion-ai' ),
		(int) $state['neurons'],
		skyphusion_ai_neuron_cap()
	);
}

/**
 * Settings field for the daily cap.
 *
 * @return void
 */
function skyphusion_ai_neuron_field() {
	if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	printf(
		'<input type="number" class="small-text" name="skyphusion_ai_settings[neuron_cap]" value="%s" min="1" step="1" />',
		esc_attr( (string) skyphusion_ai_neuron_cap() )
	);
	echo '<p class="description">' . esc_html( skyphusion_ai_neuron_settings_text() ) . '</p>';
}

/**
 * Register the cap field on the Workers AI section.
 *
 * @return void
 */
function skyphusion_ai_neuron_register_field() {
	if ( ! function_exists( 'add_settings_field' ) ) {
		return;
	}
	add_settings_field(
		'neuron_cap',
		__( 'Daily neuron budget', 'skyphusion-ai' ),
		'skyphusion_ai_neuron_field',
		'cloudflare-ai-search',
		'workers'
	);
}

/**
 * Warn an administrator at 80% and at 100%. The mail flag is not required.
 *
 * @return void
 */
function skyphusion_ai_neuron_admin_notice() {
	if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$code = skyphusion_ai_neuron_notice_code();
	if ( '' === $code ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>' . esc_html( skyphusion_ai_neuron_notice_text( $code ) ) . '</p></div>';
}

/**
 * Show today's total in the admin bar.
 *
 * @param mixed $bar Admin bar.
 * @return void
 */
function skyphusion_ai_neuron_admin_bar( $bar ) {
	if ( ! is_object( $bar ) || ! method_exists( $bar, 'add_node' ) ) {
		return;
	}
	if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$href = function_exists( 'admin_url' ) ? admin_url( 'options-general.php?page=cloudflare-ai-search' ) : '';
	$bar->add_node(
		array(
			'id'    => 'skyphusion-ai-neurons',
			'title' => skyphusion_ai_neuron_bar_title(),
			'href'  => $href,
		)
	);
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'admin_init', 'skyphusion_ai_neuron_register_field', 20 );
	add_action( 'admin_notices', 'skyphusion_ai_neuron_admin_notice' );
	add_action( 'admin_bar_menu', 'skyphusion_ai_neuron_admin_bar', 80 );
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'sanitize_option_skyphusion_ai_settings', 'skyphusion_ai_neuron_sanitize_option', 20, 3 );
}

require_once __DIR__ . '/gateway.php';
