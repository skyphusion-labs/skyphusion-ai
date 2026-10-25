<?php
/**
 * Member search over the stored vectors.
 *
 * Logged-out visitors are not searched and spend nothing. The result is a
 * list of pages. It is not a generated answer. An IP address is not stored.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option for the member search choice.
 *
 * @return string
 */
function skyphusion_ai_member_option_name() {
	return 'skyphusion_ai_member';
}

/**
 * How long a repeated member search is remembered, in seconds.
 *
 * @return int
 */
function skyphusion_ai_member_cache_ttl() {
	return 3600;
}

/**
 * Saved choice. No role means nobody is searched.
 *
 * @return array{roles: array<int, string>, lead: int}
 */
function skyphusion_ai_member_config() {
	$stored = array();
	if ( function_exists( 'get_option' ) ) {
		$stored = get_option( skyphusion_ai_member_option_name(), array() );
	}
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	$roles = array();
	if ( isset( $stored['roles'] ) ) {
		$roles = skyphusion_ai_member_role_list( $stored['roles'] );
	}
	return array(
		'roles' => $roles,
		'lead'  => empty( $stored['lead'] ) ? 0 : 1,
	);
}

/**
 * Keep short role slugs.
 *
 * @param mixed $value Raw roles.
 * @return array<int, string>
 */
function skyphusion_ai_member_role_list( $value ) {
	$items = array();
	if ( is_string( $value ) ) {
		$value = preg_split( '/[\s,]+/', $value );
	}
	if ( ! is_array( $value ) ) {
		return $items;
	}
	foreach ( $value as $item ) {
		$role = strtolower( trim( (string) $item ) );
		if ( ! preg_match( '/^[a-z0-9_]{1,40}$/', $role ) || in_array( $role, $items, true ) ) {
			continue;
		}
		$items[] = $role;
		if ( count( $items ) >= 20 ) {
			break;
		}
	}
	return $items;
}

/**
 * Save the choice. This does not call the model.
 *
 * @param array<string, mixed> $posted Form fields.
 * @return array{roles: array<int, string>, lead: int}
 */
function skyphusion_ai_member_apply( $posted ) {
	$config = skyphusion_ai_member_config();
	if ( ! is_array( $posted ) ) {
		return $config;
	}
	if ( isset( $posted['roles'] ) ) {
		$config['roles'] = skyphusion_ai_member_role_list( $posted['roles'] );
	}
	if ( isset( $posted['lead'] ) ) {
		$config['lead'] = empty( $posted['lead'] ) ? 0 : 1;
	}
	if ( function_exists( 'update_option' ) ) {
		update_option( skyphusion_ai_member_option_name(), $config, false );
	}
	return skyphusion_ai_member_config();
}

/**
 * Roles on the current visitor.
 *
 * @return array<int, string>
 */
function skyphusion_ai_member_user_roles() {
	if ( isset( $GLOBALS['skyphusion_ai_member_roles'] ) && is_array( $GLOBALS['skyphusion_ai_member_roles'] ) ) {
		return skyphusion_ai_member_role_list( $GLOBALS['skyphusion_ai_member_roles'] );
	}
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		return array();
	}
	$user = wp_get_current_user();
	if ( is_object( $user ) && isset( $user->roles ) && is_array( $user->roles ) ) {
		return skyphusion_ai_member_role_list( $user->roles );
	}
	return array();
}

/**
 * True when this signed-in visitor has a chosen role.
 *
 * A logged-out visitor is refused before any role flag is read.
 *
 * @return bool
 */
