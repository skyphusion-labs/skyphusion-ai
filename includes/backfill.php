<?php
/**
 * Fill empty alt text and an empty SEO description.
 *
 * The job is off until an admin turns it on. One batch runs each hour
 * until the empty fields are done or today's backfill limit is reached.
 * That limit does not stop the other tools. Text the admin wrote is left
 * as it is. A spent neuron budget still stops a call.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option that holds the switch, the daily limit, the last run, and the skips.
 *
 * It is not autoloaded.
 *
 * @return string
 */
function skyphusion_ai_backfill_option_name() {
	return 'skyphusion_ai_backfill';
}

/**
 * How many items one batch may send to the model.
 *
 * @return int
 */
function skyphusion_ai_backfill_batch_size() {
	return 5;
}

/**
 * Seconds between batches. A retry from the screen does not wait.
 *
 * @return int
 */
function skyphusion_ai_backfill_interval() {
	return 3600;
}

/**
 * Neurons this job may spend in one UTC day when no limit is saved.
 *
 * Zero, once saved, means this job has no limit of its own.
 *
 * @return int
 */
function skyphusion_ai_backfill_daily_default() {
	return 3000;
}

/**
 * A whole number of neurons, including zero. Anything else is refused.
 *
 * @param mixed $value Raw value.
 * @return int|null
 */
function skyphusion_ai_backfill_limit_value( $value ) {
	if ( is_int( $value ) && $value >= 0 ) {
		return $value;
	}
	if ( is_string( $value ) && 1 === preg_match( '/^(0|[1-9][0-9]{0,8})$/', $value ) ) {
		return (int) $value;
	}
	return null;
}

/**
 * Neurons this job has spent today. A row from another UTC day counts as zero.
 *
 * @param array<string, mixed> $config Saved config.
 * @return int
 */
function skyphusion_ai_backfill_used( $config ) {
	$today = function_exists( 'skyphusion_ai_neuron_today' ) ? skyphusion_ai_neuron_today() : gmdate( 'Y-m-d' );
	if ( ! is_array( $config ) || ! isset( $config['day'] ) || (string) $config['day'] !== $today ) {
		return 0;
	}
	$used = isset( $config['neurons'] ) ? (int) $config['neurons'] : 0;
	if ( $used < 0 ) {
		return 0;
	}
	return $used;
}

/**
 * True when today's backfill neurons have reached the saved limit.
 *
 * A limit of zero does not stop this job. The site neuron budget is separate.
 *
 * @param array<string, mixed> $config Saved config.
 * @return bool
 */
function skyphusion_ai_backfill_blocked( $config ) {
	if ( ! is_array( $config ) ) {
		return false;
	}
	$limit = isset( $config['limit'] ) ? (int) $config['limit'] : skyphusion_ai_backfill_daily_default();
	if ( $limit < 1 ) {
		return false;
	}
	return skyphusion_ai_backfill_used( $config ) >= $limit;
}

/**
 * Add neurons from one backfill response to today's row.
 *
 * A figure Cloudflare did not send adds nothing. The site ledger is unchanged
 * by this write. Another tool keeps its own room.
 *
 * @param string $model   Model id.
 * @param mixed  $decoded Response JSON.
 * @return void
 */
function skyphusion_ai_backfill_add_neurons( $model, $decoded ) {
	$neurons = 0;
	if ( function_exists( 'skyphusion_ai_neuron_measure' ) ) {
		$measured = skyphusion_ai_neuron_measure( (string) $model, $decoded );
		if ( ! empty( $measured['known'] ) ) {
			$neurons = (int) $measured['neurons'];
		}
	}
	if ( $neurons < 0 ) {
		$neurons = 0;
	}
	$config = skyphusion_ai_backfill_config();
	$today  = function_exists( 'skyphusion_ai_neuron_today' ) ? skyphusion_ai_neuron_today() : gmdate( 'Y-m-d' );
	if ( (string) $config['day'] !== $today ) {
		$config['neurons'] = 0;
		$config['day']     = $today;
	}
	$config['neurons'] = (int) $config['neurons'] + $neurons;
	if ( function_exists( 'update_option' ) ) {
		update_option( skyphusion_ai_backfill_option_name(), $config, false );
	}
}

/**
 * Saved switch, daily limit, last run, and skip rows.
 *
 * @return array{enabled: int, last_at: int, limit: int, day: string, neurons: int, skips: array<int, array{post_id: int, kind: string, reason: string}>}
 */
function skyphusion_ai_backfill_config() {
	$stored = array();
	if ( function_exists( 'get_option' ) ) {
		$stored = get_option( skyphusion_ai_backfill_option_name(), array() );
	}
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	$skips = array();
	if ( isset( $stored['skips'] ) && is_array( $stored['skips'] ) ) {
		foreach ( $stored['skips'] as $row ) {
			$clean = skyphusion_ai_backfill_public_skip( $row );
			if ( array() !== $clean ) {
				$skips[] = $clean;
			}
		}
	}
	$limit = skyphusion_ai_backfill_daily_default();
	if ( array_key_exists( 'limit', $stored ) ) {
		$parsed = skyphusion_ai_backfill_limit_value( $stored['limit'] );
		if ( null !== $parsed ) {
			$limit = $parsed;
		}
	}
	$day = '';
	if ( isset( $stored['day'] ) && is_string( $stored['day'] ) && 1 === preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $stored['day'] ) ) {
		$day = $stored['day'];
	}
	$neurons = isset( $stored['neurons'] ) ? (int) $stored['neurons'] : 0;
	if ( $neurons < 0 ) {
		$neurons = 0;
	}
	return array(
		'enabled' => empty( $stored['enabled'] ) ? 0 : 1,
		'last_at' => isset( $stored['last_at'] ) ? (int) $stored['last_at'] : 0,
		'limit'   => $limit,
		'day'     => $day,
		'neurons' => $neurons,
		'skips'   => $skips,
	);
}

