<?php
/**
 * Daily neuron budget for Workers AI.
 *
 * The public search box and the chat button are not counted. PHP does not
 * see those calls. The AI Search instance rate limit remains their cap.
 * Each tool can also have a daily limit. A tool at its limit stops, and
 * the other tools continue. The site budget still stops every tool.
 * Search snippets stay outside both limits. The totals do not store an
 * IP address. The last receipt is a transient for the current user. It
 * stores the feature, the model, the neurons, and the time. It does not
 * store an IP address.
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
	$tools = array();
	foreach ( skyphusion_ai_tool_keys() as $tool ) {
		$tools[ $tool ] = 0;
	}
	return array(
		'day'        => $day,
		'neurons'    => 0,
		'estimated'  => 0,
		'features'   => $features,
		'tools'      => $tools,
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
 * The daily cap. The default is 10,000. Cloudflare free plans include it.
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
 * True only when a saved cap is a whole number of 1 or more.
 *
 * An absent cap is not saved. The default 10000 is still the number in use.
 *
 * @return bool
 */
function skyphusion_ai_neuron_cap_saved() {
	if ( ! function_exists( 'skyphusion_ai_settings' ) ) {
		return false;
	}
	$settings = skyphusion_ai_settings();
	if ( ! is_array( $settings ) || ! array_key_exists( 'neuron_cap', $settings ) ) {
		return false;
	}
	return null !== skyphusion_ai_neuron_valid_cap( $settings['neuron_cap'] );
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
	$value['neuron_cap']  = $parsed;
	$value['neuron_over'] = skyphusion_ai_neuron_stored_over( $original );
	$value['tool_caps']   = skyphusion_ai_tool_stored_caps( $original );
	return $value;
}

/**
 * Pause, or the cheapest text model. Anything else stays pause.
 *
 * @return string
 */
function skyphusion_ai_neuron_over_mode() {
	if ( function_exists( 'skyphusion_ai_settings' ) ) {
		$settings = skyphusion_ai_settings();
		if ( is_array( $settings ) && isset( $settings['neuron_over'] ) && 'cheapest' === $settings['neuron_over'] ) {
			return 'cheapest';
		}
	}
	return 'pause';
}

/**
 * The over-budget choice to store. An omitted field keeps the previous choice.
 *
 * @param mixed $original Previous stored settings.
 * @return string
 */
function skyphusion_ai_neuron_stored_over( $original ) {
	$posted = skyphusion_ai_neuron_posted_over();
	if ( null !== $posted ) {
		return $posted;
	}
	if ( is_array( $original ) && isset( $original['neuron_over'] ) && 'cheapest' === $original['neuron_over'] ) {
		return 'cheapest';
	}
	return 'pause';
}

/**
 * The over-budget choice on the settings form, or null when that field is absent.
 *
 * Options.php has already checked the settings nonce.
 *
 * @return string|null
 */
