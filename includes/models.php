<?php
/**
 * Account model list and the per-job model choice.
 *
 * The seven built-in ids stay the defaults when the account list is empty
 * or the read fails. The list is cached for 12 hours. A failed read is
 * remembered for 10 minutes so a dead token is not fetched on every call.
 * The cache stores ids, names, and task names. A price is stored only when
 * the account list sends one. It does not store the token.
 *
 * Marker: model-labels. Context, neurons per 1,000 tokens, and tool or
 * vision flags are copied from that payload. A missing number is not filled in.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jobs that have a picker.
 *
 * @return string[]
 */
function skyphusion_ai_model_jobs() {
	return array( 'writing', 'vision', 'image', 'speech', 'embeddings' );
}

/**
 * Built-in model id for one job.
 *
 * @return array<string, string>
 */
function skyphusion_ai_model_defaults() {
	return array(
		'writing'    => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
		'vision'     => '@cf/mistralai/mistral-small-3.1-24b-instruct',
		'image'      => '@cf/black-forest-labs/flux-1-schnell',
		'speech'     => '@cf/deepgram/aura-1',
		'embeddings' => '@cf/baai/bge-large-en-v1.5',
	);
}

/**
 * Built-in id for one job, or an empty string.
 *
 * @param string $job Job name.
 * @return string
 */
function skyphusion_ai_model_default( $job ) {
	$defaults = skyphusion_ai_model_defaults();
	return isset( $defaults[ $job ] ) ? $defaults[ $job ] : '';
}

/**
 * The model id for one job. A missing or invalid stored id uses the built-in id.
 *
 * @param string $job Job name.
 * @return string
 */
function skyphusion_ai_model_choice( $job ) {
	$fallback = skyphusion_ai_model_default( $job );
	if ( '' === $fallback || ! function_exists( 'skyphusion_ai_settings' ) ) {
		return $fallback;
	}
	$settings = skyphusion_ai_settings();
	$key      = 'model_' . $job;
	if ( ! isset( $settings[ $key ] ) || ! function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		return $fallback;
	}
	$chosen = cloudflare_ai_provider_model_id( $settings[ $key ] );
	if ( '' === $chosen ) {
		return $fallback;
	}
	return $chosen;
}

/**
 * Short tasks that may use the light model.
 *
 * @return string[]
 */
function skyphusion_ai_short_tasks() {
	return array( 'title', 'excerpt', 'meta', 'moderation', 'alt' );
}

/**
 * Saved light model, or an empty string when the field is empty.
 *
 * An id the account list does not allow is treated as empty. No model id
 * is filled in by the plugin.
 *
 * @return string
 */
function skyphusion_ai_model_light_id() {
	if ( ! function_exists( 'skyphusion_ai_settings' ) || ! function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		return '';
	}
	$settings = skyphusion_ai_settings();
	if ( ! is_array( $settings ) || ! isset( $settings['model_light'] ) ) {
		return '';
	}
	$id = cloudflare_ai_provider_model_id( $settings['model_light'] );
	if ( '' === $id || ! skyphusion_ai_model_id_allowed( $id, 'writing' ) ) {
		return '';
	}
	return $id;
}

/**
 * Light model when one is saved, otherwise the writing model.
 *
 * @return string
 */
function skyphusion_ai_light_model() {
	$light = skyphusion_ai_model_light_id();
	if ( '' !== $light ) {
		return $light;
	}
	return skyphusion_ai_model_choice( 'writing' );
}

/**
 * Model for one task. A model the editor already picked stays that model.
 *
 * @param string $task   Task name.
 * @param string $picked Model id already chosen, or empty.
 * @return string
 */
function skyphusion_ai_model_for_task( $task, $picked = '' ) {
	if ( function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		$picked = cloudflare_ai_provider_model_id( $picked );
	} else {
		$picked = '';
	}
	if ( '' !== $picked ) {
		return $picked;
	}
	if ( in_array( (string) $task, skyphusion_ai_short_tasks(), true ) ) {
		return skyphusion_ai_light_model();
	}
	return skyphusion_ai_model_choice( 'writing' );
}

/**
 * Light model value to store. Empty stays empty.
 *
 * @param mixed $original Previous settings.
 * @param mixed $posted   Posted settings, or null when the form omitted them.
 * @return string
 */
function skyphusion_ai_model_light_stored( $original, $posted ) {
	$previous = '';
	if ( is_array( $original ) && isset( $original['model_light'] ) && function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		$kept = cloudflare_ai_provider_model_id( $original['model_light'] );
		if ( '' !== $kept && skyphusion_ai_model_id_allowed( $kept, 'writing' ) ) {
			$previous = $kept;
		}
	}
	if ( ! is_array( $posted ) || ! array_key_exists( 'model_light', $posted ) ) {
		return $previous;
	}
	$typed = trim( (string) $posted['model_light'] );
	if ( '' === $typed ) {
		return '';
	}
	$id = function_exists( 'cloudflare_ai_provider_model_id' ) ? cloudflare_ai_provider_model_id( $typed ) : '';
	if ( '' === $id || ! skyphusion_ai_model_id_allowed( $id, 'writing' ) ) {
		if ( function_exists( 'skyphusion_ai_reject' ) ) {
			skyphusion_ai_reject(
				'model_light',
				__( 'That light model was not kept. The previous choice stays.', 'skyphusion-ai' )
			);
		}
		return $previous;
	}
	return $id;
}

/**
 * How long a successful account list is kept, in seconds.
 *
 * @return int
 */
function skyphusion_ai_account_models_ttl() {
	return 12 * 3600;
}

/**
 * Model rows from a Cloudflare models search body.
 *
 * @param mixed $decoded Response JSON.
 * @return array<int, array{id: string, name: string, task: string}>
 */