/**
 * A skip row the screen may show. An address is not a field.
 *
 * @param mixed $row Stored row.
 * @return array{post_id: int, kind: string, reason: string}
 */
function skyphusion_ai_backfill_public_skip( $row ) {
	if ( ! is_array( $row ) ) {
		return array();
	}
	$kind = 'description';
	if ( isset( $row['kind'] ) && 'alt' === $row['kind'] ) {
		$kind = 'alt';
	}
	$reason = isset( $row['reason'] ) ? (string) $row['reason'] : '';
	if ( ! in_array( $reason, array( 'budget', 'empty', 'blocked', 'missing', 'short', 'noimage', 'limit' ), true ) ) {
		$reason = 'missing';
	}
	return array(
		'post_id' => isset( $row['post_id'] ) ? (int) $row['post_id'] : 0,
		'kind'    => $kind,
		'reason'  => $reason,
	);
}

/**
 * Store the switch and the daily limit. An omitted key keeps the previous value.
 *
 * @param array<string, mixed> $posted Posted fields.
 * @return array{enabled: int, last_at: int, limit: int, day: string, neurons: int, skips: array<int, array{post_id: int, kind: string, reason: string}>}
 */
function skyphusion_ai_backfill_apply( $posted ) {
	$config = skyphusion_ai_backfill_config();
	if ( ! is_array( $posted ) ) {
		return $config;
	}
	if ( array_key_exists( 'enabled', $posted ) ) {
		$config['enabled'] = empty( $posted['enabled'] ) ? 0 : 1;
	}
	if ( array_key_exists( 'limit', $posted ) ) {
		$parsed = skyphusion_ai_backfill_limit_value( $posted['limit'] );
		if ( null !== $parsed ) {
			$config['limit'] = $parsed;
		}
	}
	if ( function_exists( 'update_option' ) ) {
		update_option( skyphusion_ai_backfill_option_name(), $config, false );
	}
	return $config;
}

/**
 * SEO plugin name this job knows.
 *
 * @param string $name Raw name.
 * @return string
 */
function skyphusion_ai_backfill_seo_name( $name ) {
	$name = (string) $name;
	if ( in_array( $name, array( 'yoast', 'rank_math', 'aioseo', 'seopress' ), true ) ) {
		return $name;
	}
	return '';
}

/**
 * Active SEO plugin. Yoast wins when more than one is present.
 *
 * A string in $GLOBALS['skyphusion_ai_backfill_seo'] is the test stand-in.
 *
 * @return string
 */
function skyphusion_ai_backfill_seo_plugin() {
	if ( isset( $GLOBALS['skyphusion_ai_backfill_seo'] ) && is_string( $GLOBALS['skyphusion_ai_backfill_seo'] ) ) {
		return skyphusion_ai_backfill_seo_name( $GLOBALS['skyphusion_ai_backfill_seo'] );
	}
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'yoast';
	}
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		return 'rank_math';
	}
	if ( defined( 'AIOSEO_VERSION' ) ) {
		return 'aioseo';
	}
	if ( defined( 'SEOPRESS_VERSION' ) ) {
		return 'seopress';
	}
	return '';
}

/**
 * Post meta key for one SEO plugin. AIOSEO keeps its own table.
 *
 * @param string $seo Plugin name.
 * @return string
 */
function skyphusion_ai_backfill_meta_key( $seo ) {
	$map = array(
		'yoast'     => '_yoast_wpseo_metadesc',
		'rank_math' => 'rank_math_description',
		'seopress'  => '_seopress_titles_desc',
	);
	$seo = skyphusion_ai_backfill_seo_name( $seo );
	return isset( $map[ $seo ] ) ? $map[ $seo ] : '';
}

/**
 * Alt meta key. WordPress stores this on the attachment.
 *
 * @return string
 */
function skyphusion_ai_backfill_alt_key() {
	return '_wp_attachment_image_alt';
}

/**
 * Description already stored for All in One SEO.
 *
 * Null means the table cannot be read. An empty string is an empty field.
 * The test stand-in is $GLOBALS['skyphusion_ai_backfill_aioseo'].
 *
 * @param int $post_id Post id.
 * @return string|null
 */
function skyphusion_ai_backfill_aioseo_current( $post_id ) {
	$post_id = (int) $post_id;
	if ( isset( $GLOBALS['skyphusion_ai_backfill_aioseo'] ) && is_array( $GLOBALS['skyphusion_ai_backfill_aioseo'] ) ) {
		if ( ! array_key_exists( $post_id, $GLOBALS['skyphusion_ai_backfill_aioseo'] ) ) {
			return '';
		}
		return trim( (string) $GLOBALS['skyphusion_ai_backfill_aioseo'][ $post_id ] );
	}
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
		return null;
	}
	if ( ! function_exists( 'esc_sql' ) ) {
		return null;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- AIOSEO stores the description in this table. The name is the prefix plus a fixed suffix, passed through esc_sql.
	$value = $wpdb->get_var( $wpdb->prepare( 'SELECT description FROM ' . esc_sql( $wpdb->prefix . 'aioseo_posts' ) . ' WHERE post_id = %d', $post_id ) );
	if ( null === $value ) {
		return null;
	}
	return trim( (string) $value );
}

/**
 * Write an empty All in One SEO description. A filled field is left as it is.
 *
 * @param int    $post_id Post id.
 * @param string $text    Description.
 * @return bool
 */
