<?php
/**
 * Site Health for the Cloudflare connection and the Vectorize store.
 *
 * A failed test is a Site Health result. It does not send mail. The
 * WordPress AI plugin is not installed from here. When that plugin's
 * connector approval guard is actually hooked, an unapproved plugin is
 * reported before any token check.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connector id this plugin registers. The approval matrix uses the same id.
 *
 * @return string
 */
function skyphusion_ai_health_connector_id() {
	return 'cloudflare';
}

/**
 * True when the saved caller is approved for this plugin's connector.
 *
 * @param mixed  $approvals Approval matrix, caller basename to connector id.
 * @param string $basename  This plugin's basename.
 * @return bool
 */
function skyphusion_ai_health_caller_approved( $approvals, $basename ) {
	if ( ! is_array( $approvals ) || '' === $basename ) {
		return false;
	}
	if ( ! isset( $approvals[ $basename ] ) || ! is_array( $approvals[ $basename ] ) ) {
		return false;
	}
	$id = skyphusion_ai_health_connector_id();
	return ! empty( $approvals[ $basename ][ $id ] );
}

/**
 * True when the WordPress AI plugin is enforcing connector approval.
 *
 * The class is not loaded from here. A plugin that is installed but not
 * enforcing does not block. This does not install that plugin.
 *
 * @return bool
 */