function skyphusion_ai_neuron_posted_over() {
	if ( ! isset( $_POST['skyphusion_ai_settings'] ) || ! is_array( $_POST['skyphusion_ai_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checks the settings nonce before this filter.
		return null;
	}
	if ( ! array_key_exists( 'neuron_over', $_POST['skyphusion_ai_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checks the settings nonce before this filter.
		return null;
	}
	$typed = $_POST['skyphusion_ai_settings']['neuron_over']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash runs on the next line when that function exists. The value is kept only when it is the cheapest choice.
	if ( function_exists( 'wp_unslash' ) ) {
		$typed = wp_unslash( $typed );
	}
	if ( is_string( $typed ) && 'cheapest' === $typed ) {
		return 'cheapest';
	}
	return 'pause';
}

/**
 * Sum of the prices stored on one account row, or null when none were sent.
 *
 * @param array<string, mixed> $model Account row.
 * @return float|null
 */
function skyphusion_ai_neuron_row_price( $model ) {
	if ( ! is_array( $model ) || ! function_exists( 'skyphusion_ai_account_model_prices' ) ) {
		return null;
	}
	$prices = skyphusion_ai_account_model_prices( $model );
	if ( array() === $prices ) {
		return null;
	}
	$sum = 0.0;
	foreach ( $prices as $price ) {
		$sum += (float) $price;
	}
	if ( $sum < 0 ) {
		return null;
	}
	return $sum;
}

/**
 * Cheapest plain text model on the account list.
 *
 * A vision model is skipped. A model with no price is skipped. An empty
 * string means the call stays paused. No id is invented.
 *
 * @return string
 */
function skyphusion_ai_neuron_cheapest_text() {
	if ( ! function_exists( 'skyphusion_ai_account_models_current' ) || ! function_exists( 'skyphusion_ai_account_model_kind' ) ) {
		return '';
	}
	$best    = '';
	$best_px = null;
	foreach ( skyphusion_ai_account_models_current() as $model ) {
		if ( ! is_array( $model ) || empty( $model['id'] ) ) {
			continue;
		}
		$kind = skyphusion_ai_account_model_kind( isset( $model['task'] ) ? (string) $model['task'] : '' );
		if ( 'text' !== $kind[0] || ! empty( $kind[1] ) ) {
			continue;
		}
		$price = skyphusion_ai_neuron_row_price( $model );
		if ( null === $price ) {
			continue;
		}
		if ( null === $best_px || $price < $best_px ) {
			$best_px = $price;
			$best    = (string) $model['id'];
		}
	}
	return $best;
}

/**
 * Replace the model on one Workers AI URL. The payload model is replaced when it is set.
 *
 * @param string $url     Request URL.
 * @param mixed  $payload Request JSON.
 * @param string $model   Model id from the account list.
 * @return array{0: string, 1: mixed}
 */
function skyphusion_ai_neuron_swap_model( $url, $payload, $model ) {
	$url   = (string) $url;
	$model = (string) $model;
	if ( '' !== $model && 1 === preg_match( '#/(?:ai/run|workers-ai)/@cf/.+$#', $url ) ) {
		$swapped = preg_replace( '#(/(?:ai/run|workers-ai)/)@cf/.+$#', '$1' . $model, $url, 1 );
		if ( is_string( $swapped ) && '' !== $swapped ) {
			$url = $swapped;
		}
	}
	if ( is_array( $payload ) && isset( $payload['model'] ) ) {
		$payload['model'] = $model;
	}
	return array( $url, $payload );
}

/**
 * At the cap, a plain text call may move to the cheapest priced model.
 *
 * Other calls are returned unchanged. The public search call does not come here.
 *
 * @param string $url     Request URL.
 * @param mixed  $payload Request JSON.
 * @return array{0: string, 1: mixed}
 */
function skyphusion_ai_neuron_cheap_request( $url, $payload ) {
	if ( 'cheapest' !== skyphusion_ai_neuron_over_mode() || ! skyphusion_ai_neuron_at_cap() ) {
		return array( $url, $payload );
	}
	$model   = skyphusion_ai_neuron_model_from_call( (string) $url, $payload );
	$feature = skyphusion_ai_neuron_feature( $model, $payload );
	if ( 'text' !== $feature || skyphusion_ai_neuron_payload_has_image( $payload ) ) {
		return array( $url, $payload );
	}
	$cheap = skyphusion_ai_neuron_cheapest_text();
	if ( '' === $cheap ) {
		return array( $url, $payload );
	}
	return skyphusion_ai_neuron_swap_model( $url, $payload, $cheap );
}

/**
 * Tools that can stop on their own daily limit.
 *
 * Search is not a tool here. The public search box does not pass through
 * PHP, and a search answer is counted on the site budget without a refusal.
 * Backfill keeps the limit on its own option.
 *
 * @return string[]
 */
function skyphusion_ai_tool_keys() {
	return array(
		'text',
		'vision',
		'image',
		'speech',
		'embeddings',
		'assistant',
		'translate',
	);
}

/**
 * Daily limit used when that tool has never been saved.
 *
 * Zero means the tool has no limit of its own. Embeddings and the site
 * assistant start with a limit. The others wait for the admin to set one.
 *
 * @param string $tool Tool name.
 * @return int
 */
function skyphusion_ai_tool_default( $tool ) {
	$defaults = array(
		'text'       => 0,
		'vision'     => 0,
		'image'      => 0,
		'speech'     => 0,
		'embeddings' => 3000,
		'assistant'  => 1000,
		'translate'  => 0,
	);
	if ( isset( $defaults[ $tool ] ) ) {
		return $defaults[ $tool ];
	}
	return 0;
}

/**
 * A tool limit that is a whole number of 0 or more, or null.
 *
 * An empty string is 0, so clearing the field removes the tool's own limit.
 *
 * @param mixed $value Raw value.
 * @return int|null
 */
function skyphusion_ai_tool_limit_value( $value ) {
	if ( is_int( $value ) && $value >= 0 ) {
		return $value;
	}
	if ( ! is_string( $value ) ) {
		return null;
	}
	$value = trim( $value );
	if ( '' === $value ) {
		return 0;
	}
	if ( 1 === preg_match( '/^(0|[1-9][0-9]{0,8})$/', $value ) ) {
		return (int) $value;
	}
	return null;
}

/**
 * The tool limits submitted with the settings form, or null when absent.
 *
 * Options.php has already checked the settings nonce.
 *
 * @return array<string, mixed>|null
 */
function skyphusion_ai_tool_posted_caps() {
	if ( ! isset( $_POST['skyphusion_ai_settings'] ) || ! is_array( $_POST['skyphusion_ai_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checks the settings nonce before this filter.
		return null;
	}
	if ( ! isset( $_POST['skyphusion_ai_settings']['tool_caps'] ) || ! is_array( $_POST['skyphusion_ai_settings']['tool_caps'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checks the settings nonce before this filter.
		return null;
	}
	$typed = $_POST['skyphusion_ai_settings']['tool_caps']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash runs on the next line when that function exists. Each value is parsed as a whole number, or rejected.
	if ( function_exists( 'wp_unslash' ) ) {
		$typed = wp_unslash( $typed );
	}
	if ( ! is_array( $typed ) ) {
		return null;
	}
	return $typed;
}

/**
 * Tool limits to store. An omitted or rejected field keeps the previous limit.
 *
 * @param mixed $original Previous stored settings.
 * @return array<string, int>
 */
function skyphusion_ai_tool_stored_caps( $original ) {
	$previous = array();
	if ( is_array( $original ) && isset( $original['tool_caps'] ) && is_array( $original['tool_caps'] ) ) {
		$previous = $original['tool_caps'];
	}
	$posted = skyphusion_ai_tool_posted_caps();
	$out    = array();
	foreach ( skyphusion_ai_tool_keys() as $tool ) {
		$kept = skyphusion_ai_tool_default( $tool );
		if ( array_key_exists( $tool, $previous ) ) {
			$parsed_prev = skyphusion_ai_tool_limit_value( $previous[ $tool ] );
			if ( null !== $parsed_prev ) {
				$kept = $parsed_prev;
			}
		}
		if ( null === $posted || ! array_key_exists( $tool, $posted ) ) {
			$out[ $tool ] = $kept;
			continue;
		}
		$parsed = skyphusion_ai_tool_limit_value( $posted[ $tool ] );
		if ( null === $parsed ) {
			if ( function_exists( 'skyphusion_ai_reject' ) ) {
				skyphusion_ai_reject(
					'tool_caps',
					__( 'A tool limit has to be a whole number of 0 or more. The previous limit was kept.', 'skyphusion-ai' )
				);
			}
			$out[ $tool ] = $kept;
			continue;
		}
		$out[ $tool ] = $parsed;
	}
	return $out;
}

/**
 * The daily limit for one tool. Zero means no limit of its own.
 *
 * @param string $tool Tool name.
 * @return int
 */
function skyphusion_ai_tool_cap( $tool ) {
	if ( ! in_array( $tool, skyphusion_ai_tool_keys(), true ) ) {
		return 0;
	}
	if ( function_exists( 'skyphusion_ai_settings' ) ) {
		$settings = skyphusion_ai_settings();
		if ( is_array( $settings ) && isset( $settings['tool_caps'] ) && is_array( $settings['tool_caps'] ) && array_key_exists( $tool, $settings['tool_caps'] ) ) {
			$parsed = skyphusion_ai_tool_limit_value( $settings['tool_caps'][ $tool ] );
			if ( null !== $parsed ) {
				return $parsed;
			}
		}
	}
	return skyphusion_ai_tool_default( $tool );
}

/**
 * Neurons this tool has used today. A row from another UTC day counts as zero.
 *
 * @param string $tool Tool name.
 * @return int
 */
function skyphusion_ai_tool_used( $tool ) {
	$state = skyphusion_ai_neuron_state();
	if ( ! isset( $state['tools'][ $tool ] ) ) {
		return 0;
	}
	$used = (int) $state['tools'][ $tool ];
	if ( $used < 0 ) {
		return 0;
	}
	return $used;
}

/**
 * True when this tool has spent its own limit.
 *
 * A limit of zero does not stop the tool. The site budget is a separate check.
 *
 * @param string $tool Tool name.
 * @return bool
 */
function skyphusion_ai_tool_blocked( $tool ) {
	$cap = skyphusion_ai_tool_cap( $tool );
	if ( $cap < 1 ) {
		return false;
	}
	return skyphusion_ai_tool_used( $tool ) >= $cap;
}

/**
 * The refusal shown when one tool has spent its limit.
 *
 * It does not name an IP address, and it does not include the token.
 *
 * @return string
 */
function skyphusion_ai_tool_refusal_text() {
	return __( 'This tool\'s daily limit is spent. It resumes at 00:00 UTC. The other tools continue. The site budget still stops every tool.', 'skyphusion-ai' );
}

/**
 * WP_Error when this tool must stay on the site, or null.
 *
 * @param string $tool Tool name.
 * @return WP_Error|null
 */
function skyphusion_ai_tool_refusal( $tool ) {
	if ( ! skyphusion_ai_tool_blocked( $tool ) ) {
		return null;
	}
	if ( ! class_exists( 'WP_Error' ) ) {
		return null;
	}
	return new WP_Error( 'skyphusion_ai_tool_cap', skyphusion_ai_tool_refusal_text() );
}

/**
 * Add neurons to one tool's total for today.
 *
 * Unknown tools, including search and backfill, are left unchanged. Backfill
 * keeps its own counter. Search stays outside both limits.
 *
 * @param string $tool    Tool name.
 * @param int    $neurons Neurons to add.
 * @return array{day: string, neurons: int, estimated: int, features: array<string, int>, tools: array<string, int>, mailed_80: int, mailed_100: int}
 */
function skyphusion_ai_tool_add( $tool, $neurons ) {
	$state = skyphusion_ai_neuron_state();
	if ( ! in_array( $tool, skyphusion_ai_tool_keys(), true ) ) {
		return $state;
	}
	$neurons = (int) $neurons;
	if ( $neurons < 0 ) {
		$neurons = 0;
	}
	$state['tools'][ $tool ] = (int) $state['tools'][ $tool ] + $neurons;
	if ( function_exists( 'update_option' ) ) {
		update_option( skyphusion_ai_neuron_option(), $state, false );
	}
	return $state;
}

/**
 * Name shown beside a tool limit.
 *
 * @param string $tool Tool name.
 * @return string
 */
function skyphusion_ai_tool_label( $tool ) {
	if ( 'text' === $tool ) {
		return __( 'Writing', 'skyphusion-ai' );
	}
	if ( 'vision' === $tool ) {
		return __( 'Image reading', 'skyphusion-ai' );
	}
	if ( 'image' === $tool ) {
		return __( 'Image generation', 'skyphusion-ai' );
	}
	if ( 'speech' === $tool ) {
		return __( 'Speech', 'skyphusion-ai' );
	}
	if ( 'embeddings' === $tool ) {
		return __( 'Embeddings', 'skyphusion-ai' );
	}
	if ( 'assistant' === $tool ) {
		return __( 'Site assistant', 'skyphusion-ai' );
	}
	if ( 'translate' === $tool ) {
		return __( 'Translation', 'skyphusion-ai' );
	}
	return $tool;
}

/**
 * Whether this tool's limit counts toward the shared-budget warning.
 *
 * A limit of zero does not count. The site assistant counts only while it
 * is on. Embeddings, writing, image reading, image generation, speech, and
 * translation count whenever a limit is saved, because those calls can run
 * without a second switch.
 *
 * @param string $tool Tool name.
 * @return bool
 */
function skyphusion_ai_tool_counts( $tool ) {
	if ( skyphusion_ai_tool_cap( $tool ) < 1 ) {
		return false;
	}
	if ( 'assistant' === $tool ) {
		if ( ! function_exists( 'skyphusion_ai_assistant_config' ) ) {
			return false;
		}
		$config = skyphusion_ai_assistant_config();
		return ! empty( $config['enabled'] );
	}
	return true;
}

/**
 * Sum of the tool limits that can be reached today, including backfill.
 *
 * Backfill is included only while that job is on and its own limit is at
 * least 1. A job that is off does not add its limit to the warning.
 *
 * @return int
 */
function skyphusion_ai_tool_limit_sum() {
	$sum = 0;
	foreach ( skyphusion_ai_tool_keys() as $tool ) {
		if ( skyphusion_ai_tool_counts( $tool ) ) {
			$sum += skyphusion_ai_tool_cap( $tool );
		}
	}
	if ( ! function_exists( 'skyphusion_ai_backfill_config' ) ) {
		return $sum;
	}
	$config = skyphusion_ai_backfill_config();
	if ( empty( $config['enabled'] ) ) {
		return $sum;
	}
	$limit = isset( $config['limit'] ) ? (int) $config['limit'] : 0;
	if ( $limit > 0 ) {
		$sum += $limit;
	}
	return $sum;
}

/**
 * Warning when the tool limits add up to more than the site budget, or empty.
 *
 * @return string
 */
function skyphusion_ai_tool_warning() {
	$sum = skyphusion_ai_tool_limit_sum();
	$cap = skyphusion_ai_neuron_cap();
	if ( $sum <= $cap ) {
		return '';
	}
	$over = $sum - $cap;
	if ( 'cheapest' === skyphusion_ai_neuron_over_mode() ) {
		return sprintf(
			/* translators: 1: sum of the tool limits, 2: daily budget, 3: neurons over that budget. */
			__( 'These tool limits add up to %1$d neurons, %3$d more than the daily budget of %2$d. The daily budget is reached first. A plain text call can switch to the cheapest priced model. The other tools pause. Lower the tool limits by %3$d in total, or raise the daily budget.', 'skyphusion-ai' ),
			$sum,
			$cap,
			$over
		);
	}
	return sprintf(
		/* translators: 1: sum of the tool limits, 2: daily budget, 3: neurons over that budget. */
		__( 'These tool limits add up to %1$d neurons, %3$d more than the daily budget of %2$d. The daily budget is reached first and stops every tool. Lower the tool limits by %3$d in total, or raise the daily budget.', 'skyphusion-ai' ),
		$sum,
		$cap,
		$over
	);
}

/**
 * Print one number field per tool, with today's use.
 *
 * @return void
 */
function skyphusion_ai_tool_fields() {
	echo '<p class="description">' . esc_html__( 'Zero means that tool has no limit of its own. A tool at its limit stops. The other tools continue. The public search box is not in these limits.', 'skyphusion-ai' ) . '</p>';
	foreach ( skyphusion_ai_tool_keys() as $tool ) {
		echo '<p><label for="skyphusion_ai_tool_' . esc_attr( $tool ) . '">' . esc_html( skyphusion_ai_tool_label( $tool ) ) . '</label> ';
		printf(
			'<input type="number" class="small-text" id="skyphusion_ai_tool_%1$s" name="skyphusion_ai_settings[tool_caps][%1$s]" value="%2$s" min="0" step="1" />',
			esc_attr( $tool ),
			esc_attr( (string) skyphusion_ai_tool_cap( $tool ) )
		);
		echo ' ';
		echo esc_html(
			sprintf(
				/* translators: %d: neurons this tool has used today. */
				__( 'Today: %d neurons.', 'skyphusion-ai' ),
				skyphusion_ai_tool_used( $tool )
			)
		);
		echo '</p>';
	}
	$warning = skyphusion_ai_tool_warning();
	if ( '' !== $warning ) {
		echo '<p class="description">' . esc_html( $warning ) . '</p>';
	}
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
		return skyphusion_ai_neuron_keep_history( $blank, $stored );
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
	if ( isset( $stored['tools'] ) && is_array( $stored['tools'] ) ) {
		foreach ( skyphusion_ai_tool_keys() as $tool ) {
			$tool_value = isset( $stored['tools'][ $tool ] ) ? (int) $stored['tools'][ $tool ] : 0;
			if ( $tool_value < 0 ) {
				$tool_value = 0;
			}
			$blank['tools'][ $tool ] = $tool_value;
		}
	}
	$blank['mailed_80']  = empty( $stored['mailed_80'] ) ? 0 : 1;
	$blank['mailed_100'] = empty( $stored['mailed_100'] ) ? 0 : 1;
	return skyphusion_ai_neuron_keep_history( $blank, $stored );
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
 * Keep the month history on a row.
 *
 * The history is dates, neuron totals, and call counts. It has no prompt,
 * no page text, and no IP address.
 *
 * @param array<string, mixed> $blank  Today's row.
 * @param mixed                $stored Stored row.
 * @return array<string, mixed>
 */
function skyphusion_ai_neuron_keep_history( $blank, $stored ) {
	$blank['days']       = array();
	$blank['month_sent'] = '';
	if ( ! is_array( $stored ) ) {
		return $blank;
	}
	if ( isset( $stored['days'] ) && is_array( $stored['days'] ) ) {
		$blank['days'] = skyphusion_ai_neuron_days_clean( $stored['days'] );
	}
	if ( isset( $stored['month_sent'] ) && is_string( $stored['month_sent'] ) && 1 === preg_match( '/^\d{4}-\d{2}$/', $stored['month_sent'] ) ) {
		$blank['month_sent'] = $stored['month_sent'];
	}
	return $blank;
}

/**
 * A date key the history will keep, or an empty string.
 *
 * @param mixed $day Raw day.
 * @return string
 */
function skyphusion_ai_neuron_day_key( $day ) {
	$day = (string) $day;
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
		return '';
	}
	return $day;
}

/**
 * History rows reduced to neurons and calls.
 *
 * @param mixed $days Raw history.
 * @return array<string, array{neurons: int, calls: int}>
 */
function skyphusion_ai_neuron_days_clean( $days ) {
	$clean = array();
	if ( ! is_array( $days ) ) {
		return $clean;
	}
	foreach ( $days as $day => $row ) {
		$key = skyphusion_ai_neuron_day_key( $day );
		if ( '' === $key || ! is_array( $row ) ) {
			continue;
		}
		$neurons = isset( $row['neurons'] ) ? (int) $row['neurons'] : 0;
		$calls   = isset( $row['calls'] ) ? (int) $row['calls'] : 0;
		if ( $neurons < 0 ) {
			$neurons = 0;
		}
		if ( $calls < 0 ) {
			$calls = 0;
		}
		if ( 0 === $neurons && 0 === $calls ) {
			continue;
		}
		$clean[ $key ] = array(
			'neurons' => $neurons,
			'calls'   => $calls,
		);
	}
	return $clean;
}

/**
 * Drop history older than 400 days.
 *
 * @param array<string, array{neurons: int, calls: int}> $days  Clean history.
 * @param string                                         $today UTC day.
 * @return array<string, array{neurons: int, calls: int}>
 */
function skyphusion_ai_neuron_days_prune( $days, $today ) {
	$today = skyphusion_ai_neuron_day_key( $today );
	if ( '' === $today ) {
		return $days;
	}
	$stamp = gmmktime( 0, 0, 0, (int) substr( $today, 5, 2 ), (int) substr( $today, 8, 2 ), (int) substr( $today, 0, 4 ) );
	$cut   = gmdate( 'Y-m-d', $stamp - ( 400 * 86400 ) );
	$kept  = array();
	foreach ( $days as $day => $row ) {
		if ( $day >= $cut && $day <= $today ) {
			$kept[ $day ] = $row;
		}
	}
	return $kept;
}

/**
 * The calendar month before a UTC day.
 *
 * @param string $today UTC day.
 * @return string
 */
function skyphusion_ai_neuron_previous_month( $today ) {
	$today = skyphusion_ai_neuron_day_key( $today );
	if ( '' === $today ) {
		return '';
	}
	$year  = (int) substr( $today, 0, 4 );
	$month = (int) substr( $today, 5, 2 );
	--$month;
	if ( $month < 1 ) {
		$month = 12;
		--$year;
	}
	return sprintf( '%04d-%02d', $year, $month );
}

/**
 * Neurons and calls stored for one month.
 *
 * @param array<string, array{neurons: int, calls: int}> $days  Clean history.
 * @param string                                         $month Month as Y-m.
 * @return array{neurons: int, calls: int}
 */
function skyphusion_ai_neuron_month_sum( $days, $month ) {
	$neurons = 0;
	$calls   = 0;
	if ( 1 !== preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
		return array(
			'neurons' => 0,
			'calls'   => 0,
		);
	}
	foreach ( $days as $day => $row ) {
		if ( substr( (string) $day, 0, 7 ) !== $month ) {
			continue;
		}
		$neurons += (int) $row['neurons'];
		$calls   += (int) $row['calls'];
	}
	return array(
		'neurons' => $neurons,
		'calls'   => $calls,
	);
}

/**
 * Move a finished UTC day into the history and start today at zero.
 *
 * Reading today's row does not do this. A later call, or the admin
 * screen, does. The copy spends nothing and stores no prompt.
 *
 * @return void
 */
function skyphusion_ai_neuron_roll() {
	if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
		return;
	}
	$stored = get_option( skyphusion_ai_neuron_option(), array() );
	if ( ! is_array( $stored ) ) {
		return;
	}
	$today = skyphusion_ai_neuron_today();
	$day   = isset( $stored['day'] ) ? skyphusion_ai_neuron_day_key( $stored['day'] ) : '';
	if ( '' === $day || $day >= $today ) {
		return;
	}
	$calls = 0;
	if ( isset( $stored['features'] ) && is_array( $stored['features'] ) ) {
		foreach ( $stored['features'] as $count ) {
			$count = (int) $count;
			if ( $count > 0 ) {
				$calls += $count;
			}
		}
	}
	$neurons = isset( $stored['neurons'] ) ? (int) $stored['neurons'] : 0;
	if ( $neurons < 0 ) {
		$neurons = 0;
	}
	$days = array();
	if ( isset( $stored['days'] ) ) {
		$days = skyphusion_ai_neuron_days_clean( $stored['days'] );
	}
	if ( $neurons > 0 || $calls > 0 ) {
		$days[ $day ] = array(
			'neurons' => $neurons,
			'calls'   => $calls,
		);
	}
	$fresh         = skyphusion_ai_neuron_blank( $today );
	$fresh         = skyphusion_ai_neuron_keep_history( $fresh, $stored );
	$fresh['days'] = skyphusion_ai_neuron_days_prune( $days, $today );
	update_option( skyphusion_ai_neuron_option(), $fresh, false );
}

/**
 * Mail text for one finished month.
 *
 * @param string $month   Month as Y-m.
 * @param int    $neurons Neurons in that month.
 * @param int    $calls   Calls in that month.
 * @return array{subject: string, body: string}
 */
function skyphusion_ai_neuron_month_mail_text( $month, $neurons, $calls ) {
	$subject = sprintf(
		/* translators: %s: month as Y-m. */
		__( 'Workers AI use for %s', 'skyphusion-ai' ),
		$month
	);
	$body = sprintf(
		/* translators: 1: neurons in the month, 2: month as Y-m, 3: number of calls. */
		__( 'Workers AI used %1$d neurons in %2$s across %3$d calls. The public search box and the chat button are not in this total.', 'skyphusion-ai' ),
		$neurons,
		$month,
		$calls
	);
	return array(
		'subject' => $subject,
		'body'    => $body,
	);
}

/**
 * Send the previous month once.
 *
 * A month with no stored use does not send a mail. A failed send is not
 * retried. The message is the stored totals only.
 *
 * @return void
 */
function skyphusion_ai_neuron_month_mail() {
	if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
		return;
	}
	$previous = skyphusion_ai_neuron_previous_month( skyphusion_ai_neuron_today() );
	if ( '' === $previous ) {
		return;
	}
	$stored = get_option( skyphusion_ai_neuron_option(), array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	$sent = '';
	if ( isset( $stored['month_sent'] ) && is_string( $stored['month_sent'] ) ) {
		$sent = $stored['month_sent'];
	}
	if ( $sent === $previous ) {
		return;
	}
	$days  = isset( $stored['days'] ) ? skyphusion_ai_neuron_days_clean( $stored['days'] ) : array();
	$sum   = skyphusion_ai_neuron_month_sum( $days, $previous );
	$ready = function_exists( 'wp_mail' );
	$email = function_exists( 'get_option' ) ? get_option( 'admin_email', '' ) : '';
	if ( ! is_string( $email ) ) {
		$email = '';
	}
	if ( function_exists( 'is_email' ) && '' !== $email && ! is_email( $email ) ) {
		$email = '';
	}
	if ( ( $sum['neurons'] > 0 || $sum['calls'] > 0 ) && $ready && '' !== $email ) {
		$text = skyphusion_ai_neuron_month_mail_text( $previous, $sum['neurons'], $sum['calls'] );
		wp_mail( $email, $text['subject'], $text['body'] );
	}
	if ( ( 0 === $sum['neurons'] && 0 === $sum['calls'] ) || ( $ready && '' !== $email ) ) {
		$stored['month_sent'] = $previous;
		if ( ! isset( $stored['day'] ) ) {
			$stored['day'] = skyphusion_ai_neuron_today();
		}
		update_option( skyphusion_ai_neuron_option(), $stored, false );
	}
}

/**
 * Archive a finished day, then send last month when it is due.
 *
 * @return void
 */
function skyphusion_ai_neuron_month_check() {
	skyphusion_ai_neuron_roll();
	skyphusion_ai_neuron_month_mail();
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
	skyphusion_ai_neuron_roll();
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
	skyphusion_ai_neuron_month_mail();
	return $state;
}

/**
 * Count one response under a feature.
 *
 * A response with no neuron figure and no token figure still counts as a
 * call and adds no neurons.
 *
 * The fourth argument names the tool bucket. It defaults to the feature.
 * Backfill passes backfill so those neurons stay on the backfill counter.
 * The site assistant and translation pass their own tool so they do not
 * spend the writing limit. The call also stores a receipt for the current
 * user. That receipt does not store an IP address.
 *
 * @param string      $feature Feature name.
 * @param string      $model   Model id.
 * @param mixed       $decoded Response JSON.
 * @param string|null $tool    Tool bucket. Null uses the feature name.
 * @return array{day: string, neurons: int, estimated: int, features: array<string, int>, tools: array<string, int>, mailed_80: int, mailed_100: int}
 */
function skyphusion_ai_neuron_record( $feature, $model, $decoded, $tool = null ) {
	$measured = skyphusion_ai_neuron_measure( $model, $decoded );
	$neurons  = $measured['known'] ? (int) $measured['neurons'] : 0;
	$state    = skyphusion_ai_neuron_add( $feature, $neurons, $measured['known'] && $measured['estimated'] );
	$bucket   = is_string( $tool ) && '' !== $tool ? $tool : $feature;
	if ( in_array( $bucket, skyphusion_ai_tool_keys(), true ) ) {
		$state = skyphusion_ai_tool_add( $bucket, $neurons );
	}
	skyphusion_ai_neuron_receipt_store(
		skyphusion_ai_neuron_receipt_user(),
		is_string( $feature ) ? $feature : '',
		is_string( $model ) ? $model : '',
		$neurons
	);
	if ( function_exists( 'skyphusion_ai_activity_add' ) ) {
		$ok = ! ( function_exists( 'is_wp_error' ) && is_wp_error( $decoded ) );
		skyphusion_ai_activity_add(
			is_string( $feature ) ? $feature : '',
			is_string( $model ) ? $model : '',
			$ok ? $neurons : 0,
			$ok
		);
	}
	return $state;
}

/**
 * Transient key for one user's last receipt.
 *
 * @param int $user_id User id.
 * @return string Empty when the id is not 1 or more.
 */
function skyphusion_ai_neuron_receipt_key( $user_id ) {
	$user_id = (int) $user_id;
	if ( 1 > $user_id ) {
		return '';
	}
	return 'skyphusion_ai_receipt_' . $user_id;
}

/**
 * User id for the receipt, or 0 when nobody is logged in.
 *
 * A harness may set cfais_user_id. Otherwise the current WordPress user is used.
 *
 * @return int
 */
function skyphusion_ai_neuron_receipt_user() {
	if ( isset( $GLOBALS['cfais_user_id'] ) ) {
		return (int) $GLOBALS['cfais_user_id'];
	}
	if ( function_exists( 'get_current_user_id' ) ) {
		return (int) get_current_user_id();
	}
	return 0;
}

/**
 * Keep the four receipt fields, or an empty array.
 *
 * @param mixed $stored Stored value.
 * @return array<string, int|string>
 */
function skyphusion_ai_neuron_receipt_fields( $stored ) {
	if ( ! is_array( $stored ) ) {
		return array();
	}
	if ( ! isset( $stored['feature'], $stored['model'], $stored['neurons'], $stored['time'] ) ) {
		return array();
	}
	if ( ! is_string( $stored['feature'] ) || ! is_string( $stored['model'] ) ) {
		return array();
	}
	return array(
		'feature' => $stored['feature'],
		'model'   => $stored['model'],
		'neurons' => (int) $stored['neurons'],
		'time'    => (int) $stored['time'],
	);
}

/**
 * Save the last receipt for one user.
 *
 * User id 0 stores nothing. The value holds the feature, the model, the
 * neurons, and the time. It does not store a prompt, a token, an IP
 * address, or the user id.
 *
 * @param int    $user_id User id. Zero stores nothing.
 * @param string $feature Feature name.
 * @param string $model   Model id.
 * @param int    $neurons Neurons for that call.
 * @return void
 */
function skyphusion_ai_neuron_receipt_store( $user_id, $feature, $model, $neurons ) {
	$user_id = (int) $user_id;
	if ( 1 > $user_id || ! function_exists( 'set_transient' ) ) {
		return;
	}
	set_transient(
		skyphusion_ai_neuron_receipt_key( $user_id ),
		array(
			'feature' => is_string( $feature ) ? $feature : '',
			'model'   => is_string( $model ) ? $model : '',
			'neurons' => (int) $neurons,
			'time'    => time(),
		),
		600
	);
}

/**
 * Last receipt for one user, or an empty array when there is none.
 *
 * @param int $user_id User id.
 * @return array<string, int|string>
 */
function skyphusion_ai_neuron_receipt_get( $user_id ) {
	$user_id = (int) $user_id;
	if ( 1 > $user_id || ! function_exists( 'get_transient' ) ) {
		return array();
	}
	return skyphusion_ai_neuron_receipt_fields( get_transient( skyphusion_ai_neuron_receipt_key( $user_id ) ) );
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
	$model   = skyphusion_ai_neuron_model_from_call( (string) $url, $payload );
	$feature = skyphusion_ai_neuron_feature( $model, $payload );
	$refusal = skyphusion_ai_neuron_refusal();
	if ( is_wp_error( $refusal ) ) {
		$cheap = skyphusion_ai_neuron_cheapest_text();
		$plain = 'text' === $feature && ! skyphusion_ai_neuron_payload_has_image( $payload );
		if ( ! ( 'cheapest' === skyphusion_ai_neuron_over_mode() && $plain && '' !== $cheap && $model === $cheap ) ) {
			return skyphusion_ai_neuron_refusal_text();
		}
	}
	if ( skyphusion_ai_tool_blocked( $feature ) ) {
		return skyphusion_ai_tool_refusal_text();
	}
	$GLOBALS['skyphusion_ai_neuron_call'] = array(
		'feature' => $feature,
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
	$tool_refusal = skyphusion_ai_tool_refusal( 'embeddings' );
	if ( is_wp_error( $tool_refusal ) ) {
		return $tool_refusal;
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
	if ( function_exists( 'skyphusion_ai_gateway_note' ) ) {
		skyphusion_ai_gateway_note( $url, 'embeddings', $response );
	}
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
		__( 'The default is 10,000 neurons. Cloudflare free plans include that allowance. Past it, you pay Cloudflare. Today (UTC): %1$d neurons, %2$d of them estimates. The public search box and the chat button are not counted. PHP does not see those calls. The AI Search instance rate limit is the cap for them.', 'skyphusion-ai' ),
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
	$used  = (int) $state['neurons'];
	$cap   = skyphusion_ai_neuron_cap();
	if ( skyphusion_ai_neuron_cap_saved() ) {
		return sprintf(
			/* translators: 1: neurons used today, 2: daily cap. */
			__( '%1$d / %2$d neurons', 'skyphusion-ai' ),
			$used,
			$cap
		);
	}
	return sprintf(
		/* translators: 1: neurons used today, 2: daily cap. */
		__( '%1$d / %2$d free', 'skyphusion-ai' ),
		$used,
		$cap
	);
}

/**
 * One sentence for a receipt: the feature, the model, the neurons, and the UTC time.
 *
 * @param mixed $receipt Receipt fields.
 * @return string
 */
function skyphusion_ai_neuron_receipt_sentence( $receipt ) {
	if ( ! is_array( $receipt ) ) {
		return '';
	}
	$feature = isset( $receipt['feature'] ) && is_string( $receipt['feature'] ) ? $receipt['feature'] : '';
	$model   = isset( $receipt['model'] ) && is_string( $receipt['model'] ) ? $receipt['model'] : '';
	$neurons = isset( $receipt['neurons'] ) ? (int) $receipt['neurons'] : 0;
	$stamp   = isset( $receipt['time'] ) ? (int) $receipt['time'] : 0;
	return sprintf(
		/* translators: 1: feature name, 2: model id, 3: neuron count, 4: UTC time as Y-m-d H:i. */
		__( 'Last call: %1$s, %2$s, %3$d neurons, %4$s UTC.', 'skyphusion-ai' ),
		$feature,
		$model,
		$neurons,
		gmdate( 'Y-m-d H:i', $stamp )
	);
}

/**
 * True when the current user can edit posts.
 *
 * @return bool
 */
function skyphusion_ai_neuron_receipt_permission() {
	if ( ! function_exists( 'current_user_can' ) ) {
		return false;
	}
	return (bool) current_user_can( 'edit_posts' );
}

/**
 * Last receipt for the current user, or an empty array when there is none.
 *
 * @return array<string, int|string>
 */
function skyphusion_ai_neuron_receipt_payload() {
	return skyphusion_ai_neuron_receipt_get( skyphusion_ai_neuron_receipt_user() );
}

/**
 * Today's neuron total for the REST route.
 *
 * The free flag is true when no budget is saved. The row has no IP address.
 *
 * @return array{neurons: int, cap: int, free: bool}
 */
function skyphusion_ai_neuron_usage_payload() {
	$state = skyphusion_ai_neuron_state();
	return array(
		'neurons' => (int) $state['neurons'],
		'cap'     => skyphusion_ai_neuron_cap(),
		'free'    => ! skyphusion_ai_neuron_cap_saved(),
	);
}

/**
 * Register the receipt route and the usage route.
 *
 * Editors can read both. The values do not include an IP address.
 *
 * @return void
 */
function skyphusion_ai_neuron_register_rest() {
	if ( ! function_exists( 'register_rest_route' ) ) {
		return;
	}
	register_rest_route(
		'skyphusion-ai/v1',
		'/receipt',
		array(
			'methods'             => 'GET',
			'callback'            => 'skyphusion_ai_neuron_receipt_payload',
			'permission_callback' => 'skyphusion_ai_neuron_receipt_permission',
		)
	);
	register_rest_route(
		'skyphusion-ai/v1',
		'/usage',
		array(
			'methods'             => 'GET',
			'callback'            => 'skyphusion_ai_neuron_usage_payload',
			'permission_callback' => 'skyphusion_ai_neuron_receipt_permission',
		)
	);
}

/**
 * Show the last receipt to a user who can edit posts.
 *
 * @return void
 */
function skyphusion_ai_neuron_receipt_notice() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$receipt = skyphusion_ai_neuron_receipt_get( skyphusion_ai_neuron_receipt_user() );
	if ( array() === $receipt ) {
		return;
	}
	$sentence = skyphusion_ai_neuron_receipt_sentence( $receipt );
	if ( '' === $sentence ) {
		return;
	}
	echo '<div class="notice notice-info"><p>' . esc_html( $sentence ) . '</p></div>';
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
	echo '<p><label for="skyphusion_ai_neuron_over">' . esc_html__( 'When the budget is spent', 'skyphusion-ai' ) . '</label></p>';
	echo '<select id="skyphusion_ai_neuron_over" name="skyphusion_ai_settings[neuron_over]">';
	echo '<option value="pause"';
	if ( 'cheapest' !== skyphusion_ai_neuron_over_mode() ) {
		echo ' selected="selected"';
	}
	echo '>' . esc_html__( 'Pause until 00:00 UTC', 'skyphusion-ai' ) . '</option>';
	echo '<option value="cheapest"';
	if ( 'cheapest' === skyphusion_ai_neuron_over_mode() ) {
		echo ' selected="selected"';
	}
	echo '>' . esc_html__( 'Use the cheapest text model', 'skyphusion-ai' ) . '</option>';
	echo '</select>';
	echo '<p class="description">' . esc_html__( 'Only a plain text call can switch. Backfill, embeddings, the site assistant, translation, vision, images, and speech pause either way. The public search box is not in this budget.', 'skyphusion-ai' ) . '</p>';
	skyphusion_ai_tool_fields();
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
	add_action( 'admin_init', 'skyphusion_ai_neuron_month_check', 30 );
	add_action( 'admin_notices', 'skyphusion_ai_neuron_admin_notice' );
	add_action( 'admin_notices', 'skyphusion_ai_neuron_receipt_notice' );
	add_action( 'admin_bar_menu', 'skyphusion_ai_neuron_admin_bar', 80 );
	add_action( 'rest_api_init', 'skyphusion_ai_neuron_register_rest' );
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'sanitize_option_skyphusion_ai_settings', 'skyphusion_ai_neuron_sanitize_option', 20, 3 );
}

require_once __DIR__ . '/gateway.php';