function skyphusion_ai_backfill_aioseo_write( $post_id, $text ) {
	$post_id = (int) $post_id;
	$text    = trim( (string) $text );
	if ( '' === $text ) {
		return false;
	}
	if ( isset( $GLOBALS['skyphusion_ai_backfill_aioseo'] ) && is_array( $GLOBALS['skyphusion_ai_backfill_aioseo'] ) ) {
		$current = skyphusion_ai_backfill_aioseo_current( $post_id );
		if ( null === $current || '' !== $current ) {
			return false;
		}
		$GLOBALS['skyphusion_ai_backfill_aioseo'][ $post_id ] = $text;
		return true;
	}
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'update' ) ) {
		return false;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- AIOSEO stores the description in this table. The where clause keeps a filled field.
	$updated = $wpdb->update(
		$wpdb->prefix . 'aioseo_posts',
		array( 'description' => $text ),
		array(
			'post_id'     => $post_id,
			'description' => '',
		),
		array( '%s' ),
		array( '%d', '%s' )
	);
	return false !== $updated && $updated > 0;
}

/**
 * Text already stored for this item. Null means it cannot be read.
 *
 * @param int    $post_id Post id.
 * @param string $kind    alt or description.
 * @param string $seo     Active SEO plugin.
 * @return string|null
 */
function skyphusion_ai_backfill_current( $post_id, $kind, $seo ) {
	if ( 'alt' === $kind ) {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return null;
		}
		$alt = trim( (string) get_post_meta( (int) $post_id, skyphusion_ai_backfill_alt_key(), true ) );
		if ( '' !== $alt ) {
			return $alt;
		}
		$mark = trim( (string) get_post_meta( (int) $post_id, skyphusion_ai_backfill_decorative_key(), true ) );
		if ( '1' === $mark ) {
			return $mark;
		}
		return '';
	}
	if ( 'aioseo' === $seo ) {
		return skyphusion_ai_backfill_aioseo_current( $post_id );
	}
	$key = skyphusion_ai_backfill_meta_key( $seo );
	if ( '' === $key || ! function_exists( 'get_post_meta' ) ) {
		return null;
	}
	return trim( (string) get_post_meta( (int) $post_id, $key, true ) );
}

/**
 * Store generated text when the field is still empty.
 *
 * @param int    $post_id Post id.
 * @param string $kind    alt or description.
 * @param string $seo     Active SEO plugin.
 * @param string $text    Generated text.
 * @return bool
 */
function skyphusion_ai_backfill_write( $post_id, $kind, $seo, $text ) {
	$text = skyphusion_ai_backfill_trim( $kind, $text );
	if ( '' === $text ) {
		return false;
	}
	$current = skyphusion_ai_backfill_current( $post_id, $kind, $seo );
	if ( null === $current || '' !== $current ) {
		return false;
	}
	if ( 'alt' === $kind ) {
		if ( ! function_exists( 'update_post_meta' ) ) {
			return false;
		}
		update_post_meta( (int) $post_id, skyphusion_ai_backfill_alt_key(), $text );
		return true;
	}
	if ( 'aioseo' === $seo ) {
		return skyphusion_ai_backfill_aioseo_write( $post_id, $text );
	}
	$key = skyphusion_ai_backfill_meta_key( $seo );
	if ( '' === $key || ! function_exists( 'update_post_meta' ) ) {
		return false;
	}
	update_post_meta( (int) $post_id, $key, $text );
	return true;
}

/**
 * Cut generated text to the field length.
 *
 * @param string $kind alt or description.
 * @param string $text Generated text.
 * @return string
 */
function skyphusion_ai_backfill_trim( $kind, $text ) {
	if ( function_exists( 'wp_strip_all_tags' ) ) {
		$text = wp_strip_all_tags( (string) $text );
	} else {
		$stripped = preg_replace( '/<[^>]*>/', '', (string) $text );
		$text     = is_string( $stripped ) ? $stripped : (string) $text;
	}
	$collapsed = preg_replace( '/\s+/', ' ', $text );
	$text      = trim( is_string( $collapsed ) ? $collapsed : $text );
	$cap       = ( 'alt' === $kind ) ? 125 : 160;
	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $text, 0, $cap );
	}
	return substr( $text, 0, $cap );
}

/**
 * Minimum page text an SEO description will send.
 *
 * @return int
 */
function skyphusion_ai_backfill_content_floor() {
	return 40;
}

/**
 * Maximum page text an SEO description will send.
 *
 * @return int
 */
function skyphusion_ai_backfill_content_cap() {
	return 6000;
}

/**
 * Page text with tags, shortcodes, and extra space removed.
 *
 * @param string $content Raw page text.
 * @return string
 */
function skyphusion_ai_backfill_plain( $content ) {
	$text = (string) $content;
	if ( function_exists( 'strip_shortcodes' ) ) {
		$text = strip_shortcodes( $text );
	} else {
		$stripped = preg_replace( '/\[[^\]]*\]/', '', $text );
		$text     = is_string( $stripped ) ? $stripped : $text;
	}
	if ( function_exists( 'wp_strip_all_tags' ) ) {
		$text = wp_strip_all_tags( $text );
	} else {
		$stripped = preg_replace( '/<[^>]*>/', '', $text );
		$text     = is_string( $stripped ) ? $stripped : $text;
	}
	$collapsed = preg_replace( '/\s+/', ' ', $text );
	return trim( is_string( $collapsed ) ? $collapsed : $text );
}

/**
 * Length of plain page text.
 *
 * @param string $text Plain page text.
 * @return int
 */
function skyphusion_ai_backfill_plain_length( $text ) {
	if ( function_exists( 'mb_strlen' ) ) {
		return (int) mb_strlen( (string) $text );
	}
	return strlen( (string) $text );
}

/**
 * Raw page text for one post. A post id that does not match is ignored.
 *
 * @param int $post_id Post id.
 * @return string
 */
function skyphusion_ai_backfill_page_text( $post_id ) {
	if ( ! function_exists( 'get_post' ) ) {
		return '';
	}
	$post = get_post( (int) $post_id );
	if ( is_object( $post ) && isset( $post->post_content ) ) {
		return (string) $post->post_content;
	}
	if ( is_array( $post ) && isset( $post['post_content'] ) ) {
		$id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		if ( 0 === $id || $id === (int) $post_id ) {
			return (string) $post['post_content'];
		}
	}
	return '';
}