function skyphusion_ai_health_guard_hooked() {
	if ( ! class_exists( '\WordPress\AI\Connector_Approval\Http_Guard', false ) ) {
		return false;
	}
	global $wp_filter;
	if ( ! isset( $wp_filter['pre_http_request'] ) ) {
		return false;
	}
	$hook   = $wp_filter['pre_http_request'];
	$groups = array();
	if ( is_object( $hook ) && isset( $hook->callbacks ) && is_array( $hook->callbacks ) ) {
		$groups = $hook->callbacks;
	} elseif ( is_array( $hook ) ) {
		$groups = $hook;
	}
	foreach ( $groups as $priority ) {
		if ( ! is_array( $priority ) ) {
			continue;
		}
		foreach ( $priority as $callback ) {
			if ( ! is_array( $callback ) || ! isset( $callback['function'] ) || ! is_array( $callback['function'] ) ) {
				continue;
			}
			$object = $callback['function'][0];
			if ( is_object( $object ) && 'WordPress\AI\Connector_Approval\Http_Guard' === get_class( $object ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * True when connector approval is blocking this plugin.
 *
 * A test may set skyphusion_ai_health_connector_blocked. The site does not.
 *
 * @return bool
 */
function skyphusion_ai_health_connector_blocked() {
	if ( array_key_exists( 'skyphusion_ai_health_connector_blocked', $GLOBALS ) ) {
		return (bool) $GLOBALS['skyphusion_ai_health_connector_blocked'];
	}
	if ( ! skyphusion_ai_health_guard_hooked() ) {
		return false;
	}
	$basename = 'skyphusion-ai/skyphusion-ai.php';
	if ( defined( 'SKYPHUSION_AI_FILE' ) && function_exists( 'plugin_basename' ) ) {
		$basename = (string) plugin_basename( SKYPHUSION_AI_FILE );
	}
	$approvals = array();
	if ( function_exists( 'get_option' ) ) {
		$stored = get_option( 'wpai_connector_approvals', array() );
		if ( is_array( $stored ) ) {
			$approvals = $stored;
		}
	}
	return ! skyphusion_ai_health_caller_approved( $approvals, $basename );
}

/**
 * Admin URL, escaped when WordPress is present.
 *
 * @param string $path Path under wp-admin.
 * @return string
 */
function skyphusion_ai_health_admin_href( $path ) {
	if ( function_exists( 'admin_url' ) ) {
		$path = admin_url( $path );
	}
	if ( function_exists( 'esc_url' ) ) {
		return esc_url( $path );
	}
	return $path;
}

/**
 * Link to this plugin's settings screen.
 *
 * @return string
 */
function skyphusion_ai_health_settings_link() {
	$href = skyphusion_ai_health_admin_href( 'options-general.php?page=cloudflare-ai-search' );
	return '<p><a href="' . $href . '">' . esc_html__( 'Skyphusion AI settings', 'skyphusion-ai' ) . '</a></p>';
}

/**
 * Link to Tools, then Connector Approvals.
 *
 * The page slug is the WordPress AI plugin's Tools submenu.
 *
 * @return string
 */
function skyphusion_ai_health_approval_link() {
	$href = skyphusion_ai_health_admin_href( 'tools.php?page=ai-connector-approval' );
	return '<p><a href="' . $href . '">' . esc_html__( 'Tools, then Connector Approvals', 'skyphusion-ai' ) . '</a></p>';
}

/**
 * Shared Site Health fields. Status starts as good.
 *
 * @param string $test_id Test id.
 * @param string $label   Result label.
 * @return array<string, mixed>
 */
function skyphusion_ai_health_base( $test_id, $label ) {
	return array(
		'label'       => $label,
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'AI', 'skyphusion-ai' ),
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => skyphusion_ai_health_settings_link(),
		'test'        => $test_id,
	);
}

/**
 * Account id and token, or two empty strings.
 *
 * @return array{account_id: string, token: string}
 */
function skyphusion_ai_health_credentials() {
	if ( function_exists( 'skyphusion_ai_model_credentials' ) ) {
		return skyphusion_ai_model_credentials();
	}
	return array(
		'account_id' => '',
		'token'      => '',
	);
}

/**
 * Saved gateway id from the option, including while a bypass is active.
 *
 * The settings reader clears the id during a bypass. The option still holds it.
 *
 * @return string
 */
function skyphusion_ai_health_gateway_saved() {
	if ( ! function_exists( 'get_option' ) ) {
		return '';
	}
	$stored = get_option( 'skyphusion_ai_settings', array() );
	if ( ! is_array( $stored ) || ! isset( $stored['gateway_id'] ) ) {
		return '';
	}
	$value = trim( (string) $stored['gateway_id'] );
	if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $value ) ) {
		return '';
	}
	return $value;
}

/**
 * True when a saved gateway is sending calls to the direct API.
 *
 * @return bool
 */
function skyphusion_ai_health_gateway_bypassed() {
	if ( '' === skyphusion_ai_health_gateway_saved() ) {
		return false;
	}
	if ( ! function_exists( 'skyphusion_ai_gateway_fallback_active' ) ) {
		return false;
	}
	return skyphusion_ai_gateway_fallback_active();
}

/**
 * True when the cached model list says Cloudflare accepted the account.
 *
 * The list costs no neurons. An unapproved connector never reaches this.
 *
 * @return bool
 */
function skyphusion_ai_health_catalog_ok() {
	if ( isset( $GLOBALS['skyphusion_ai_models_runtime'] ) ) {
		unset( $GLOBALS['skyphusion_ai_models_runtime'] );
	}
	if ( ! function_exists( 'skyphusion_ai_account_models_current' ) ) {
		return false;
	}
	$models = skyphusion_ai_account_models_current();
	return is_array( $models ) && array() !== $models;
}

/**
 * Paragraph for a translated sentence.
 *
 * @param string $sentence Already translated text.
 * @return string
 */
function skyphusion_ai_health_paragraph( $sentence ) {
	$text = function_exists( 'esc_html' ) ? esc_html( $sentence ) : $sentence;
	return '<p>' . $text . '</p>';
}

/**
 * Connection result for Tools, then Site Health.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_health_connection() {
	$good = __( 'Cloudflare Workers AI is connected and inside today\'s neuron budget', 'skyphusion-ai' );
	if ( ! function_exists( 'skyphusion_ai_neuron_cap_saved' ) || ! skyphusion_ai_neuron_cap_saved() ) {
		$good = __( 'Cloudflare Workers AI is connected and inside the free daily allowance', 'skyphusion-ai' );
	}
	$result = skyphusion_ai_health_base( 'skyphusion_ai_connection', $good );
	$creds  = skyphusion_ai_health_credentials();
	if ( '' === $creds['account_id'] || '' === $creds['token'] ) {
		$result['label']       = __( 'Cloudflare Workers AI is not connected', 'skyphusion-ai' );
		$result['status']      = 'recommended';
		$result['description'] = skyphusion_ai_health_paragraph(
			__( 'Add the Cloudflare account ID and API token on the settings screen.', 'skyphusion-ai' )
		);
		return $result;
	}
	if ( skyphusion_ai_health_connector_blocked() ) {
		$result['label']       = __( 'Cloudflare Workers AI needs connector approval', 'skyphusion-ai' );
		$result['status']      = 'critical';
		$result['description'] = skyphusion_ai_health_paragraph(
			__( 'The WordPress AI plugin blocks this plugin. Open Tools, then Connector Approvals, and allow Cloudflare. The saved account is not the cause.', 'skyphusion-ai' )
		);
		$result['actions']     = skyphusion_ai_health_approval_link() . $result['actions'];
		return $result;
	}
	if ( ! skyphusion_ai_health_catalog_ok() ) {
		$result['label']       = __( 'Cloudflare did not accept the saved account or token', 'skyphusion-ai' );
		$result['status']      = 'critical';
		$result['description'] = skyphusion_ai_health_paragraph(
			__( 'The model list failed, so Workers AI calls will fail too. The token may be revoked, the account ID may be wrong, or Cloudflare may be unreachable from this server.', 'skyphusion-ai' )
		);
		return $result;
	}
	if ( skyphusion_ai_health_gateway_bypassed() ) {
		$result['label']  = __( 'AI Gateway is being bypassed', 'skyphusion-ai' );
		$result['status'] = 'recommended';
		$notice           = '';
		if ( function_exists( 'skyphusion_ai_gateway_notice_text' ) ) {
			$notice = skyphusion_ai_gateway_notice_text();
		}
		if ( '' === $notice ) {
			$notice = __( 'The gateway refused a call. Workers AI is using the direct API for a short time.', 'skyphusion-ai' );
		}
		$result['description'] = skyphusion_ai_health_paragraph( $notice );
		return $result;
	}
	$used = 0;
	$cap  = 10000;
	if ( function_exists( 'skyphusion_ai_neuron_state' ) ) {
		$state = skyphusion_ai_neuron_state();
		$used  = isset( $state['neurons'] ) ? (int) $state['neurons'] : 0;
	}
	if ( function_exists( 'skyphusion_ai_neuron_cap' ) ) {
		$cap = (int) skyphusion_ai_neuron_cap();
	}
	$saved = function_exists( 'skyphusion_ai_neuron_cap_saved' ) && skyphusion_ai_neuron_cap_saved();
	$code  = '';
	if ( function_exists( 'skyphusion_ai_neuron_notice_code' ) ) {
		$code = skyphusion_ai_neuron_notice_code();
	}
	if ( '100' === $code ) {
		if ( $saved ) {
			$result['label'] = __( 'Today\'s neuron budget is spent', 'skyphusion-ai' );
		} else {
			$result['label'] = __( 'The free daily allowance is spent', 'skyphusion-ai' );
		}
		$result['status']      = 'recommended';
		$result['description'] = skyphusion_ai_health_paragraph(
			sprintf(
				/* translators: 1: neurons used today, 2: daily cap. */
				__( 'Workers AI pauses until 00:00 UTC. Today\'s use is %1$d of %2$d neurons. This plugin adds no fee. Past the free allowance, the site pays Cloudflare.', 'skyphusion-ai' ),
				$used,
				$cap
			)
		);
		return $result;
	}
	if ( '80' === $code ) {
		if ( $saved ) {
			$result['label'] = __( 'Workers AI is near today\'s neuron budget', 'skyphusion-ai' );
		} else {
			$result['label'] = __( 'Workers AI is near the free daily allowance', 'skyphusion-ai' );
		}
		$result['status']      = 'recommended';
		$result['description'] = skyphusion_ai_health_paragraph(
			sprintf(
				/* translators: 1: neurons used today, 2: daily cap. */
				__( 'Today\'s use is %1$d of %2$d neurons. Calls pause at the cap until 00:00 UTC. This plugin adds no fee.', 'skyphusion-ai' ),
				$used,
				$cap
			)
		);
		return $result;
	}
	$result['description'] = skyphusion_ai_health_paragraph(
		sprintf(
			/* translators: 1: neurons used today, 2: daily cap. */
			__( 'Today\'s use is %1$d of %2$d neurons. This plugin adds no fee. Past the free allowance, the site pays Cloudflare.', 'skyphusion-ai' ),
			$used,
			$cap
		)
	);
	return $result;
}