function skyphusion_ai_account_models_from_body( $decoded ) {
	$empty = array();
	if ( ! is_array( $decoded ) ) {
		return $empty;
	}
	if ( array_key_exists( 'success', $decoded ) && true !== $decoded['success'] && 1 !== $decoded['success'] ) {
		return $empty;
	}
	if ( ! isset( $decoded['result'] ) || ! is_array( $decoded['result'] ) ) {
		return $empty;
	}
	$rows = $decoded['result'];
	if ( isset( $rows['models'] ) && is_array( $rows['models'] ) ) {
		$rows = $rows['models'];
	}
	$out = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || ! function_exists( 'cloudflare_ai_provider_model_id' ) ) {
			continue;
		}
		$id = '';
		if ( isset( $row['id'] ) ) {
			$id = cloudflare_ai_provider_model_id( $row['id'] );
		}
		if ( '' === $id && isset( $row['name'] ) ) {
			$id = cloudflare_ai_provider_model_id( $row['name'] );
		}
		if ( '' === $id ) {
			continue;
		}
		$name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
		if ( '' === $name ) {
			$name = $id;
		}
		$task = '';
		if ( isset( $row['task'] ) && is_string( $row['task'] ) ) {
			$task = $row['task'];
		} elseif ( isset( $row['task'] ) && is_array( $row['task'] ) ) {
			if ( isset( $row['task']['name'] ) ) {
				$task = (string) $row['task']['name'];
			} elseif ( isset( $row['task']['id'] ) ) {
				$task = (string) $row['task']['id'];
			}
		}
		$clean = array(
			'id'   => $id,
			'name' => $name,
			'task' => strtolower( trim( $task ) ),
		);
		foreach ( skyphusion_ai_account_model_labels( $row ) as $label_key => $label_value ) {
			$clean[ $label_key ] = $label_value;
		}
		$out[] = $clean;
	}
	return $out;
}

/**
 * Prices the account list sent for one model. A missing number is not filled in.
 *
 * @param array<string, mixed> $row Account row.
 * @return array<string, float>
 */
function skyphusion_ai_account_model_prices( $row ) {
	$out = array();
	if ( ! is_array( $row ) ) {
		return $out;
	}
	foreach ( array( 'price_in', 'price_out', 'price' ) as $key ) {
		if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
			$out[ $key ] = (float) $row[ $key ];
		}
	}
	if ( isset( $row['price'] ) && is_array( $row['price'] ) ) {
		skyphusion_ai_account_model_price_tiers( $out, $row['price'] );
	}
	if ( isset( $row['properties'] ) && is_array( $row['properties'] ) ) {
		foreach ( $row['properties'] as $property ) {
			if ( ! is_array( $property ) || ! isset( $property['value'] ) ) {
				continue;
			}
			$name = isset( $property['property_id'] ) ? strtolower( (string) $property['property_id'] ) : '';
			if ( is_numeric( $property['value'] ) && in_array( $name, array( 'price_in', 'price_out', 'price' ), true ) ) {
				$out[ $name ] = (float) $property['value'];
				continue;
			}
			if ( 'price' === $name && is_array( $property['value'] ) ) {
				skyphusion_ai_account_model_price_tiers( $out, $property['value'] );
			}
		}
	}
	return $out;
}

/**
 * Copy token prices from a tier list. A tier without a number is skipped.
 *
 * @param array<string, float> $out   Prices already found.
 * @param array<int, mixed>    $tiers Tier rows from the account.
 * @return void
 */
function skyphusion_ai_account_model_price_tiers( &$out, $tiers ) {
	foreach ( $tiers as $tier ) {
		if ( ! is_array( $tier ) || ! isset( $tier['unit'], $tier['price'] ) || ! is_numeric( $tier['price'] ) ) {
			continue;
		}
		$slot = skyphusion_ai_account_model_price_slot( $tier['unit'] );
		if ( '' === $slot || isset( $out[ $slot ] ) ) {
			continue;
		}
		$out[ $slot ] = (float) $tier['price'];
	}
}

/**
 * Price key for one tier unit. Any other unit is ignored.
 *
 * @param mixed $unit Unit string from the account.
 * @return string
 */
function skyphusion_ai_account_model_price_slot( $unit ) {
	$unit = strtolower( trim( (string) $unit ) );
	if ( 'per m input tokens' === $unit ) {
		return 'price_in';
	}
	if ( 'per m output tokens' === $unit ) {
		return 'price_out';
	}
	return '';
}

/**
 * Property values keyed by property id.
 *
 * @param array<string, mixed> $row Account row.
 * @return array<string, mixed>
 */
function skyphusion_ai_account_model_property_map( $row ) {
	$map = array();
	if ( ! isset( $row['properties'] ) || ! is_array( $row['properties'] ) ) {
		return $map;
	}
	foreach ( $row['properties'] as $property ) {
		if ( ! is_array( $property ) || ! isset( $property['property_id'] ) || ! array_key_exists( 'value', $property ) ) {
			continue;
		}
		$name = strtolower( trim( (string) $property['property_id'] ) );
		if ( '' === $name ) {
			continue;
		}
		$map[ $name ] = $property['value'];
	}
	return $map;
}

/**
 * True only for the account's own true values.
 *
 * @param mixed $value Property value.
 * @return bool
 */
function skyphusion_ai_account_model_truthy( $value ) {
	if ( true === $value || 1 === $value ) {
		return true;
	}
	if ( ! is_string( $value ) ) {
		return false;
	}
	$lower = strtolower( $value );
	return '1' === $lower || 'true' === $lower;
}

/**
 * Dollars per million tokens, as neurons per 1,000 tokens.
 *
 * Cloudflare prices Workers AI at $0.011 per 1,000 neurons. The dollar
 * figure has to be on the account row. A missing figure stays missing.
 *
 * @param mixed $usd Dollars per million tokens, or null.
 * @return float|null
 */
function skyphusion_ai_account_model_neurons( $usd ) {
	if ( ! is_numeric( $usd ) || (float) $usd <= 0 ) {
		return null;
	}
	return (float) $usd / 0.011;
}

/**
 * Labels the account list sent. A missing label is left off the row.
 *
 * @param array<string, mixed> $row Account row, raw or already stored.
 * @return array<string, mixed>
 */