function skyphusion_ai_member_allowed() {
	$user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	if ( 1 > $user ) {
		return false;
	}
	$config = skyphusion_ai_member_config();
	if ( array() === $config['roles'] ) {
		return false;
	}
	foreach ( skyphusion_ai_member_user_roles() as $role ) {
		if ( in_array( $role, $config['roles'], true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Cache name for one question. The question text is not the name.
 *
 * @param string $question Search text.
 * @return string
 */
function skyphusion_ai_member_cache_key( $question ) {
	return 'skyphusion_ai_ms_' . hash( 'sha256', strtolower( trim( (string) $question ) ) );
}

/**
 * Pages for one member search.
 *
 * A list of title and url. A state array means the search did not run.
 *
 * @param string $question Search text.
 * @return array<int, array{title: string, url: string}>|array{state: string}
 */
function skyphusion_ai_member_results( $question ) {
	$question = trim( (string) $question );
	if ( ! skyphusion_ai_member_allowed() ) {
		return array( 'state' => 'closed' );
	}
	if ( '' === $question ) {
		return array( 'state' => 'empty' );
	}
	$key = skyphusion_ai_member_cache_key( $question );
	if ( function_exists( 'get_transient' ) ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) && ! isset( $cached['state'] ) ) {
			return $cached;
		}
	}
	if ( ! function_exists( 'skyphusion_ai_embed_search' ) ) {
		return array( 'state' => 'blocked' );
	}
	$found = skyphusion_ai_embed_search( $question, 5 );
	if ( ! is_array( $found ) || isset( $found['state'] ) ) {
		return is_array( $found ) ? $found : array( 'state' => 'blocked' );
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
		if ( count( $rows ) >= 5 ) {
			break;
		}
	}
	if ( function_exists( 'set_transient' ) ) {
		set_transient( $key, $rows, skyphusion_ai_member_cache_ttl() );
	}
	return $rows;
}

/**
 * List markup. There is no generated sentence.
 *
 * @param mixed $rows Result rows, or a state array.
 * @return string
 */
function skyphusion_ai_member_html( $rows ) {
	if ( ! is_array( $rows ) || isset( $rows['state'] ) || ! function_exists( 'esc_html' ) ) {
		return '';
	}
	if ( array() === $rows ) {
		return '<p>' . esc_html__( 'No matching page.', 'skyphusion-ai' ) . '</p>';
	}
	$html = '<ul class="skyphusion-ai-member-search">';
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
	return $html;
}

/**
 * Shortcode. Logged-out visitors get an empty string.
 *
 * @param mixed $atts Shortcode attributes.
 * @return string
 */
function skyphusion_ai_member_shortcode( $atts = array() ) {
	if ( ! skyphusion_ai_member_allowed() ) {
		return '';
	}
	$question = '';
	if ( is_array( $atts ) && isset( $atts['query'] ) ) {
		$question = (string) $atts['query'];
	}
	if ( '' === trim( $question ) && function_exists( 'get_search_query' ) ) {
		$question = (string) get_search_query( false );
	}
	return skyphusion_ai_member_html( skyphusion_ai_member_results( $question ) );
}

/**
 * Rows that lead the normal search, or an empty list.
 *
 * @param string $question Search text.
 * @return array<int, array{title: string, url: string}>
 */
function skyphusion_ai_member_lead_rows( $question ) {
	$config = skyphusion_ai_member_config();
	if ( empty( $config['lead'] ) || ! skyphusion_ai_member_allowed() ) {
		return array();
	}
	$rows = skyphusion_ai_member_results( $question );
	if ( ! is_array( $rows ) || isset( $rows['state'] ) ) {
		return array();
	}
	return $rows;
}

/**
 * Remember member results for the main search query.
 *
 * @param mixed $query Query object.
 * @return void
 */
function skyphusion_ai_member_on_search( $query ) {
	if ( ! is_object( $query ) || ! method_exists( $query, 'is_search' ) || ! $query->is_search() ) {
		return;
	}
	if ( method_exists( $query, 'is_main_query' ) && ! $query->is_main_query() ) {
		return;
	}
	$question = '';
	if ( method_exists( $query, 'get' ) ) {
		$question = (string) $query->get( 's' );
	}
	$GLOBALS['skyphusion_ai_member_lead'] = skyphusion_ai_member_lead_rows( $question );
}

/**
 * Print the leading list once, above the normal results.
 *
 * @return void
 */
function skyphusion_ai_member_lead_box() {
	if ( ! isset( $GLOBALS['skyphusion_ai_member_lead'] ) || ! is_array( $GLOBALS['skyphusion_ai_member_lead'] ) ) {
		return;
	}
	if ( function_exists( 'is_admin' ) && is_admin() ) {
		return;
	}
	echo skyphusion_ai_member_html( $GLOBALS['skyphusion_ai_member_lead'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes each value.
	unset( $GLOBALS['skyphusion_ai_member_lead'] );
}

/**
 * Print the choice on the settings screen.
 *
 * @return void
 */
function skyphusion_ai_member_render() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'esc_html' ) ) {
		return;
	}
	$config = skyphusion_ai_member_config();
	echo '<hr />';
	echo '<h2>' . esc_html__( 'Member search', 'skyphusion-ai' ) . '</h2>';
	echo '<p>' . esc_html__( 'Logged-out visitors keep the normal search and spend nothing. Members in a chosen role get a list of pages. There is no generated answer.', 'skyphusion-ai' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="skyphusion_ai_member_save" />';
	if ( function_exists( 'wp_nonce_field' ) ) {
		wp_nonce_field( 'skyphusion_ai_member_save' );
	}
	echo '<p><label>' . esc_html__( 'Roles, separated by commas.', 'skyphusion-ai' ) . ' ';
	echo '<input type="text" name="skyphusion_ai_member_roles" value="' . esc_attr( implode( ', ', $config['roles'] ) ) . '" /></label></p>';
	echo '<p><label><input type="checkbox" name="skyphusion_ai_member_lead" value="1"';
	if ( ! empty( $config['lead'] ) ) {
		echo ' checked="checked"';
	}
	echo ' /> ' . esc_html__( 'Show these pages above the normal search for those members.', 'skyphusion-ai' ) . '</label></p>';
	echo '<p>' . esc_html__( 'Place [skyphusion_ai_member_search] on a page. A repeated search is remembered for one hour.', 'skyphusion-ai' ) . '</p>';
	echo '<button type="submit">' . esc_html__( 'Save the member search', 'skyphusion-ai' ) . '</button>';
	echo '</form>';
}

/**
 * Store the member search form.
 *
 * @return void
 */
function skyphusion_ai_member_handle_save() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( function_exists( 'check_admin_referer' ) ) {
		check_admin_referer( 'skyphusion_ai_member_save' );
	}
	$roles = '';
	if ( isset( $_POST['skyphusion_ai_member_roles'] ) ) {
		$roles = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_member_roles'] ) );
	}
	skyphusion_ai_member_apply(
		array(
			'roles' => $roles,
			'lead'  => isset( $_POST['skyphusion_ai_member_lead'] ) ? '1' : '',
		)
	);
	if ( function_exists( 'wp_safe_redirect' ) ) {
		wp_safe_redirect( admin_url( 'options-general.php?page=cloudflare-ai-search' ) );
		exit;
	}
}

/**
 * Register the shortcode and the search lead.
 *
 * @return void
 */
function skyphusion_ai_member_boot() {
	if ( function_exists( 'add_shortcode' ) ) {
		add_shortcode( 'skyphusion_ai_member_search', 'skyphusion_ai_member_shortcode' );
	}
	if ( function_exists( 'add_action' ) ) {
		add_action( 'pre_get_posts', 'skyphusion_ai_member_on_search' );
		add_action( 'loop_start', 'skyphusion_ai_member_lead_box' );
	}
}

add_action( 'init', 'skyphusion_ai_member_boot' );
add_action( 'admin_post_skyphusion_ai_member_save', 'skyphusion_ai_member_handle_save' );