/**
 * True when the Vectorize index has been created.
 *
 * @return bool
 */
function skyphusion_ai_health_embeddings_on() {
	if ( ! function_exists( 'skyphusion_ai_embed_config' ) ) {
		return false;
	}
	$config = skyphusion_ai_embed_config();
	return ! empty( $config['created'] );
}

/**
 * Vector length for a Workers AI embedding preset.
 *
 * Cloudflare Vectorize presets use these lengths.
 * https://developers.cloudflare.com/workers-ai/models/bge-large-en-v1.5/
 *
 * @param string $model Model id.
 * @return int Zero when this plugin has no preset for the model.
 */
function skyphusion_ai_health_embed_dimensions( $model ) {
	$map = array(
		'@cf/baai/bge-small-en-v1.5' => 384,
		'@cf/baai/bge-base-en-v1.5'  => 768,
		'@cf/baai/bge-large-en-v1.5' => 1024,
	);
	if ( isset( $map[ $model ] ) ) {
		return (int) $map[ $model ];
	}
	return 0;
}

/**
 * Read one Vectorize index. The result does not include the token.
 *
 * @param string $account Account id.
 * @param string $index   Index name.
 * @param string $token   API token.
 * @return array{state: string, dimensions: int}
 */
function skyphusion_ai_health_store_fetch( $account, $index, $token ) {
	$empty = array(
		'state'      => 'missing',
		'dimensions' => 0,
	);
	if ( '' === $account || '' === $index || '' === $token ) {
		return $empty;
	}
	if ( ! function_exists( 'skyphusion_ai_vectorize_url' ) || ! function_exists( 'wp_remote_request' ) ) {
		$empty['state'] = 'unreachable';
		return $empty;
	}
	$url      = skyphusion_ai_vectorize_url( $account, $index, '' );
	$response = wp_remote_request(
		$url,
		array(
			'method'  => 'GET',
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
			),
		)
	);
	if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
		$empty['state'] = 'unreachable';
		return $empty;
	}
	$code = 0;
	if ( function_exists( 'wp_remote_retrieve_response_code' ) ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
	}
	$body = '';
	if ( function_exists( 'wp_remote_retrieve_body' ) ) {
		$body = (string) wp_remote_retrieve_body( $response );
	}
	if ( 404 === $code ) {
		return $empty;
	}
	$decoded = json_decode( $body, true );
	if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
		$empty['state'] = 'unreachable';
		return $empty;
	}
	$dims = 0;
	if ( isset( $decoded['result']['config']['dimensions'] ) ) {
		$dims = (int) $decoded['result']['config']['dimensions'];
	}
	return array(
		'state'      => 'ready',
		'dimensions' => $dims,
	);
}