function skyphusion_ai_account_model_labels( $row ) {
	$out = array();
	if ( ! is_array( $row ) ) {
		return $out;
	}
	foreach ( skyphusion_ai_account_model_prices( $row ) as $price_key => $price_value ) {
		$out[ $price_key ] = $price_value;
	}
	$props   = skyphusion_ai_account_model_property_map( $row );
	$context = null;
	if ( isset( $row['context'] ) && is_numeric( $row['context'] ) ) {
		$context = (int) $row['context'];
	} elseif ( isset( $props['context_window'] ) && is_numeric( $props['context_window'] ) ) {
		$context = (int) $props['context_window'];
	}
	if ( null !== $context && $context > 0 ) {
		$out['context'] = $context;
	}
	$tools = null;
	if ( array_key_exists( 'tools', $row ) ) {
		$tools = ! empty( $row['tools'] );
	} elseif ( array_key_exists( 'function_calling', $props ) ) {
		$tools = skyphusion_ai_account_model_truthy( $props['function_calling'] );
	}
	if ( true === $tools ) {
		$out['tools'] = 1;
	}
	$vision = ! empty( $row['vision'] );
	if ( ! $vision && isset( $props['vision'] ) ) {
		$vision = skyphusion_ai_account_model_truthy( $props['vision'] );
	}
	if ( $vision ) {
		$out['vision'] = 1;
	}
	$sides = array(
		'in'  => isset( $out['price_in'] ) ? $out['price_in'] : null,
		'out' => isset( $out['price_out'] ) ? $out['price_out'] : null,
	);
	foreach ( $sides as $side => $usd ) {
		$key = 'neurons_' . $side;
		if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) && (float) $row[ $key ] > 0 ) {
			$out[ $key ] = (float) $row[ $key ];
			continue;
		}
		if ( isset( $props[ $key ] ) && is_numeric( $props[ $key ] ) && (float) $props[ $key ] > 0 ) {
			$out[ $key ] = (float) $props[ $key ];
			continue;
		}
		$neurons = skyphusion_ai_account_model_neurons( $usd );
		if ( null !== $neurons ) {
			$out[ $key ] = $neurons;
		}
	}
	if ( ! empty( $row['licence'] ) ) {
		$out['licence'] = 1;
	}
	return $out;
}

/**
 * Kind and vision flag for an account task name.
 *
 * @param string $task Task name from the account list.
 * @return array{0: string, 1: bool}
 */
function skyphusion_ai_account_model_kind( $task ) {
	$task   = strtolower( (string) $task );
	$vision = false !== strpos( $task, 'vision' ) || false !== strpos( $task, 'image-to-text' ) || false !== strpos( $task, 'image to text' );
	if ( false !== strpos( $task, 'embed' ) ) {
		return array( 'embeddings', false );
	}
	if ( false !== strpos( $task, 'text-to-speech' ) || false !== strpos( $task, 'text to speech' ) || 'speech' === $task || false !== strpos( $task, 'tts' ) ) {
		return array( 'speech', false );
	}
	if ( false !== strpos( $task, 'text-to-image' ) || false !== strpos( $task, 'text to image' ) || false !== strpos( $task, 'image-generation' ) ) {
		return array( 'image', false );
	}
	return array( 'text', $vision );
}

/**
 * Catalog-shaped entry for one account model.
 *
 * @param array{id: string, name: string, task: string} $model Account row.
 * @return array{id: string, name: string, kind: string, tools: bool, vision: bool, json: bool}
 */
function skyphusion_ai_account_model_as_entry( $model ) {
	$kind = skyphusion_ai_account_model_kind( isset( $model['task'] ) ? $model['task'] : '' );
	return array(
		'id'     => $model['id'],
		'name'   => $model['name'],
		'kind'   => $kind[0],
		'tools'  => ! empty( $model['tools'] ),
		'vision' => $kind[1] || ! empty( $model['vision'] ),
		'json'   => false,
	);
}

/**
 * Whether one kind belongs on one picker.
 *
 * @param string $job    Job name.
 * @param string $kind   text, vision is separate, image, speech, or embeddings.
 * @param bool   $vision Whether the model accepts an image.
 * @return bool
 */
function skyphusion_ai_model_matches( $job, $kind, $vision ) {
	if ( 'writing' === $job ) {
		return 'text' === $kind;
	}
	if ( 'vision' === $job ) {
		return true === $vision;
	}
	if ( 'image' === $job ) {
		return 'image' === $kind;
	}
	if ( 'speech' === $job ) {
		return 'speech' === $kind;
	}
	if ( 'embeddings' === $job ) {
		return 'embeddings' === $kind;
	}
	return false;
}

/**
 * Account id and token, from the same order as the provider.
 *
 * @return array{account_id: string, token: string}
 */
function skyphusion_ai_model_credentials() {
	$account = '';
	if ( function_exists( 'skyphusion_ai_settings' ) ) {
		$settings = skyphusion_ai_settings();
		$account  = isset( $settings['account_id'] ) ? (string) $settings['account_id'] : '';
	}
	$key = '';
	if ( function_exists( 'cloudflare_ai_provider_connector_key' ) ) {
		$key = (string) cloudflare_ai_provider_connector_key();
	}
	if ( function_exists( 'cloudflare_ai_provider_resolve_credentials' ) ) {
		return cloudflare_ai_provider_resolve_credentials( $key, $account );
	}
	return array(
		'account_id' => '',
		'token'      => '',
	);
}

/**
 * True when a recent read failed and should not be repeated yet.
 *
 * @return bool
 */
function skyphusion_ai_account_models_negative() {
	if ( ! function_exists( 'get_transient' ) ) {
		return false;
	}
	$stored = get_transient( 'skyphusion_ai_models' );
	return is_array( $stored ) && empty( $stored['ok'] );
}

/**
 * Cached account models for the current account, or an empty list.
 *
 * @return array<int, array{id: string, name: string, task: string}>
 */
function skyphusion_ai_account_models_cached() {
	if ( ! function_exists( 'get_transient' ) ) {
		return array();
	}
	$stored = get_transient( 'skyphusion_ai_models' );
	if ( ! is_array( $stored ) || empty( $stored['ok'] ) || ! isset( $stored['models'] ) || ! is_array( $stored['models'] ) ) {
		return array();
	}
	$credentials = skyphusion_ai_model_credentials();
	if ( '' === $credentials['account_id'] || ! isset( $stored['account'] ) || $stored['account'] !== $credentials['account_id'] ) {
		return array();
	}
	return skyphusion_ai_account_models_from_body( array( 'result' => $stored['models'] ) );
}

/**
 * Store the list. The token is not a field on the row.
 *
 * @param string $account Account id.
 * @param array  $models  Rows. Each row has id, name, and task.
 * @param bool   $ok      False marks a failed read.
 * @return array<int, array{id: string, name: string, task: string}>
 */