/**
 * Prompt for one empty field.
 *
 * Alt text sends the title. An SEO description also sends the page text
 * when that text is at least 40 characters.
 *
 * @param string $kind    alt or description.
 * @param string $title   Page or image title.
 * @param string $content Raw page text. Alt text ignores it.
 * @return string
 */
function skyphusion_ai_backfill_prompt( $kind, $title, $content = '' ) {
	$collapsed = preg_replace( '/\s+/', ' ', (string) $title );
	$title     = trim( is_string( $collapsed ) ? $collapsed : (string) $title );
	if ( function_exists( 'mb_substr' ) ) {
		$title = mb_substr( $title, 0, 120 );
	} else {
		$title = substr( $title, 0, 120 );
	}
	$key = ( 'alt' === $kind ) ? 'backfill_alt' : 'backfill_description';
	if ( function_exists( 'skyphusion_ai_prompt_fill' ) ) {
		$prompt = skyphusion_ai_prompt_fill( $key, array( 'title' => $title ) );
	} elseif ( 'alt' === $kind ) {
		$prompt = 'Write one alt sentence for this image. Title: ' . $title;
	} else {
		$prompt = 'Write one SEO description for this page. Title: ' . $title;
	}
	if ( 'alt' === $kind ) {
		return $prompt;
	}
	$plain = skyphusion_ai_backfill_plain( $content );
	if ( skyphusion_ai_backfill_plain_length( $plain ) < skyphusion_ai_backfill_content_floor() ) {
		return $prompt;
	}
	if ( function_exists( 'mb_substr' ) ) {
		$plain = mb_substr( $plain, 0, skyphusion_ai_backfill_content_cap() );
	} else {
		$plain = substr( $plain, 0, skyphusion_ai_backfill_content_cap() );
	}
	return $prompt . "\n\nContent:\n" . $plain;
}

/**
 * Meta key for an image that carries no information.
 *
 * @return string
 */
function skyphusion_ai_backfill_decorative_key() {
	return '_skyphusion_ai_decorative_alt';
}

/**
 * Word the vision model uses when an image carries no information.
 *
 * @return string
 */
function skyphusion_ai_backfill_decorative_word() {
	return 'decorative';
}

/**
 * True when the whole reply is the decorative word.
 *
 * @param string $text Model reply.
 * @return bool
 */
function skyphusion_ai_backfill_is_decorative( $text ) {
	$text = strtolower( trim( (string) $text ) );
	$text = rtrim( $text, '.' );
	$text = trim( $text );
	return skyphusion_ai_backfill_decorative_word() === $text;
}

/**
 * Remember a decorative image and leave the alt field empty.
 *
 * @param int $post_id Attachment id.
 * @return bool
 */
function skyphusion_ai_backfill_mark_decorative( $post_id ) {
	if ( ! function_exists( 'update_post_meta' ) ) {
		return false;
	}
	update_post_meta( (int) $post_id, skyphusion_ai_backfill_decorative_key(), '1' );
	return true;
}

/**
 * Largest local image this job will read, in bytes.
 *
 * @return int
 */
function skyphusion_ai_backfill_image_cap() {
	return 2000000;
}

/**
 * True for an http, https, or data image URL.
 *
 * @param string $url Image URL.
 * @return bool
 */
function skyphusion_ai_backfill_image_allowed( $url ) {
	$url = trim( (string) $url );
	if ( 0 === strpos( $url, 'data:image/' ) ) {
		return false === strpos( $url, ' ' );
	}
	if ( function_exists( 'wp_parse_url' ) ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		return 'https' === $scheme || 'http' === $scheme;
	}
	$scheme = strtolower( (string) strtok( $url, ':' ) );
	if ( 'https' !== $scheme && 'http' !== $scheme ) {
		return false;
	}
	$rest = substr( $url, strlen( $scheme ) + 3 );
	return '' !== $rest && 0 !== strpos( $rest, '/' ) && false === strpos( $rest, ' ' );
}

/**
 * Public image URL, or a data URL read from a local file.
 *
 * A public URL is preferred so the file is not loaded in PHP.
 *
 * @param int $post_id Attachment id.
 * @return string
 */
function skyphusion_ai_backfill_image_ref( $post_id ) {
	$post_id = (int) $post_id;
	$url     = '';
	if ( function_exists( 'wp_get_attachment_url' ) ) {
		$url = (string) wp_get_attachment_url( $post_id );
	}
	if ( skyphusion_ai_backfill_image_allowed( $url ) && 0 !== strpos( $url, 'data:' ) ) {
		return $url;
	}
	if ( 0 === strpos( $url, 'data:image/' ) ) {
		return $url;
	}
	$path = '';
	if ( function_exists( 'get_attached_file' ) ) {
		$path = (string) get_attached_file( $post_id );
	}
	return skyphusion_ai_backfill_image_file( $path );
}

/**
 * Data URL for one local image file. An unreadable or huge file is empty.
 *
 * @param string $path File path.
 * @return string
 */