/**
 * Index status, cached for one minute.
 *
 * A Site Health page should not call Vectorize on every refresh.
 *
 * @param array $config      Saved embedding choice.
 * @param array $credentials Account id and token.
 * @return array{state: string, dimensions: int}
 */
function skyphusion_ai_health_store_status( $config, $credentials ) {
	$index   = isset( $config['index'] ) ? (string) $config['index'] : '';
	$account = isset( $credentials['account_id'] ) ? (string) $credentials['account_id'] : '';
	$token   = isset( $credentials['token'] ) ? (string) $credentials['token'] : '';
	$stamp   = $account . ':' . $index;
	$cached  = false;
	if ( function_exists( 'get_transient' ) ) {
		$cached = get_transient( 'skyphusion_ai_embed_store_status' );
	}
	if ( is_array( $cached ) && isset( $cached['stamp'], $cached['result'] ) && $cached['stamp'] === $stamp && is_array( $cached['result'] ) ) {
		return $cached['result'];
	}
	$result = skyphusion_ai_health_store_fetch( $account, $index, $token );
	if ( function_exists( 'set_transient' ) ) {
		set_transient(
			'skyphusion_ai_embed_store_status',
			array(
				'stamp'  => $stamp,
				'result' => $result,
			),
			60
		);
	}
	return $result;
}