function skyphusion_ai_account_models_store( $account, $models, $ok ) {
	$clean = array();
	foreach ( $models as $model ) {
		if ( ! is_array( $model ) || empty( $model['id'] ) ) {
			continue;
		}
		$clean_row = array(
			'id'   => (string) $model['id'],
			'name' => isset( $model['name'] ) ? (string) $model['name'] : (string) $model['id'],
			'task' => isset( $model['task'] ) ? (string) $model['task'] : '',
		);
		foreach ( skyphusion_ai_account_model_labels( $model ) as $label_key => $label_value ) {
			$clean_row[ $label_key ] = $label_value;
		}
		$clean[] = $clean_row;
	}
	if ( function_exists( 'set_transient' ) ) {
		set_transient(
			'skyphusion_ai_models',
			array(
				'ok'      => $ok ? 1 : 0,
				'account' => (string) $account,
				'models'  => $clean,
			),
			$ok ? skyphusion_ai_account_models_ttl() : 600
		);
	}
	return $clean;
}

/**
 * Read the account model list. An empty return leaves the built-in ids in place.
 *
 * @return array<int, array{id: string, name: string, task: string}>
 */
function skyphusion_ai_account_models_refresh() {
	$credentials = skyphusion_ai_model_credentials();
	$account     = $credentials['account_id'];
	$token       = $credentials['token'];
	if ( '' === $account || '' === $token || ! function_exists( 'wp_remote_get' ) || ! function_exists( 'wp_remote_retrieve_body' ) ) {
		return array();
	}
	$collected = array();
	$page      = 1;
	while ( $page <= 4 ) {
		$url      = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( $account ) . '/ai/models/search?per_page=50&page=' . $page;
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);
		$failed   = function_exists( 'is_wp_error' ) && is_wp_error( $response );
		$code     = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		if ( $failed || $code < 200 || $code >= 300 ) {
			if ( 1 === $page ) {
				skyphusion_ai_account_models_store( $account, array(), false );
				return array();
			}
			break;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$rows    = skyphusion_ai_account_models_from_body( $decoded );
		if ( array() === $rows ) {
			break;
		}
		foreach ( $rows as $row ) {
			$collected[] = $row;
		}
		if ( count( $rows ) < 50 ) {
			break;
		}
		++$page;
	}
	if ( array() === $collected ) {
		skyphusion_ai_account_models_store( $account, array(), false );
		return array();
	}
	return skyphusion_ai_account_models_store( $account, $collected, true );
}

/**
 * Account models for this request. One read, then the saved list.
 *
 * @return array<int, array{id: string, name: string, task: string}>
 */
function skyphusion_ai_account_models_current() {
	if ( isset( $GLOBALS['skyphusion_ai_models_runtime'] ) && is_array( $GLOBALS['skyphusion_ai_models_runtime'] ) ) {
		return $GLOBALS['skyphusion_ai_models_runtime'];
	}
	if ( skyphusion_ai_account_models_negative() ) {
		$GLOBALS['skyphusion_ai_models_runtime'] = array();
		return array();
	}
	$models = skyphusion_ai_account_models_cached();
	if ( array() === $models ) {
		$models = skyphusion_ai_account_models_refresh();
	}
	$GLOBALS['skyphusion_ai_models_runtime'] = $models;
	return $models;
}

/**
 * Catalog entry, or the account entry when the catalog does not have the id.
 *
 * An embeddings model stays on the embeddings picker. The AI Client does not
 * build a text or speech model for it, so this returns null for that kind.
 *
 * @param string $model_id Model id.
 * @return array|null
 */
function skyphusion_ai_model_resolved_entry( $model_id ) {
	if ( ! function_exists( 'cloudflare_ai_provider_catalog_entry' ) ) {
		return null;
	}
	$entry = cloudflare_ai_provider_catalog_entry( $model_id );
	if ( null === $entry ) {
		$entry = skyphusion_ai_account_model_entry( $model_id );
	}
	if ( is_array( $entry ) && isset( $entry['kind'] ) && 'embeddings' === $entry['kind'] ) {
		return null;
	}
	return $entry;
}

/**
 * Catalog-shaped entry for an account model, or null when the id is not on the list.
 *
 * A built-in catalog id returns null here. The caller already asked the catalog.
 *
 * @param string $model_id Model id.
 * @return array{id: string, name: string, kind: string, tools: bool, vision: bool, json: bool}|null
 */
function skyphusion_ai_account_model_entry( $model_id ) {
	if ( ! function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		return null;
	}
	$model_id = cloudflare_ai_provider_model_id( $model_id );
	if ( '' === $model_id ) {
		return null;
	}
	if ( function_exists( 'cloudflare_ai_provider_catalog_entry' ) && null !== cloudflare_ai_provider_catalog_entry( $model_id ) ) {
		return null;
	}
	foreach ( skyphusion_ai_account_models_current() as $model ) {
		if ( $model['id'] === $model_id ) {
			return skyphusion_ai_account_model_as_entry( $model );
		}
	}
	return null;
}

/**
 * True when this id may be saved for this job.
 *
 * @param string $id     Model id.
 * @param string $job    Job name.
 * @param array  $models Account rows. Null reads the cache.
 * @return bool
 */