function skyphusion_ai_backfill_image_file( $path ) {
	$path = (string) $path;
	if ( '' === $path || ! is_readable( $path ) ) {
		return '';
	}
	$size = filesize( $path );
	if ( ! is_int( $size ) && ! is_float( $size ) ) {
		return '';
	}
	$size = (int) $size;
	if ( 1 > $size || $size > skyphusion_ai_backfill_image_cap() ) {
		return '';
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local attachment, not a remote request.
	$raw = file_get_contents( $path );
	if ( ! is_string( $raw ) || '' === $raw ) {
		return '';
	}
	$mime  = 'image/jpeg';
	$lower = strtolower( $path );
	if ( str_ends_with( $lower, '.png' ) ) {
		$mime = 'image/png';
	} elseif ( str_ends_with( $lower, '.gif' ) ) {
		$mime = 'image/gif';
	} elseif ( str_ends_with( $lower, '.webp' ) ) {
		$mime = 'image/webp';
	}
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- image bytes for the vision request, not hidden text.
	return 'data:' . $mime . ';base64,' . base64_encode( $raw );
}

/**
 * Saved alt prompt plus the decorative instruction.
 *
 * @param string $title Image title.
 * @return string
 */
function skyphusion_ai_backfill_alt_prompt( $title ) {
	$prompt = skyphusion_ai_backfill_prompt( 'alt', $title );
	return $prompt . "\nIf the image has no information, reply with the single word decorative.";
}

/**
 * Text model id for an SEO description.
 *
 * @return string
 */
function skyphusion_ai_backfill_model() {
	if ( function_exists( 'skyphusion_ai_model_for_task' ) ) {
		return skyphusion_ai_model_for_task( 'meta' );
	}
	if ( function_exists( 'skyphusion_ai_model_choice' ) ) {
		return skyphusion_ai_model_choice( 'writing' );
	}
	return '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
}

/**
 * Vision model id for empty alt text.
 *
 * @return string
 */
function skyphusion_ai_backfill_vision_model() {
	if ( function_exists( 'skyphusion_ai_model_choice' ) ) {
		$chosen = skyphusion_ai_model_choice( 'vision' );
		if ( '' !== $chosen ) {
			return $chosen;
		}
	}
	return '@cf/mistralai/mistral-small-3.1-24b-instruct';
}

/**
 * Ask the writing model. A spent budget does not leave the site.
 *
 * @param string $prompt Prompt text.
 * @return string|WP_Error
 */
function skyphusion_ai_backfill_text( $prompt ) {
	return skyphusion_ai_backfill_call( $prompt, skyphusion_ai_backfill_model(), 'text', '' );
}

/**
 * Ask the vision model with one image.
 *
 * @param string $prompt Prompt text.
 * @param string $image  Allowed image URL.
 * @return string|WP_Error
 */
function skyphusion_ai_backfill_see( $prompt, $image ) {
	return skyphusion_ai_backfill_call( $prompt, skyphusion_ai_backfill_vision_model(), 'vision', $image );
}

/**
 * Ask one model. A spent budget does not leave the site.
 *
 * @param string $prompt  Prompt text.
 * @param string $model   Model id.
 * @param string $feature text or vision.
 * @param string $image   Image URL. Empty for text.
 * @return string|WP_Error
 */
function skyphusion_ai_backfill_call( $prompt, $model, $feature, $image ) {
	if ( function_exists( 'skyphusion_ai_neuron_refusal' ) ) {
		$refusal = skyphusion_ai_neuron_refusal();
		if ( is_wp_error( $refusal ) ) {
			return $refusal;
		}
	}
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
	if ( function_exists( 'cloudflare_ai_provider_run_url' ) && is_array( $credentials ) ) {
		$account = isset( $credentials['account_id'] ) ? (string) $credentials['account_id'] : '';
		$url     = cloudflare_ai_provider_run_url( $account, $gateway, $model );
	}
	$token = ( is_array( $credentials ) && isset( $credentials['token'] ) ) ? (string) $credentials['token'] : '';
	if ( '' === $url || '' === $token || ! function_exists( 'wp_remote_post' ) ) {
		return new WP_Error( 'skyphusion_ai_backfill', __( 'The text could not be written.', 'skyphusion-ai' ) );
	}
	$message = array(
		'role' => 'user',
		'text' => $prompt,
	);
	if ( '' !== $image ) {
		$message['images'] = array( $image );
	}
	$body = array();
	if ( function_exists( 'cloudflare_ai_provider_chat_body' ) ) {
		$body = cloudflare_ai_provider_chat_body(
			array(
				'model'    => $model,
				'messages' => array( $message ),
			)
		);
	}
	$headers = array(
		'Content-Type'  => 'application/json',
		'Authorization' => 'Bearer ' . $token,
	);
	if ( function_exists( 'skyphusion_ai_gateway_metadata_header' ) ) {
		$headers = array_merge( $headers, skyphusion_ai_gateway_metadata_header( $url, $feature, $model ) );
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
		return new WP_Error( 'skyphusion_ai_backfill', __( 'The text could not be written.', 'skyphusion-ai' ) );
	}
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
		skyphusion_ai_neuron_record( $feature, $model, $decoded, 'backfill' );
	}
	skyphusion_ai_backfill_add_neurons( $model, $decoded );
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error( 'skyphusion_ai_backfill', __( 'The text could not be written.', 'skyphusion-ai' ) );
	}
	$read = array();
	if ( function_exists( 'cloudflare_ai_provider_read_text' ) ) {
		$read = cloudflare_ai_provider_read_text( is_array( $decoded ) ? $decoded : array() );
	}
	$written = '';
	if ( isset( $read['candidates'][0]['text'] ) ) {
		$written = trim( (string) $read['candidates'][0]['text'] );
	}
	return $written;
}