/**
 * Embeddings result. Registered only while the index exists.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_health_embeddings() {
	$result = skyphusion_ai_health_base(
		'skyphusion_ai_embeddings',
		__( 'The Vectorize index matches the embeddings model', 'skyphusion-ai' )
	);
	if ( ! skyphusion_ai_health_embeddings_on() ) {
		$result['label']       = __( 'Embeddings are off', 'skyphusion-ai' );
		$result['description'] = skyphusion_ai_health_paragraph(
			__( 'No Vectorize index has been created.', 'skyphusion-ai' )
		);
		return $result;
	}
	if ( skyphusion_ai_health_connector_blocked() ) {
		$result['label']       = __( 'Embeddings need connector approval', 'skyphusion-ai' );
		$result['status']      = 'critical';
		$result['description'] = skyphusion_ai_health_paragraph(
			__( 'The WordPress AI plugin blocks this plugin. Open Tools, then Connector Approvals, and allow Cloudflare. The saved account is not the cause.', 'skyphusion-ai' )
		);
		$result['actions']     = skyphusion_ai_health_approval_link() . $result['actions'];
		return $result;
	}
	$config   = function_exists( 'skyphusion_ai_embed_config' ) ? skyphusion_ai_embed_config() : array();
	$selected = '';
	if ( function_exists( 'cloudflare_ai_provider_embeddings_model' ) ) {
		$selected = (string) cloudflare_ai_provider_embeddings_model();
	}
	$built = isset( $config['model'] ) ? (string) $config['model'] : '';
	if ( '' !== $built && '' !== $selected && $built !== $selected ) {
		$result['label']       = __( 'The Vectorize index does not match the embeddings model', 'skyphusion-ai' );
		$result['status']      = 'critical';
		$result['description'] = skyphusion_ai_health_paragraph(
			sprintf(
				/* translators: 1: model id stored on the index, 2: model id selected now. */
				__( 'The index was built for %1$s. The selected embeddings model is %2$s. Rebuild the index before new posts are stored.', 'skyphusion-ai' ),
				$built,
				$selected
			)
		);
		return $result;
	}
	$credentials = skyphusion_ai_health_credentials();
	if ( '' === $credentials['account_id'] || '' === $credentials['token'] ) {
		$result['label']       = __( 'The Vectorize index cannot be checked yet', 'skyphusion-ai' );
		$result['status']      = 'recommended';
		$result['description'] = skyphusion_ai_health_paragraph(
			__( 'Add the Cloudflare account ID and API token on the settings screen.', 'skyphusion-ai' )
		);
		return $result;
	}
	$status = skyphusion_ai_health_store_status( $config, $credentials );
	$state  = isset( $status['state'] ) ? (string) $status['state'] : 'unreachable';
	if ( 'missing' === $state ) {
		$result['label']       = __( 'The Vectorize index is not in the Cloudflare account', 'skyphusion-ai' );
		$result['status']      = 'critical';
		$result['description'] = skyphusion_ai_health_paragraph(
			__( 'Create the index from the settings screen. Embeddings stay on Cloudflare Vectorize.', 'skyphusion-ai' )
		);
		return $result;
	}
	if ( 'ready' !== $state ) {
		$result['label']       = __( 'The Vectorize index cannot be reached', 'skyphusion-ai' );
		$result['status']      = 'recommended';
		$result['description'] = skyphusion_ai_health_paragraph(
			__( 'Cloudflare did not return the index. The settings screen can run the sync again.', 'skyphusion-ai' )
		);
		return $result;
	}
	$dims     = isset( $status['dimensions'] ) ? (int) $status['dimensions'] : 0;
	$expected = skyphusion_ai_health_embed_dimensions( '' !== $selected ? $selected : $built );
	if ( $expected > 0 && $dims > 0 && $dims !== $expected ) {
		$result['label']       = __( 'The Vectorize index length does not match the embeddings model', 'skyphusion-ai' );
		$result['status']      = 'critical';
		$result['description'] = skyphusion_ai_health_paragraph(
			sprintf(
				/* translators: 1: numbers stored in each vector, 2: numbers the selected model writes. */
				__( 'The index holds vectors of %1$d numbers. The selected model writes %2$d. Rebuild the index.', 'skyphusion-ai' ),
				$dims,
				$expected
			)
		);
		return $result;
	}
	$index = isset( $config['index'] ) ? (string) $config['index'] : '';
	if ( function_exists( 'skyphusion_ai_embed_limit_blocked' ) && skyphusion_ai_embed_limit_blocked( $config ) ) {
		$result['label']       = __( 'Today\'s embeddings limit is spent', 'skyphusion-ai' );
		$result['status']      = 'recommended';
		$result['description'] = skyphusion_ai_health_paragraph(
			sprintf(
				/* translators: %s: Vectorize index name. */
				__( 'Index %s is on Cloudflare Vectorize. This job pauses until 00:00 UTC. Other tools keep running. This plugin adds no fee.', 'skyphusion-ai' ),
				$index
			)
		);
		return $result;
	}
	$result['description'] = skyphusion_ai_health_paragraph(
		sprintf(
			/* translators: 1: Vectorize index name, 2: vector length. */
			__( 'Index %1$s is on Cloudflare Vectorize. Each vector has %2$d numbers. This plugin adds no fee.', 'skyphusion-ai' ),
			$index,
			$dims
		)
	);
	return $result;
}