function skyphusion_ai_model_id_allowed( $id, $job, $models = null ) {
	if ( ! function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		return false;
	}
	$id = cloudflare_ai_provider_model_id( $id );
	if ( '' === $id ) {
		return false;
	}
	if ( skyphusion_ai_model_default( $job ) === $id ) {
		return true;
	}
	if ( function_exists( 'cloudflare_ai_provider_catalog_entry' ) ) {
		$entry = cloudflare_ai_provider_catalog_entry( $id );
		if ( is_array( $entry ) && skyphusion_ai_model_matches( $job, $entry['kind'], ! empty( $entry['vision'] ) ) ) {
			return true;
		}
	}
	if ( null === $models ) {
		$models = skyphusion_ai_account_models_current();
	}
	foreach ( $models as $model ) {
		if ( ! is_array( $model ) || ! isset( $model['id'] ) || $model['id'] !== $id ) {
			continue;
		}
		$kind = skyphusion_ai_account_model_kind( isset( $model['task'] ) ? $model['task'] : '' );
		if ( skyphusion_ai_model_matches( $job, $kind[0], $kind[1] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Options for one picker. The built-in models are always present.
 *
 * @param string $job    Job name.
 * @param array  $models Account rows. Null reads the cache.
 * @return array<int, array{id: string, name: string}>
 */
function skyphusion_ai_model_options( $job, $models = null ) {
	$options = array();
	$seen    = array();
	$add     = function ( $id, $name, $labels = array() ) use ( &$options, &$seen ) {
		if ( isset( $seen[ $id ] ) ) {
			$index = $seen[ $id ];
			if ( is_array( $labels ) ) {
				foreach ( $labels as $label_key => $label_value ) {
					if ( 'id' === $label_key || 'name' === $label_key || array_key_exists( $label_key, $options[ $index ] ) ) {
						continue;
					}
					$options[ $index ][ $label_key ] = $label_value;
				}
			}
			return;
		}
		$seen[ $id ] = count( $options );
		$row         = array(
			'id'   => $id,
			'name' => $name,
		);
		if ( is_array( $labels ) ) {
			foreach ( $labels as $label_key => $label_value ) {
				if ( 'id' === $label_key || 'name' === $label_key ) {
					continue;
				}
				$row[ $label_key ] = $label_value;
			}
		}
		$options[] = $row;
	};
	if ( 'embeddings' === $job ) {
		$default = skyphusion_ai_model_default( 'embeddings' );
		$add( $default, $default );
	}
	if ( function_exists( 'cloudflare_ai_provider_catalog' ) ) {
		foreach ( cloudflare_ai_provider_catalog() as $entry ) {
			if ( skyphusion_ai_model_matches( $job, $entry['kind'], ! empty( $entry['vision'] ) ) ) {
				$add( $entry['id'], $entry['name'] );
			}
		}
	}
	if ( null === $models ) {
		$models = skyphusion_ai_account_models_current();
	}
	foreach ( $models as $model ) {
		if ( ! is_array( $model ) || empty( $model['id'] ) ) {
			continue;
		}
		$kind = skyphusion_ai_account_model_kind( isset( $model['task'] ) ? $model['task'] : '' );
		if ( skyphusion_ai_model_matches( $job, $kind[0], $kind[1] || ! empty( $model['vision'] ) ) ) {
			$add( $model['id'], isset( $model['name'] ) ? $model['name'] : $model['id'], skyphusion_ai_account_model_labels( $model ) );
		}
	}
	$choice = skyphusion_ai_model_choice( $job );
	if ( '' !== $choice ) {
		$add( $choice, $choice );
	}
	return $options;
}

/**
 * Move each chosen id to the front, writing last so it stays first.
 *
 * @param array<int, array<string, mixed>> $rows    Catalog-shaped rows.
 * @param array<string, string>            $choices Job to model id.
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_model_promote( $rows, $choices ) {
	$rows = array_values( $rows );
	foreach ( array( 'speech', 'image', 'vision', 'writing' ) as $job ) {
		if ( empty( $choices[ $job ] ) ) {
			continue;
		}
		$index = null;
		foreach ( $rows as $position => $row ) {
			if ( isset( $row['id'] ) && $row['id'] === $choices[ $job ] ) {
				$index = $position;
				break;
			}
		}
		if ( null === $index || 0 === $index ) {
			continue;
		}
		$chosen = $rows[ $index ];
		array_splice( $rows, $index, 1 );
		array_unshift( $rows, $chosen );
	}
	return $rows;
}

/**
 * Catalog rows, then account models that are not already in the catalog.
 *
 * Embeddings stay off this list. The metadata helper would otherwise describe
 * them as speech. Chosen models are moved to the front.
 *
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_model_directory_rows() {
	$rows = function_exists( 'cloudflare_ai_provider_catalog' ) ? cloudflare_ai_provider_catalog() : array();
	foreach ( skyphusion_ai_account_models_current() as $model ) {
		$known = function_exists( 'cloudflare_ai_provider_catalog_entry' ) ? cloudflare_ai_provider_catalog_entry( $model['id'] ) : null;
		if ( null !== $known ) {
			continue;
		}
		$rows[] = skyphusion_ai_account_model_as_entry( $model );
	}
	$choices = array();
	foreach ( skyphusion_ai_model_jobs() as $job ) {
		$choices[ $job ] = skyphusion_ai_model_choice( $job );
	}
	$listed = array();
	foreach ( $rows as $row ) {
		if ( isset( $row['kind'] ) && 'embeddings' === $row['kind'] ) {
			continue;
		}
		$listed[] = $row;
	}
	return skyphusion_ai_model_promote( $listed, $choices );
}

/**
 * Keep the five model choices on the settings option.
 *
 * The settings sanitizer rebuilds the row from its own defaults. This filter
 * puts the choices back after that rebuild. Options.php has already checked
 * the settings nonce.
 *
 * @param mixed  $value    Sanitized settings.
 * @param string $option   Option name.
 * @param mixed  $original Previous stored value.
 * @return mixed
 */
function skyphusion_ai_model_sanitize_option( $value, $option = '', $original = null ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( '' !== $option && 'skyphusion_ai_settings' !== $option ) {
		return $value;
	}
	$posted = null;
	if ( isset( $_POST['skyphusion_ai_settings'] ) && is_array( $_POST['skyphusion_ai_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checks the settings nonce before this filter.
		$posted = $_POST['skyphusion_ai_settings']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash runs next when that function exists.
		if ( function_exists( 'wp_unslash' ) ) {
			$posted = wp_unslash( $posted );
		}
	}
	foreach ( skyphusion_ai_model_jobs() as $job ) {
		$key      = 'model_' . $job;
		$fallback = skyphusion_ai_model_default( $job );
		$previous = $fallback;
		if ( is_array( $original ) && isset( $original[ $key ] ) && function_exists( 'cloudflare_ai_provider_model_id' ) ) {
			$kept = cloudflare_ai_provider_model_id( $original[ $key ] );
			if ( '' !== $kept ) {
				$previous = $kept;
			}
		}
		if ( ! is_array( $posted ) || ! array_key_exists( $key, $posted ) ) {
			$value[ $key ] = $previous;
			continue;
		}
		$typed = trim( (string) $posted[ $key ] );
		if ( '' === $typed ) {
			$value[ $key ] = $fallback;
			continue;
		}
		$id = function_exists( 'cloudflare_ai_provider_model_id' ) ? cloudflare_ai_provider_model_id( $typed ) : '';
		if ( '' === $id || ! skyphusion_ai_model_id_allowed( $id, $job ) ) {
			if ( function_exists( 'skyphusion_ai_reject' ) ) {
				skyphusion_ai_reject( $key, __( 'That model id was not kept. The previous model stays.', 'skyphusion-ai' ) );
			}
			$value[ $key ] = $previous;
			continue;
		}
		$value[ $key ] = $id;
	}
	$value['model_light'] = skyphusion_ai_model_light_stored( $original, $posted );
	return $value;
}

/**
 * Vendor segment of a model id. An id without one is an empty string.
 *
 * @param string $id Model id.
 * @return string
 */
function skyphusion_ai_model_vendor( $id ) {
	if ( 1 === preg_match( '#^@cf/([^/]+)/#', (string) $id, $match ) ) {
		return strtolower( $match[1] );
	}
	return '';
}

/**
 * Publisher name for a model id. Meta stays Meta. Any other segment is titled.
 *
 * @param string $id Model id.
 * @return string
 */
function skyphusion_ai_model_publisher( $id ) {
	$vendor = skyphusion_ai_model_vendor( $id );
	if ( 'meta' === $vendor ) {
		return 'Meta';
	}
	if ( '' === $vendor ) {
		return 'publisher';
	}
	return ucwords( str_replace( array( '-', '_' ), ' ', $vendor ) );
}

/**
 * Rows grouped by vendor segment.
 *
 * @param array<int, array<string, mixed>> $rows Model rows.
 * @return array<string, array<int, array<string, mixed>>>
 */
function skyphusion_ai_model_groups( $rows ) {
	$groups = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			continue;
		}
		$vendor = skyphusion_ai_model_vendor( $row['id'] );
		if ( '' === $vendor ) {
			$vendor = 'other';
		}
		if ( ! isset( $groups[ $vendor ] ) ) {
			$groups[ $vendor ] = array();
		}
		$groups[ $vendor ][] = $row;
	}
	ksort( $groups );
	return $groups;
}

/**
 * One decimal for a neuron figure. Zero and non-numbers stay blank.
 *
 * @param mixed $value Neurons per 1,000 tokens.
 * @return string
 */
function skyphusion_ai_model_neuron_text( $value ) {
	if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
		return '';
	}
	return number_format( (float) $value, 1, '.', '' );
}

/**
 * Short context size. 128000 is 128K.
 *
 * @param int $tokens Context window.
 * @return string
 */
function skyphusion_ai_model_context_text( $tokens ) {
	$tokens = (int) $tokens;
	if ( $tokens >= 1000000 ) {
		$text = number_format( $tokens / 1000000, 1, '.', '' );
		$text = rtrim( rtrim( $text, '0' ), '.' );
		return $text . 'M';
	}
	if ( $tokens >= 1000 ) {
		return (string) (int) round( $tokens / 1000 ) . 'K';
	}
	return (string) $tokens;
}

/**
 * Picker text for one account row. Missing labels are left out.
 *
 * @param array<string, mixed> $row Account row.
 * @return string
 */
function skyphusion_ai_model_label( $row ) {
	if ( ! is_array( $row ) ) {
		return '';
	}
	$name = '';
	if ( isset( $row['name'] ) && '' !== trim( (string) $row['name'] ) ) {
		$name = (string) $row['name'];
	} elseif ( isset( $row['id'] ) ) {
		$name = (string) $row['id'];
	}
	$bits = array();
	$in   = isset( $row['neurons_in'] ) ? skyphusion_ai_model_neuron_text( $row['neurons_in'] ) : '';
	$out  = isset( $row['neurons_out'] ) ? skyphusion_ai_model_neuron_text( $row['neurons_out'] ) : '';
	if ( '' !== $in && '' !== $out && $in === $out ) {
		$bits[] = sprintf(
			/* translators: %s: neurons per 1,000 tokens. */
			__( '%s neurons per 1,000 tokens', 'skyphusion-ai' ),
			$in
		);
	} elseif ( '' !== $in && '' !== $out ) {
		$bits[] = sprintf(
			/* translators: 1: neurons per 1,000 input tokens, 2: neurons per 1,000 output tokens. */
			__( '%1$s neurons per 1,000 input tokens, %2$s per 1,000 output tokens', 'skyphusion-ai' ),
			$in,
			$out
		);
	} elseif ( '' !== $in ) {
		$bits[] = sprintf(
			/* translators: %s: neurons per 1,000 input tokens. */
			__( '%s neurons per 1,000 input tokens', 'skyphusion-ai' ),
			$in
		);
	} elseif ( '' !== $out ) {
		$bits[] = sprintf(
			/* translators: %s: neurons per 1,000 output tokens. */
			__( '%s neurons per 1,000 output tokens', 'skyphusion-ai' ),
			$out
		);
	}
	if ( isset( $row['context'] ) && (int) $row['context'] > 0 ) {
		$bits[] = sprintf(
			/* translators: %s: context window, such as 128K. */
			__( '%s context', 'skyphusion-ai' ),
			skyphusion_ai_model_context_text( (int) $row['context'] )
		);
	}
	if ( ! empty( $row['tools'] ) ) {
		$bits[] = __( 'tools', 'skyphusion-ai' );
	}
	$vision = ! empty( $row['vision'] );
	if ( ! $vision && isset( $row['task'] ) ) {
		$kind   = skyphusion_ai_account_model_kind( (string) $row['task'] );
		$vision = ! empty( $kind[1] );
	}
	if ( $vision ) {
		$bits[] = __( 'vision', 'skyphusion-ai' );
	}
	if ( array() === $bits ) {
		return $name;
	}
	return $name . ' (' . implode( ', ', $bits ) . ')';
}

/**
 * One select, grouped by vendor.
 *
 * @param string                           $name    Field name.
 * @param string                           $id_attr Element id.
 * @param string                           $current Selected model id.
 * @param array<int, array<string, mixed>> $options Picker rows.
 * @param string|null                      $blank   First empty option, or null.
 * @return void
 */
function skyphusion_ai_model_select( $name, $id_attr, $current, $options, $blank ) {
	if ( ! function_exists( 'esc_attr' ) || ! function_exists( 'esc_html' ) ) {
		return;
	}
	echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id_attr ) . '">';
	if ( is_string( $blank ) ) {
		echo '<option value=""';
		if ( '' === (string) $current ) {
			echo ' selected="selected"';
		}
		echo '>' . esc_html( $blank ) . '</option>';
	}
	foreach ( skyphusion_ai_model_groups( $options ) as $vendor => $rows ) {
		echo '<optgroup label="' . esc_attr( (string) $vendor ) . '">';
		foreach ( $rows as $option ) {
			echo '<option value="' . esc_attr( $option['id'] ) . '"';
			if ( isset( $option['id'] ) && $option['id'] === $current ) {
				echo ' selected="selected"';
			}
			echo '>' . esc_html( skyphusion_ai_model_label( $option ) ) . '</option>';
		}
		echo '</optgroup>';
	}
	echo '</select>';
}

/**
 * One picker.
 *
 * @param string $job Job name.
 * @return void
 */
function skyphusion_ai_model_field( $job ) {
	if ( ! function_exists( 'esc_attr' ) || ! function_exists( 'esc_html' ) ) {
		return;
	}
	skyphusion_ai_model_select(
		'skyphusion_ai_settings[model_' . $job . ']',
		'skyphusion_ai_model_' . $job,
		skyphusion_ai_model_choice( $job ),
		skyphusion_ai_model_options( $job ),
		null
	);
}

/**
 * Writing model picker.
 *
 * @return void
 */
function skyphusion_ai_field_model_writing() {
	skyphusion_ai_model_field( 'writing' );
}

/**
 * Light model picker. The first row keeps the writing model.
 *
 * @return void
 */
function skyphusion_ai_field_model_light() {
	if ( ! function_exists( 'esc_attr' ) || ! function_exists( 'esc_html' ) ) {
		return;
	}
	skyphusion_ai_model_select(
		'skyphusion_ai_settings[model_light]',
		'skyphusion_ai_model_light',
		skyphusion_ai_model_light_id(),
		skyphusion_ai_model_options( 'writing' ),
		__( 'Use the writing model', 'skyphusion-ai' )
	);
	echo '<p class="description">' . esc_html__( 'Short tasks use this model. Titles, excerpts, meta descriptions, moderation, and alt wording. Leave it empty and the writing model stays in use. A model the editor already picked stays that model.', 'skyphusion-ai' ) . '</p>';
}

/**
 * Vision model picker.
 *
 * @return void
 */
function skyphusion_ai_field_model_vision() {
	skyphusion_ai_model_field( 'vision' );
}

/**
 * Image model picker.
 *
 * @return void
 */
function skyphusion_ai_field_model_image() {
	skyphusion_ai_model_field( 'image' );
}

/**
 * Speech model picker.
 *
 * @return void
 */
function skyphusion_ai_field_model_speech() {
	skyphusion_ai_model_field( 'speech' );
}

/**
 * Embeddings model picker.
 *
 * @return void
 */
function skyphusion_ai_field_model_embeddings() {
	skyphusion_ai_model_field( 'embeddings' );
}

/**
 * Option that remembers which models need a publisher licence.
 *
 * The upstream message is not stored. It can contain a secret.
 *
 * @return string
 */
function skyphusion_ai_model_licence_option() {
	return 'skyphusion_ai_model_licences';
}

/**
 * True when this error body is a publisher-licence gate.
 *
 * @param mixed $decoded Response JSON, or anything else.
 * @return bool
 */
function skyphusion_ai_model_licence_is_gate( $decoded ) {
	if ( ! is_array( $decoded ) || ! isset( $decoded['errors'] ) || ! is_array( $decoded['errors'] ) ) {
		return false;
	}
	foreach ( $decoded['errors'] as $error ) {
		if ( ! is_array( $error ) ) {
			continue;
		}
		if ( isset( $error['code'] ) && 5016 === (int) $error['code'] ) {
			return true;
		}
		$message = isset( $error['message'] ) ? strtolower( (string) $error['message'] ) : '';
		if ( false !== strpos( $message, 'model agreement' ) ) {
			return true;
		}
		if ( false !== strpos( $message, 'publisher' ) && false !== strpos( $message, 'licen' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Stored model ids that need a publisher licence.
 *
 * @return array<string, int>
 */
function skyphusion_ai_model_licence_stored() {
	if ( ! function_exists( 'get_option' ) ) {
		return array();
	}
	$stored = get_option( skyphusion_ai_model_licence_option(), array() );
	if ( ! is_array( $stored ) ) {
		return array();
	}
	$clean = array();
	foreach ( array_keys( $stored ) as $key ) {
		$id = '';
		if ( function_exists( 'cloudflare_ai_provider_model_id' ) ) {
			$id = cloudflare_ai_provider_model_id( is_string( $key ) ? $key : '' );
		}
		if ( '' === $id ) {
			continue;
		}
		$clean[ $id ] = 1;
	}
	return $clean;
}

/**
 * Remember one model. The message is not written.
 *
 * @param string $model_id Model id.
 * @param string $message  Unused. Callers pass the upstream text. It is dropped.
 * @return void
 */
function skyphusion_ai_model_licence_record( $model_id, $message ) {
	if ( is_string( $message ) && false !== stripos( $message, 'Bearer ' ) ) {
		$message = '';
	}
	if ( ! function_exists( 'cloudflare_ai_provider_model_id' ) || ! function_exists( 'update_option' ) ) {
		return;
	}
	$model_id = cloudflare_ai_provider_model_id( $model_id );
	if ( '' === $model_id ) {
		return;
	}
	$stored              = skyphusion_ai_model_licence_stored();
	$stored[ $model_id ] = 1;
	update_option( skyphusion_ai_model_licence_option(), $stored, false );
}

/**
 * Record a gate seen on a model call. Other responses are ignored.
 *
 * @param string $model_or_url Model id, or a URL that contains one.
 * @param mixed  $decoded      Response JSON.
 * @return void
 */
function skyphusion_ai_model_licence_note( $model_or_url, $decoded ) {
	if ( ! skyphusion_ai_model_licence_is_gate( $decoded ) ) {
		return;
	}
	$id = '';
	if ( function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		$id = cloudflare_ai_provider_model_id( $model_or_url );
	}
	if ( '' === $id && is_string( $model_or_url ) && 1 === preg_match( '#(@cf/[a-z0-9][a-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*)#', $model_or_url, $match ) ) {
		$id = $match[1];
	}
	if ( '' === $id ) {
		return;
	}
	skyphusion_ai_model_licence_record( $id, '' );
}

/**
 * Say which models need a publisher licence. This does not send the acceptance.
 *
 * @return void
 */
function skyphusion_ai_model_licence_notice() {
	if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'esc_html' ) || ! function_exists( 'esc_attr' ) ) {
		return;
	}
	$stored = skyphusion_ai_model_licence_stored();
	if ( array() === $stored ) {
		return;
	}
	foreach ( array_keys( $stored ) as $model_id ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: model id, 2: publisher name, such as Meta. */
				__( '%1$s needs a %2$s publisher licence.', 'skyphusion-ai' ),
				$model_id,
				skyphusion_ai_model_publisher( $model_id )
			)
		) . '</p>';
		if ( ! function_exists( 'admin_url' ) || ! function_exists( 'esc_url' ) ) {
			continue;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="skyphusion_ai_model_licence" />';
		echo '<input type="hidden" name="skyphusion_ai_model" value="' . esc_attr( $model_id ) . '" />';
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( 'skyphusion_ai_model_licence' );
		}
		echo '<button type="submit">' . esc_html__( 'Accept the publisher licence', 'skyphusion-ai' ) . '</button>';
		echo '</form>';
	}
}

/**
 * Send the acceptance after the admin asks. A false ask does not send.
 *
 * @param string $model_id Model id.
 * @param bool   $asked    True only when the admin submitted the button.
 * @return string
 */
function skyphusion_ai_model_licence_accept( $model_id, $asked ) {
	$quiet = __( 'The publisher licence was not sent.', 'skyphusion-ai' );
	if ( true !== $asked ) {
		return $quiet;
	}
	if ( ! function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		return $quiet;
	}
	$model_id = cloudflare_ai_provider_model_id( $model_id );
	if ( '' === $model_id || ! isset( skyphusion_ai_model_licence_stored()[ $model_id ] ) ) {
		return $quiet;
	}
	if ( ! function_exists( 'wp_remote_post' ) || ! function_exists( 'skyphusion_ai_model_credentials' ) ) {
		return $quiet;
	}
	$credentials = skyphusion_ai_model_credentials();
	$account     = $credentials['account_id'];
	$token       = $credentials['token'];
	if ( '' === $account || '' === $token || ! function_exists( 'cloudflare_ai_provider_run_url' ) ) {
		return $quiet;
	}
	$url = cloudflare_ai_provider_run_url( $account, '', $model_id );
	if ( '' === $url ) {
		return $quiet;
	}
	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			),
			'body'    => function_exists( 'wp_json_encode' ) ? wp_json_encode( array( 'prompt' => 'agree' ) ) : '{"prompt":"agree"}',
		)
	);
	$failed   = function_exists( 'is_wp_error' ) && is_wp_error( $response );
	$code     = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
	if ( $failed || $code < 200 || $code >= 300 ) {
		return __( 'The publisher licence was not accepted.', 'skyphusion-ai' );
	}
	return __( 'Publisher licence sent.', 'skyphusion-ai' );
}