/**
 * Items to fill. The test stand-in is $GLOBALS['skyphusion_ai_backfill_candidates'].
 *
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_backfill_candidates() {
	if ( isset( $GLOBALS['skyphusion_ai_backfill_candidates'] ) && is_array( $GLOBALS['skyphusion_ai_backfill_candidates'] ) ) {
		return $GLOBALS['skyphusion_ai_backfill_candidates'];
	}
	return skyphusion_ai_backfill_live_candidates();
}

/**
 * Empty alt text, then empty SEO descriptions, up to one batch.
 *
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_backfill_live_candidates() {
	$rows = array();
	if ( ! function_exists( 'get_posts' ) ) {
		return $rows;
	}
	$limit  = skyphusion_ai_backfill_batch_size();
	$images = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => 50,
			'fields'         => 'ids',
		)
	);
	if ( is_array( $images ) ) {
		foreach ( $images as $post_id ) {
			$post_id = (int) $post_id;
			$current = skyphusion_ai_backfill_current( $post_id, 'alt', '' );
			if ( '' !== $current ) {
				continue;
			}
			$rows[] = array(
				'post_id' => $post_id,
				'kind'    => 'alt',
				'title'   => skyphusion_ai_backfill_title( $post_id ),
			);
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}
	}
	if ( count( $rows ) >= $limit || '' === skyphusion_ai_backfill_seo_plugin() ) {
		return array_slice( $rows, 0, $limit );
	}
	$posts = get_posts(
		array(
			'post_type'      => array( 'post', 'product' ),
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'has_password'   => false,
		)
	);
	if ( is_array( $posts ) ) {
		foreach ( $posts as $post_id ) {
			$post_id = (int) $post_id;
			$current = skyphusion_ai_backfill_current( $post_id, 'description', skyphusion_ai_backfill_seo_plugin() );
			if ( '' !== $current ) {
				continue;
			}
			$rows[] = array(
				'post_id' => $post_id,
				'kind'    => 'description',
				'title'   => skyphusion_ai_backfill_title( $post_id ),
			);
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}
	}
	return $rows;
}

/**
 * Title for a prompt. The page text is read separately.
 *
 * @param int $post_id Post id.
 * @return string
 */
function skyphusion_ai_backfill_title( $post_id ) {
	if ( ! function_exists( 'get_post' ) ) {
		return '';
	}
	$post = get_post( (int) $post_id );
	if ( is_object( $post ) && isset( $post->post_title ) ) {
		return (string) $post->post_title;
	}
	if ( is_array( $post ) && isset( $post['post_title'] ) ) {
		return (string) $post['post_title'];
	}
	return '';
}

/**
 * Replace one skip and drop the oldest past the cap.
 *
 * @param array<int, array{post_id: int, kind: string, reason: string}> $skips   Current rows.
 * @param int                                                           $post_id Post id.
 * @param string                                                        $kind    alt or description.
 * @param string                                                        $reason  Skip reason.
 * @return array<int, array{post_id: int, kind: string, reason: string}>
 */
function skyphusion_ai_backfill_remember_skip( $skips, $post_id, $kind, $reason ) {
	$next = array();
	foreach ( $skips as $row ) {
		if ( (int) $row['post_id'] === (int) $post_id && $row['kind'] === $kind ) {
			continue;
		}
		$next[] = $row;
	}
	$clean = skyphusion_ai_backfill_public_skip(
		array(
			'post_id' => $post_id,
			'kind'    => $kind,
			'reason'  => $reason,
		)
	);
	if ( array() !== $clean ) {
		$next[] = $clean;
	}
	if ( count( $next ) > 20 ) {
		$next = array_slice( $next, -20 );
	}
	return $next;
}

/**
 * Drop a skip after the field is written.
 *
 * @param array<int, array{post_id: int, kind: string, reason: string}> $skips   Current rows.
 * @param int                                                           $post_id Post id.
 * @param string                                                        $kind    alt or description.
 * @return array<int, array{post_id: int, kind: string, reason: string}>
 */
function skyphusion_ai_backfill_forget_skip( $skips, $post_id, $kind ) {
	$next = array();
	foreach ( $skips as $row ) {
		if ( (int) $row['post_id'] === (int) $post_id && $row['kind'] === $kind ) {
			continue;
		}
		$next[] = $row;
	}
	return $next;
}

/**
 * Run one batch. A retry ignores the hour. The backfill limit and the site budget still stop a call.
 *
 * @param bool $force True when the admin asks to retry.
 * @return string off, waiting, or ran.
 */