/**
 * Register the connection test, and the store test when the index exists.
 *
 * @param mixed $tests Site Health tests.
 * @return array<string, mixed>
 */
function skyphusion_ai_health_tests( $tests ) {
	if ( ! is_array( $tests ) ) {
		$tests = array();
	}
	if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
		$tests['direct'] = array();
	}
	$tests['direct']['skyphusion_ai_connection'] = array(
		'label' => __( 'Skyphusion AI connection', 'skyphusion-ai' ),
		'test'  => 'skyphusion_ai_health_connection',
	);
	if ( skyphusion_ai_health_embeddings_on() ) {
		$tests['direct']['skyphusion_ai_embeddings'] = array(
			'label' => __( 'Skyphusion AI embeddings', 'skyphusion-ai' ),
			'test'  => 'skyphusion_ai_health_embeddings',
		);
	}
	return $tests;
}

/**
 * Site Health debug fields. No remote call, and no token.
 *
 * @param mixed $info Debug sections.
 * @return array<string, mixed>
 */
function skyphusion_ai_health_debug( $info ) {
	if ( ! is_array( $info ) ) {
		$info = array();
	}
	$creds     = skyphusion_ai_health_credentials();
	$connected = ( '' !== $creds['account_id'] && '' !== $creds['token'] );
	$blocked   = skyphusion_ai_health_connector_blocked();
	$used      = 0;
	$cap       = function_exists( 'skyphusion_ai_neuron_cap' ) ? (int) skyphusion_ai_neuron_cap() : 10000;
	if ( function_exists( 'skyphusion_ai_neuron_state' ) ) {
		$state = skyphusion_ai_neuron_state();
		$used  = isset( $state['neurons'] ) ? (int) $state['neurons'] : 0;
	}
	$allowance = __( 'free daily allowance', 'skyphusion-ai' );
	if ( function_exists( 'skyphusion_ai_neuron_cap_saved' ) && skyphusion_ai_neuron_cap_saved() ) {
		$allowance = __( 'saved neuron budget', 'skyphusion-ai' );
	}
	$embed = __( 'Off', 'skyphusion-ai' );
	if ( skyphusion_ai_health_embeddings_on() && function_exists( 'skyphusion_ai_embed_config' ) ) {
		$config = skyphusion_ai_embed_config();
		$embed  = sprintf(
			/* translators: %s: Vectorize index name. */
			__( 'On. Vectorize index %s.', 'skyphusion-ai' ),
			isset( $config['index'] ) ? (string) $config['index'] : ''
		);
	}
	$gateway = skyphusion_ai_health_gateway_saved();
	if ( '' === $gateway ) {
		$gateway = __( 'not used', 'skyphusion-ai' );
	} elseif ( skyphusion_ai_health_gateway_bypassed() ) {
		$gateway = sprintf(
			/* translators: %s: AI Gateway id. */
			__( '%s, bypassed', 'skyphusion-ai' ),
			$gateway
		);
	}
	$info['skyphusion-ai'] = array(
		'label'  => __( 'Skyphusion AI for Cloudflare', 'skyphusion-ai' ),
		'fields' => array(
			'connected' => array(
				'label' => __( 'Connected', 'skyphusion-ai' ),
				'value' => $connected ? __( 'Yes', 'skyphusion-ai' ) : __( 'No', 'skyphusion-ai' ),
			),
			'approval'  => array(
				'label' => __( 'Connector approval', 'skyphusion-ai' ),
				'value' => $blocked ? __( 'Blocking', 'skyphusion-ai' ) : __( 'Not blocking', 'skyphusion-ai' ),
			),
			'neurons'   => array(
				'label' => __( 'Neurons today', 'skyphusion-ai' ),
				'value' => sprintf(
					/* translators: 1: neurons used today, 2: daily cap, 3: free allowance or saved budget. */
					__( '%1$d of %2$d, %3$s', 'skyphusion-ai' ),
					$used,
					$cap,
					$allowance
				),
			),
			'gateway'   => array(
				'label' => __( 'AI Gateway', 'skyphusion-ai' ),
				'value' => $gateway,
			),
			'embed'     => array(
				'label' => __( 'Embeddings', 'skyphusion-ai' ),
				'value' => $embed,
			),
		),
	);
	return $info;
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'site_status_tests', 'skyphusion_ai_health_tests' );
	add_filter( 'debug_information', 'skyphusion_ai_health_debug' );
}