/**
 * Admin button. The page view does not call this.
 *
 * @return void
 */
function skyphusion_ai_model_licence_handle() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( function_exists( 'check_admin_referer' ) ) {
		check_admin_referer( 'skyphusion_ai_model_licence' );
	}
	$typed = '';
	if ( isset( $_POST['skyphusion_ai_model'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer ran above.
		$typed = wp_unslash( $_POST['skyphusion_ai_model'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the model id checker is the sanitizer.
	}
	$message = skyphusion_ai_model_licence_accept( is_string( $typed ) ? $typed : '', true );
	if ( function_exists( 'set_transient' ) && function_exists( 'get_current_user_id' ) ) {
		set_transient(
			'skyphusion_ai_check_' . get_current_user_id(),
			array(
				'ok'      => __( 'Publisher licence sent.', 'skyphusion-ai' ) === $message,
				'message' => $message,
			),
			60
		);
	}
	if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'admin_url' ) ) {
		wp_safe_redirect( admin_url( 'options-general.php?page=cloudflare-ai-search' ) );
		exit;
	}
}

/**
 * What the model section tells the admin.
 *
 * @return void
 */
function skyphusion_ai_model_section() {
	if ( ! function_exists( 'esc_html_e' ) ) {
		return;
	}
	echo '<p>';
	esc_html_e( 'The account list is kept for 12 hours. A failed read keeps the built-in models. An empty choice uses the built-in model for that job.', 'skyphusion-ai' );
	echo '</p>';
	skyphusion_ai_model_licence_notice();
}

/**
 * Register the pickers after the Workers AI section.
 *
 * @return void
 */
function skyphusion_ai_model_register_fields() {
	if ( ! function_exists( 'add_settings_section' ) || ! function_exists( 'add_settings_field' ) ) {
		return;
	}
	add_settings_section(
		'models',
		__( 'Models', 'skyphusion-ai' ),
		'skyphusion_ai_model_section',
		'cloudflare-ai-search'
	);
	add_settings_field( 'model_writing', __( 'Writing model', 'skyphusion-ai' ), 'skyphusion_ai_field_model_writing', 'cloudflare-ai-search', 'models' );
	add_settings_field( 'model_light', __( 'Light model', 'skyphusion-ai' ), 'skyphusion_ai_field_model_light', 'cloudflare-ai-search', 'models' );
	add_settings_field( 'model_vision', __( 'Vision model', 'skyphusion-ai' ), 'skyphusion_ai_field_model_vision', 'cloudflare-ai-search', 'models' );
	add_settings_field( 'model_image', __( 'Image model', 'skyphusion-ai' ), 'skyphusion_ai_field_model_image', 'cloudflare-ai-search', 'models' );
	add_settings_field( 'model_speech', __( 'Speech model', 'skyphusion-ai' ), 'skyphusion_ai_field_model_speech', 'cloudflare-ai-search', 'models' );
	add_settings_field( 'model_embeddings', __( 'Embeddings model', 'skyphusion-ai' ), 'skyphusion_ai_field_model_embeddings', 'cloudflare-ai-search', 'models' );
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'admin_init', 'skyphusion_ai_model_register_fields', 20 );
	add_action( 'admin_post_skyphusion_ai_model_licence', 'skyphusion_ai_model_licence_handle' );
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'sanitize_option_skyphusion_ai_settings', 'skyphusion_ai_model_sanitize_option', 20, 3 );
}