function skyphusion_ai_backfill_run( $force ) {
	$config = skyphusion_ai_backfill_config();
	if ( empty( $config['enabled'] ) ) {
		return 'off';
	}
	$now = time();
	if ( ! $force && $config['last_at'] > 0 && ( $now - $config['last_at'] ) < skyphusion_ai_backfill_interval() ) {
		return 'waiting';
	}
	$seo      = skyphusion_ai_backfill_seo_plugin();
	$skips    = $config['skips'];
	$attempts = 0;
	$limit    = skyphusion_ai_backfill_batch_size();
	foreach ( skyphusion_ai_backfill_candidates() as $candidate ) {
		if ( $attempts >= $limit ) {
			break;
		}
		if ( ! is_array( $candidate ) ) {
			continue;
		}
		$post_id = isset( $candidate['post_id'] ) ? (int) $candidate['post_id'] : 0;
		$kind    = ( isset( $candidate['kind'] ) && 'alt' === $candidate['kind'] ) ? 'alt' : 'description';
		if ( $post_id < 1 ) {
			continue;
		}
		$current = skyphusion_ai_backfill_current( $post_id, $kind, $seo );
		if ( null === $current ) {
			++$attempts;
			$skips = skyphusion_ai_backfill_remember_skip( $skips, $post_id, $kind, 'missing' );
			continue;
		}
		if ( '' !== $current ) {
			$skips = skyphusion_ai_backfill_forget_skip( $skips, $post_id, $kind );
			continue;
		}
		$title = isset( $candidate['title'] ) ? (string) $candidate['title'] : skyphusion_ai_backfill_title( $post_id );
		$page  = '';
		if ( 'description' === $kind ) {
			$raw  = isset( $candidate['content'] ) ? (string) $candidate['content'] : skyphusion_ai_backfill_page_text( $post_id );
			$page = skyphusion_ai_backfill_plain( $raw );
			if ( skyphusion_ai_backfill_plain_length( $page ) < skyphusion_ai_backfill_content_floor() ) {
				$skips = skyphusion_ai_backfill_remember_skip( $skips, $post_id, $kind, 'short' );
				continue;
			}
		}
		$image = '';
		if ( 'alt' === $kind ) {
			$image = isset( $candidate['image'] ) ? (string) $candidate['image'] : skyphusion_ai_backfill_image_ref( $post_id );
			if ( ! skyphusion_ai_backfill_image_allowed( $image ) ) {
				$skips = skyphusion_ai_backfill_remember_skip( $skips, $post_id, $kind, 'noimage' );
				continue;
			}
		}
		if ( skyphusion_ai_backfill_blocked( skyphusion_ai_backfill_config() ) ) {
			$skips = skyphusion_ai_backfill_remember_skip( $skips, $post_id, $kind, 'limit' );
			break;
		}
		++$attempts;
		if ( function_exists( 'skyphusion_ai_neuron_refusal' ) && is_wp_error( skyphusion_ai_neuron_refusal() ) ) {
			$skips = skyphusion_ai_backfill_remember_skip( $skips, $post_id, $kind, 'budget' );
			break;
		}
		if ( 'alt' === $kind ) {
			$written = skyphusion_ai_backfill_see( skyphusion_ai_backfill_alt_prompt( $title ), $image );
		} else {
			$written = skyphusion_ai_backfill_text( skyphusion_ai_backfill_prompt( $kind, $title, $page ) );
		}
		if ( is_wp_error( $written ) ) {
			$reason = 'blocked';
			if ( function_exists( 'skyphusion_ai_neuron_refusal' ) && is_wp_error( skyphusion_ai_neuron_refusal() ) ) {
				$reason = 'budget';
			}
			$skips = skyphusion_ai_backfill_remember_skip( $skips, $post_id, $kind, $reason );
			if ( 'budget' === $reason ) {
				break;
			}
			continue;
		}
		if ( 'alt' === $kind && skyphusion_ai_backfill_is_decorative( $written ) ) {
			if ( skyphusion_ai_backfill_mark_decorative( $post_id ) ) {
				$skips = skyphusion_ai_backfill_forget_skip( $skips, $post_id, $kind );
				continue;
			}
			$skips = skyphusion_ai_backfill_remember_skip( $skips, $post_id, $kind, 'missing' );
			continue;
		}
		$written = skyphusion_ai_backfill_trim( $kind, $written );
		if ( '' === $written ) {
			$skips = skyphusion_ai_backfill_remember_skip( $skips, $post_id, $kind, 'empty' );
			continue;
		}
		if ( skyphusion_ai_backfill_write( $post_id, $kind, $seo, $written ) ) {
			$skips = skyphusion_ai_backfill_forget_skip( $skips, $post_id, $kind );
			continue;
		}
		$skips = skyphusion_ai_backfill_remember_skip( $skips, $post_id, $kind, 'missing' );
	}
	$fresh             = skyphusion_ai_backfill_config();
	$config['limit']   = $fresh['limit'];
	$config['day']     = $fresh['day'];
	$config['neurons'] = $fresh['neurons'];
	$config['skips']   = $skips;
	$config['last_at'] = $now;
	if ( function_exists( 'update_option' ) ) {
		update_option( skyphusion_ai_backfill_option_name(), $config, false );
	}
	return 'ran';
}

/**
 * Sentence for one skip. The post id is printed beside it.
 *
 * @param array{post_id: int, kind: string, reason: string} $row Skip row.
 * @return string
 */
function skyphusion_ai_backfill_skip_label( $row ) {
	$kind   = isset( $row['kind'] ) ? (string) $row['kind'] : '';
	$reason = isset( $row['reason'] ) ? (string) $row['reason'] : '';
	if ( 'alt' === $kind && 'budget' === $reason ) {
		return __( 'Alt text was skipped. The daily neuron budget stopped this item.', 'skyphusion-ai' );
	}
	if ( 'alt' === $kind && 'empty' === $reason ) {
		return __( 'Alt text was skipped. The model returned no text.', 'skyphusion-ai' );
	}
	if ( 'alt' === $kind && 'blocked' === $reason ) {
		return __( 'Alt text was skipped. The model could not write this item.', 'skyphusion-ai' );
	}
	if ( 'alt' === $kind && 'noimage' === $reason ) {
		return __( 'Alt text was skipped. The image could not be read.', 'skyphusion-ai' );
	}
	if ( 'alt' === $kind && 'limit' === $reason ) {
		return __( 'Alt text was skipped. Today\'s backfill limit stopped this item.', 'skyphusion-ai' );
	}
	if ( 'alt' === $kind ) {
		return __( 'Alt text was skipped. This item has no empty field.', 'skyphusion-ai' );
	}
	if ( 'budget' === $reason ) {
		return __( 'The SEO description was skipped. The daily neuron budget stopped this item.', 'skyphusion-ai' );
	}
	if ( 'empty' === $reason ) {
		return __( 'The SEO description was skipped. The model returned no text.', 'skyphusion-ai' );
	}
	if ( 'blocked' === $reason ) {
		return __( 'The SEO description was skipped. The model could not write this item.', 'skyphusion-ai' );
	}
	if ( 'short' === $reason ) {
		return __( 'The SEO description was skipped. The page text is under 40 characters.', 'skyphusion-ai' );
	}
	if ( 'limit' === $reason ) {
		return __( 'The SEO description was skipped. Today\'s backfill limit stopped this item.', 'skyphusion-ai' );
	}
	return __( 'The SEO description was skipped. This item has no empty field.', 'skyphusion-ai' );
}

/**
 * Print the switch, the last skip, and the retry control.
 *
 * @return void
 */
