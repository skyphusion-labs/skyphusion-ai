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
		if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! function_exists( 'cloudflare_ai_provider_model_id' ) ) {
			continue;
		}
		$id = cloudflare_ai_provider_model_id( $row['id'] );
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
		foreach ( skyphusion_ai_account_model_prices( $row ) as $price_key => $price_value ) {
			$clean[ $price_key ] = $price_value;
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
	if ( isset( $row['properties'] ) && is_array( $row['properties'] ) ) {
		foreach ( $row['properties'] as $property ) {
			if ( ! is_array( $property ) || ! isset( $property['value'] ) || ! is_numeric( $property['value'] ) ) {
				continue;
			}
			$name = isset( $property['property_id'] ) ? strtolower( (string) $property['property_id'] ) : '';
			if ( in_array( $name, array( 'price_in', 'price_out', 'price' ), true ) ) {
				$out[ $name ] = (float) $property['value'];
			}
		}
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
		'tools'  => false,
		'vision' => $kind[1],
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
		foreach ( skyphusion_ai_account_model_prices( $model ) as $price_key => $price_value ) {
			$clean_row[ $price_key ] = $price_value;
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
	$add     = function ( $id, $name ) use ( &$options, &$seen ) {
		if ( isset( $seen[ $id ] ) ) {
			return;
		}
		$seen[ $id ] = true;
		$options[]   = array(
			'id'   => $id,
			'name' => $name,
		);
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
		if ( skyphusion_ai_model_matches( $job, $kind[0], $kind[1] ) ) {
			$add( $model['id'], isset( $model['name'] ) ? $model['name'] : $model['id'] );
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
	return $value;
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
	$current = skyphusion_ai_model_choice( $job );
	echo '<select name="skyphusion_ai_settings[model_' . esc_attr( $job ) . ']" id="skyphusion_ai_model_' . esc_attr( $job ) . '">';
	foreach ( skyphusion_ai_model_options( $job ) as $option ) {
		echo '<option value="' . esc_attr( $option['id'] ) . '"';
		if ( $option['id'] === $current ) {
			echo ' selected="selected"';
		}
		echo '>' . esc_html( $option['name'] ) . '</option>';
	}
	echo '</select>';
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
	add_settings_field( 'model_vision', __( 'Vision model', 'skyphusion-ai' ), 'skyphusion_ai_field_model_vision', 'cloudflare-ai-search', 'models' );
	add_settings_field( 'model_image', __( 'Image model', 'skyphusion-ai' ), 'skyphusion_ai_field_model_image', 'cloudflare-ai-search', 'models' );
	add_settings_field( 'model_speech', __( 'Speech model', 'skyphusion-ai' ), 'skyphusion_ai_field_model_speech', 'cloudflare-ai-search', 'models' );
	add_settings_field( 'model_embeddings', __( 'Embeddings model', 'skyphusion-ai' ), 'skyphusion_ai_field_model_embeddings', 'cloudflare-ai-search', 'models' );
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'admin_init', 'skyphusion_ai_model_register_fields', 20 );
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'sanitize_option_skyphusion_ai_settings', 'skyphusion_ai_model_sanitize_option', 20, 3 );
}
