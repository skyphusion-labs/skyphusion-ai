<?php
/**
 * A log of Workers AI calls.
 *
 * A row keeps the feature, the model, the neurons, the time, and
 * whether the call worked. It does not keep a prompt, a page body,
 * or an IP address.
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
function skyphusion_ai_activity_option_name() {
	return 'skyphusion_ai_activity';
}

/**
 * Most rows kept.
 *
 * A test may set skyphusion_ai_activity_cap. The site does not.
 *
 * @return int
 */
function skyphusion_ai_activity_cap() {
	if ( isset( $GLOBALS['skyphusion_ai_activity_cap'] ) ) {
		$cap = (int) $GLOBALS['skyphusion_ai_activity_cap'];
		if ( $cap > 0 ) {
			return $cap;
		}
	}
	return 200;
}

/**
 * Feature slug, or an empty string.
 *
 * @param mixed $value Raw feature.
 * @return string
 */
function skyphusion_ai_activity_feature( $value ) {
	$value = strtolower( trim( (string) $value ) );
	if ( 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/', $value ) ) {
		return '';
	}
	return $value;
}

/**
 * Model id, or an empty string.
 *
 * @param mixed $value Raw model.
 * @return string
 */
function skyphusion_ai_activity_model( $value ) {
	if ( function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		return cloudflare_ai_provider_model_id( $value );
	}
	return '';
}

/**
 * Stored rows, oldest first.
 *
 * @return array<int, array{feature: string, model: string, neurons: int, time: int, ok: int}>
 */
function skyphusion_ai_activity_stored() {
	$stored = array();
	if ( function_exists( 'get_option' ) ) {
		$stored = get_option( skyphusion_ai_activity_option_name(), array() );
	}
	if ( ! is_array( $stored ) ) {
		return array();
	}
	$rows = array();
	foreach ( $stored as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$rows[] = array(
			'feature' => skyphusion_ai_activity_feature( isset( $row['feature'] ) ? $row['feature'] : '' ),
			'model'   => skyphusion_ai_activity_model( isset( $row['model'] ) ? $row['model'] : '' ),
			'neurons' => isset( $row['neurons'] ) ? max( 0, (int) $row['neurons'] ) : 0,
			'time'    => isset( $row['time'] ) ? max( 0, (int) $row['time'] ) : 0,
			'ok'      => empty( $row['ok'] ) ? 0 : 1,
		);
	}
	return $rows;
}

/**
 * Append one row and drop the oldest rows past the cap.
 *
 * @param string $feature Feature slug.
 * @param string $model   Model id.
 * @param int    $neurons Neuron count.
 * @param bool   $ok      True when the call worked.
 * @param int    $time    Unix time. Zero means now.
 * @return void
 */
function skyphusion_ai_activity_add( $feature, $model, $neurons, $ok, $time = 0 ) {
	$rows   = skyphusion_ai_activity_stored();
	$rows[] = array(
		'feature' => skyphusion_ai_activity_feature( $feature ),
		'model'   => skyphusion_ai_activity_model( $model ),
		'neurons' => max( 0, (int) $neurons ),
		'time'    => $time > 0 ? (int) $time : time(),
		'ok'      => $ok ? 1 : 0,
	);
	$cap    = skyphusion_ai_activity_cap();
	if ( count( $rows ) > $cap ) {
		$rows = array_slice( $rows, count( $rows ) - $cap );
	}
	if ( function_exists( 'update_option' ) ) {
		update_option( skyphusion_ai_activity_option_name(), array_values( $rows ), false );
	}
}

/**
 * Rows for the screen, newest first.
 *
 * @return array<int, array{feature: string, model: string, neurons: int, time: int, ok: int}>
 */
function skyphusion_ai_activity_rows() {
	return array_reverse( skyphusion_ai_activity_stored() );
}

/**
 * One screen row. The export uses this same shape.
 *
 * @param array{feature: string, model: string, neurons: int, time: int, ok: int} $row Stored row.
 * @return array{feature: string, model: string, neurons: string, time: string, result: string}
 */
function skyphusion_ai_activity_screen_row( $row ) {
	return array(
		'feature' => isset( $row['feature'] ) ? (string) $row['feature'] : '',
		'model'   => isset( $row['model'] ) ? (string) $row['model'] : '',
		'neurons' => isset( $row['neurons'] ) ? (string) (int) $row['neurons'] : '0',
		'time'    => gmdate( 'Y-m-d H:i', isset( $row['time'] ) ? (int) $row['time'] : 0 ),
		'result'  => ! empty( $row['ok'] ) ? 'success' : 'failure',
	);
}