function skyphusion_ai_backfill_render() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'esc_html' ) ) {
		return;
	}
	$config = skyphusion_ai_backfill_config();
	echo '<hr />';
	echo '<h2>' . esc_html__( 'Empty text', 'skyphusion-ai' ) . '</h2>';
	echo '<p>' . esc_html__( 'This job is off until you turn it on. It fills empty alt text and an empty SEO description. It does not replace text you wrote.', 'skyphusion-ai' ) . '</p>';
	echo '<p>' . esc_html__( 'One batch runs each hour until the empty fields are done or today\'s limit is reached. This limit does not stop the other tools. The site neuron budget still stops a call.', 'skyphusion-ai' ) . '</p>';
	$seo = skyphusion_ai_backfill_seo_plugin();
	if ( 'yoast' === $seo ) {
		echo '<p>' . esc_html__( 'Writes Yoast.', 'skyphusion-ai' ) . '</p>';
	} elseif ( 'rank_math' === $seo ) {
		echo '<p>' . esc_html__( 'Writes Rank Math.', 'skyphusion-ai' ) . '</p>';
	} elseif ( 'aioseo' === $seo ) {
		echo '<p>' . esc_html__( 'Writes All in One SEO.', 'skyphusion-ai' ) . '</p>';
	} elseif ( 'seopress' === $seo ) {
		echo '<p>' . esc_html__( 'Writes SEOPress.', 'skyphusion-ai' ) . '</p>';
	} else {
		echo '<p>' . esc_html__( 'No SEO plugin is active. Alt text can still be filled.', 'skyphusion-ai' ) . '</p>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="skyphusion_ai_backfill_save" />';
	if ( function_exists( 'wp_nonce_field' ) ) {
		wp_nonce_field( 'skyphusion_ai_backfill_save' );
	}
	echo '<p><label><input type="checkbox" name="skyphusion_ai_backfill_enabled" value="1"';
	if ( ! empty( $config['enabled'] ) ) {
		echo ' checked="checked"';
	}
	echo ' /> ' . esc_html__( 'Fill empty alt text and SEO descriptions.', 'skyphusion-ai' ) . '</label></p>';
	echo '<p><label>' . esc_html__( 'Daily backfill limit, in neurons.', 'skyphusion-ai' ) . ' ';
	echo '<input type="number" name="skyphusion_ai_backfill_limit" min="0" step="1" value="' . esc_attr( (string) (int) $config['limit'] ) . '" />';
	echo '</label></p>';
	if ( (int) $config['limit'] < 1 ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: neurons this job has spent today. */
				__( 'Today this job has used %d neurons. It has no limit of its own.', 'skyphusion-ai' ),
				skyphusion_ai_backfill_used( $config )
			)
		) . '</p>';
	} else {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: neurons this job has spent today, 2: daily backfill limit. */
				__( 'Today this job has used %1$d of %2$d neurons.', 'skyphusion-ai' ),
				skyphusion_ai_backfill_used( $config ),
				(int) $config['limit']
			)
		) . '</p>';
	}
	echo '<p>' . esc_html__( 'Zero means this job has no limit of its own.', 'skyphusion-ai' ) . '</p>';
	echo '<button type="submit">' . esc_html__( 'Save the empty text choice', 'skyphusion-ai' ) . '</button>';
	echo '</form>';
	if ( array() === $config['skips'] ) {
		echo '<p>' . esc_html__( 'No item is waiting.', 'skyphusion-ai' ) . '</p>';
	} else {
		echo '<ul>';
		foreach ( $config['skips'] as $row ) {
			echo '<li>' . esc_html( (string) $row['post_id'] ) . ' ' . esc_html( skyphusion_ai_backfill_skip_label( $row ) ) . '</li>';
		}
		echo '</ul>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="skyphusion_ai_backfill_retry" />';
	if ( function_exists( 'wp_nonce_field' ) ) {
		wp_nonce_field( 'skyphusion_ai_backfill_retry' );
	}
	echo '<button type="submit">' . esc_html__( 'Retry skipped items', 'skyphusion-ai' ) . '</button>';
	echo '</form>';
}

/**
 * Store the switch from the settings form.
 *
 * @return void
 */
function skyphusion_ai_backfill_handle_save() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( function_exists( 'check_admin_referer' ) ) {
		check_admin_referer( 'skyphusion_ai_backfill_save' );
	}
	$enabled = '';
	if ( isset( $_POST['skyphusion_ai_backfill_enabled'] ) ) {
		$enabled = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_backfill_enabled'] ) );
	}
	$posted = array(
		'enabled' => $enabled,
	);
	if ( isset( $_POST['skyphusion_ai_backfill_limit'] ) ) {
		$posted['limit'] = sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_backfill_limit'] ) );
	}
	skyphusion_ai_backfill_apply( $posted );
	if ( function_exists( 'wp_safe_redirect' ) ) {
		wp_safe_redirect( admin_url( 'options-general.php?page=cloudflare-ai-search' ) );
		exit;
	}
}

/**
 * Run one batch now. The hour wait does not apply. The backfill limit and the site budget still do.
 *
 * @return void
 */
function skyphusion_ai_backfill_handle_retry() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( function_exists( 'check_admin_referer' ) ) {
		check_admin_referer( 'skyphusion_ai_backfill_retry' );
	}
	skyphusion_ai_backfill_run( true );
	if ( function_exists( 'wp_safe_redirect' ) ) {
		wp_safe_redirect( admin_url( 'options-general.php?page=cloudflare-ai-search' ) );
		exit;
	}
}

/**
 * Hourly callback.
 *
 * @return void
 */
function skyphusion_ai_backfill_on_hourly() {
	skyphusion_ai_backfill_run( false );
}

/**
 * Schedule the hourly job once.
 *
 * @return void
 */
function skyphusion_ai_backfill_boot() {
	if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) ) {
		if ( ! wp_next_scheduled( 'skyphusion_ai_backfill_hourly' ) ) {
			wp_schedule_event( time() + skyphusion_ai_backfill_interval(), 'hourly', 'skyphusion_ai_backfill_hourly' );
		}
	}
}

add_action( 'init', 'skyphusion_ai_backfill_boot' );
add_action( 'skyphusion_ai_backfill_hourly', 'skyphusion_ai_backfill_on_hourly' );
add_action( 'admin_post_skyphusion_ai_backfill_save', 'skyphusion_ai_backfill_handle_save' );
add_action( 'admin_post_skyphusion_ai_backfill_retry', 'skyphusion_ai_backfill_handle_retry' );