/**
 * Screen rows, newest first.
 *
 * @return array<int, array{feature: string, model: string, neurons: string, time: string, result: string}>
 */
function skyphusion_ai_activity_screen_rows() {
	$out = array();
	foreach ( skyphusion_ai_activity_rows() as $row ) {
		$out[] = skyphusion_ai_activity_screen_row( $row );
	}
	return $out;
}

/**
 * CSV cell. A comma or a quote is quoted.
 *
 * @param string $value Cell text.
 * @return string
 */
function skyphusion_ai_activity_csv_cell( $value ) {
	$value = (string) $value;
	if ( false !== strpos( $value, '"' ) || false !== strpos( $value, ',' ) || false !== strpos( $value, "\n" ) ) {
		return '"' . str_replace( '"', '""', $value ) . '"';
	}
	return $value;
}

/**
 * CSV of the screen rows. The header is the first line.
 *
 * @return string
 */
function skyphusion_ai_activity_export() {
	$lines   = array();
	$lines[] = 'feature,model,neurons,time,result';
	foreach ( skyphusion_ai_activity_screen_rows() as $row ) {
		$cells   = array();
		$cells[] = skyphusion_ai_activity_csv_cell( $row['feature'] );
		$cells[] = skyphusion_ai_activity_csv_cell( $row['model'] );
		$cells[] = skyphusion_ai_activity_csv_cell( $row['neurons'] );
		$cells[] = skyphusion_ai_activity_csv_cell( $row['time'] );
		$cells[] = skyphusion_ai_activity_csv_cell( $row['result'] );
		$lines[] = implode( ',', $cells );
	}
	return implode( "\n", $lines ) . "\n";
}

/**
 * Print the log. The columns match the export.
 *
 * @return void
 */
function skyphusion_ai_activity_render() {
	if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'esc_html' ) ) {
		return;
	}
	echo '<h2>' . esc_html__( 'Activity', 'skyphusion-ai' ) . '</h2>';
	echo '<p>' . esc_html__( 'Each row is one Workers AI call. The export has these same rows.', 'skyphusion-ai' ) . '</p>';
	echo '<table class="widefat">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Feature', 'skyphusion-ai' ) . '</th>';
	echo '<th>' . esc_html__( 'Model', 'skyphusion-ai' ) . '</th>';
	echo '<th>' . esc_html__( 'Neurons', 'skyphusion-ai' ) . '</th>';
	echo '<th>' . esc_html__( 'Time', 'skyphusion-ai' ) . '</th>';
	echo '<th>' . esc_html__( 'Result', 'skyphusion-ai' ) . '</th>';
	echo '</tr></thead><tbody>';
	$rows = skyphusion_ai_activity_screen_rows();
	if ( array() === $rows ) {
		echo '<tr><td colspan="5">' . esc_html__( 'No calls are stored.', 'skyphusion-ai' ) . '</td></tr>';
	}
	foreach ( $rows as $row ) {
		echo '<tr>';
		echo '<td>' . esc_html( $row['feature'] ) . '</td>';
		echo '<td>' . esc_html( $row['model'] ) . '</td>';
		echo '<td>' . esc_html( $row['neurons'] ) . '</td>';
		echo '<td>' . esc_html( $row['time'] ) . '</td>';
		echo '<td>' . esc_html( $row['result'] ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

/**
 * WP-CLI handler for activity list and activity export.
 *
 * @param array<int, string>    $args       Positional arguments.
 * @param array<string, string> $assoc_args Flags.
 * @return void
 */
function skyphusion_ai_activity_cli( $args, $assoc_args ) {
	unset( $assoc_args );
	$command = '';
	if ( isset( $args[0] ) ) {
		$command = (string) $args[0];
	}
	if ( ! class_exists( 'WP_CLI' ) ) {
		return;
	}
	if ( 'export' === $command ) {
		WP_CLI::log( skyphusion_ai_activity_export() );
		return;
	}
	foreach ( skyphusion_ai_activity_screen_rows() as $row ) {
		WP_CLI::log( $row['feature'] . ' ' . $row['model'] . ' ' . $row['neurons'] . ' ' . $row['time'] . ' ' . $row['result'] );
	}
}
