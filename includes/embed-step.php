<?php
/**
 * Embed the selection in small steps.
 *
 * One request used to embed every chosen post. On a large store that
 * does not fit in one request, and loading every post costs memory. A
 * step now embeds at most one batch. A cursor, the last post id done,
 * is saved after each post. The next step starts after it. A step that
 * stops part-way loses at most the post it was on. Each post saves its
 * own hash right after its own upsert.
 *
 * The admin button, WP-CLI, the REST route, and the cron event call the
 * same step.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option that holds the cursor.
 *
 * @return string
 */
function skyphusion_ai_embed_cursor_name() {
	return 'skyphusion_ai_embed_cursor';
}

/**
 * Cron hook for the next step.
 *
 * @return string
 */
function skyphusion_ai_embed_step_hook() {
	return 'skyphusion_ai_embed_step_event';
}

/**
 * Posts in one step when nothing else is saved.
 *
 * @return int
 */
function skyphusion_ai_embed_batch_default() {
	return 25;
}

/**
 * Batch size from one to two hundred.
 *
 * @param mixed $value Raw size.
 * @return int
 */
function skyphusion_ai_embed_batch_size( $value ) {
	if ( ! is_numeric( $value ) ) {
		return skyphusion_ai_embed_batch_default();
	}
	$size = (int) $value;
	if ( 1 > $size ) {
		return skyphusion_ai_embed_batch_default();
	}
	if ( 200 < $size ) {
		return 200;
	}
	return $size;
}

/**
 * Saved cursor, with defaults filled in.
 *
 * The after key is the last post id done. The active key is 1 while a pass runs.
 *
 * @return array{after: int, batch: int, active: int, seen: int, embedded: int, state: string}
 */
function skyphusion_ai_embed_cursor() {
	$stored = array();
	if ( function_exists( 'get_option' ) ) {
		$stored = get_option( skyphusion_ai_embed_cursor_name(), array() );
	}
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	$state = 'idle';
	if ( isset( $stored['state'] ) && in_array( (string) $stored['state'], array( 'idle', 'running', 'done', 'limit', 'blocked' ), true ) ) {
		$state = (string) $stored['state'];
	}
	return array(
		'after'    => isset( $stored['after'] ) ? max( 0, (int) $stored['after'] ) : 0,
		'batch'    => skyphusion_ai_embed_batch_size( isset( $stored['batch'] ) ? $stored['batch'] : null ),
		'active'   => empty( $stored['active'] ) ? 0 : 1,
		'seen'     => isset( $stored['seen'] ) ? max( 0, (int) $stored['seen'] ) : 0,
		'embedded' => isset( $stored['embedded'] ) ? max( 0, (int) $stored['embedded'] ) : 0,
		'state'    => $state,
	);
}

/**
 * Save the cursor.
 *
 * @param array<string, mixed> $cursor Cursor.
 * @return void
 */
function skyphusion_ai_embed_cursor_save( $cursor ) {
	if ( function_exists( 'update_option' ) ) {
		update_option( skyphusion_ai_embed_cursor_name(), $cursor, false );
	}
}

/**
 * Start a new pass from the first post.
 *
 * @param int $batch Batch size, or zero to keep the saved size.
 * @return array<string, mixed>
 */
function skyphusion_ai_embed_cursor_start( $batch = 0 ) {
	$cursor             = skyphusion_ai_embed_cursor();
	$cursor['after']    = 0;
	$cursor['active']   = 1;
	$cursor['seen']     = 0;
	$cursor['embedded'] = 0;
	$cursor['state']    = 'running';
	if ( 0 < (int) $batch ) {
		$cursor['batch'] = skyphusion_ai_embed_batch_size( $batch );
	}
	skyphusion_ai_embed_cursor_save( $cursor );
	return $cursor;
}

/**
 * Post ids a run considers, lowest first.
 *
 * Ids only, so a large store stays cheap. A test may set
 * skyphusion_ai_embed_posts.
 *
 * @return array<int, int>
 */
function skyphusion_ai_embed_ids() {
	$ids = array();
	if ( isset( $GLOBALS['skyphusion_ai_embed_posts'] ) && is_array( $GLOBALS['skyphusion_ai_embed_posts'] ) ) {
		foreach ( $GLOBALS['skyphusion_ai_embed_posts'] as $post ) {
			if ( is_array( $post ) && isset( $post['ID'] ) && 0 < (int) $post['ID'] ) {
				$ids[] = (int) $post['ID'];
			}
		}
	} elseif ( function_exists( 'get_posts' ) ) {
		$args = skyphusion_ai_embed_query_args( skyphusion_ai_embed_config() );
		if ( array() === $args['post_type'] ) {
			return array();
		}
		$args['fields']        = 'ids';
		$args['orderby']       = 'ID';
		$args['order']         = 'ASC';
		$args['no_found_rows'] = true;
		$found                 = get_posts( $args );
		if ( is_array( $found ) ) {
			foreach ( $found as $id ) {
				if ( 0 < (int) $id ) {
					$ids[] = (int) $id;
				}
			}
		}
	}
	$ids = array_values( array_unique( $ids ) );
	sort( $ids );
	return $ids;
}

/**
 * Load the posts for one step, keyed by id.
 *
 * @param array<int, int> $ids Post ids.
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_embed_load_page( $ids ) {
	$rows = array();
	if ( array() === $ids ) {
		return $rows;
	}
	if ( isset( $GLOBALS['skyphusion_ai_embed_posts'] ) && is_array( $GLOBALS['skyphusion_ai_embed_posts'] ) ) {
		foreach ( $GLOBALS['skyphusion_ai_embed_posts'] as $post ) {
			if ( is_array( $post ) && isset( $post['ID'] ) && in_array( (int) $post['ID'], $ids, true ) ) {
				$rows[ (int) $post['ID'] ] = $post;
			}
		}
		return $rows;
	}
	if ( ! function_exists( 'get_posts' ) ) {
		return $rows;
	}
	$args                = skyphusion_ai_embed_query_args( skyphusion_ai_embed_config() );
	$args['post__in']    = $ids;
	$args['orderby']     = 'post__in';
	$args['numberposts'] = count( $ids );
	$found               = get_posts( $args );
	if ( ! is_array( $found ) ) {
		return $rows;
	}
	foreach ( $found as $post ) {
		if ( is_object( $post ) ) {
			$post = get_object_vars( $post );
		}
		if ( is_array( $post ) && isset( $post['ID'] ) ) {
			$rows[ (int) $post['ID'] ] = $post;
		}
	}
	return $rows;
}

/**
 * Embed at most one batch of posts after the cursor.
 *
 * The cursor moves after each post. A post the model cannot store is
 * marked failed and the step moves on; retry sends it again. The daily
 * embeddings limit stops the step on that post, and the next step
 * starts there.
 *
 * @param int           $batch Batch size, or zero for the saved size.
 * @param callable|null $sync  Stores one post. Tests pass a stand-in.
 * @return array{state: string, processed: int, embedded: int, after: int, remaining: int, batch: int}
 */
function skyphusion_ai_embed_step( $batch = 0, $sync = null ) {
	if ( null === $sync ) {
		$sync = 'skyphusion_ai_embed_sync_post';
	}
	$cursor = skyphusion_ai_embed_cursor();
	$size   = 0 < (int) $batch ? skyphusion_ai_embed_batch_size( $batch ) : (int) $cursor['batch'];
	$result = array(
		'state'     => 'blocked',
		'processed' => 0,
		'embedded'  => 0,
		'after'     => (int) $cursor['after'],
		'remaining' => 0,
		'batch'     => $size,
	);

	$ready = skyphusion_ai_embed_ensure( skyphusion_ai_embed_config() );
	if ( 'ready' !== $ready['state'] ) {
		$cursor['state'] = 'blocked';
		skyphusion_ai_embed_cursor_save( $cursor );
		return $result;
	}
	$ids  = skyphusion_ai_embed_ids();
	$todo = array();
	foreach ( $ids as $id ) {
		if ( $id > (int) $cursor['after'] ) {
			$todo[] = $id;
			if ( count( $todo ) >= $size ) {
				break;
			}
		}
	}
	$posts = skyphusion_ai_embed_load_page( $todo );
	foreach ( $todo as $id ) {
		if ( isset( $posts[ $id ] ) ) {
			$done  = call_user_func( $sync, $posts[ $id ] );
			$state = is_array( $done ) && isset( $done['state'] ) ? (string) $done['state'] : '';
			if ( 'limit' === $state ) {
				$cursor['state'] = 'limit';
				skyphusion_ai_embed_cursor_save( $cursor );
				$result['state']     = 'limit';
				$result['after']     = (int) $cursor['after'];
				$result['remaining'] = skyphusion_ai_embed_left( $ids, (int) $cursor['after'] );
				return $result;
			}
			if ( 'embedded' === $state ) {
				++$result['embedded'];
				++$cursor['embedded'];
			}
		}
		++$result['processed'];
		++$cursor['seen'];
		$cursor['after']  = $id;
		$cursor['active'] = 1;
		$cursor['state']  = 'running';
		skyphusion_ai_embed_cursor_save( $cursor );
	}
	$result['after']     = (int) $cursor['after'];
	$result['remaining'] = skyphusion_ai_embed_left( $ids, (int) $cursor['after'] );
	if ( 0 === $result['remaining'] ) {
		$cursor['after']  = 0;
		$cursor['active'] = 0;
		$cursor['state']  = 'done';
		skyphusion_ai_embed_cursor_save( $cursor );
		$result['state'] = 'done';
		return $result;
	}
	$result['state'] = 'step';
	return $result;
}

/**
 * Ids still after the cursor.
 *
 * @param array<int, int> $ids   Sorted ids.
 * @param int             $after Last id done.
 * @return int
 */
function skyphusion_ai_embed_left( $ids, $after ) {
	$left = 0;
	foreach ( $ids as $id ) {
		if ( $id > $after ) {
			++$left;
		}
	}
	return $left;
}

/**
 * Queue the next step while a pass runs.
 *
 * A pass stopped by the daily limit waits an hour. Otherwise the next
 * step runs in about a minute.
 *
 * @param string $state State of the step that just ran.
 * @return void
 */
function skyphusion_ai_embed_schedule( $state ) {
	if ( 'step' !== $state && 'limit' !== $state ) {
		return;
	}
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
		return;
	}
	if ( wp_next_scheduled( skyphusion_ai_embed_step_hook() ) ) {
		return;
	}
	$wait = 'limit' === $state ? 3600 : 60;
	wp_schedule_single_event( time() + $wait, skyphusion_ai_embed_step_hook() );
}

/**
 * Cron callback. One step, then queue the next.
 *
 * A pass stops while embeddings are off.
 *
 * @return void
 */
function skyphusion_ai_embed_on_step_event() {
	$cursor = skyphusion_ai_embed_cursor();
	if ( empty( $cursor['active'] ) ) {
		return;
	}
	if ( ! skyphusion_ai_embed_on( skyphusion_ai_embed_config() ) ) {
		return;
	}
	$result = skyphusion_ai_embed_step();
	skyphusion_ai_embed_schedule( $result['state'] );
}

/**
 * Resume the pass, or start one, then run one step.
 *
 * The admin button and the REST route use this.
 *
 * @param int $batch Batch size, or zero for the saved size.
 * @return array<string, mixed>
 */
function skyphusion_ai_embed_step_now( $batch = 0 ) {
	$cursor = skyphusion_ai_embed_cursor();
	if ( empty( $cursor['active'] ) ) {
		skyphusion_ai_embed_cursor_start( $batch );
	}
	$result = skyphusion_ai_embed_step( $batch );
	skyphusion_ai_embed_schedule( $result['state'] );
	return $result;
}

/**
 * Register the step route. Only an admin can call it.
 *
 * POST /wp-json/skyphusion-ai/v1/embed/step runs one step and returns
 * where it stopped. A site with no WP-CLI can call it in a loop until
 * the state is done.
 *
 * @return void
 */
function skyphusion_ai_embed_register_rest() {
	if ( ! function_exists( 'register_rest_route' ) ) {
		return;
	}
	register_rest_route(
		'skyphusion-ai/v1',
		'/embed/step',
		array(
			'methods'             => 'POST',
			'callback'            => 'skyphusion_ai_embed_rest_step',
			'permission_callback' => 'skyphusion_ai_embed_admin_allowed',
		)
	);
}

/**
 * REST callback for one step.
 *
 * @param mixed $request REST request.
 * @return array<string, mixed>
 */
function skyphusion_ai_embed_rest_step( $request ) {
	$batch = 0;
	if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
		$batch = (int) $request->get_param( 'batch' );
	}
	return skyphusion_ai_embed_step_now( $batch );
}

add_action( 'rest_api_init', 'skyphusion_ai_embed_register_rest' );
add_action( skyphusion_ai_embed_step_hook(), 'skyphusion_ai_embed_on_step_event' );

/**
 * Say how far the current pass is.
 *
 * @return void
 */
function skyphusion_ai_embed_render_progress() {
	if ( ! function_exists( 'esc_html' ) ) {
		return;
	}
	$cursor = skyphusion_ai_embed_cursor();
	if ( empty( $cursor['active'] ) ) {
		return;
	}
	if ( 'limit' === $cursor['state'] ) {
		echo '<p>' . esc_html__( 'The run stopped at the daily embeddings limit. It starts again later.', 'skyphusion-ai' ) . '</p>';
		return;
	}
	/* translators: 1: number of posts done, 2: posts in one step. */
	$sentence = __( 'A run is in progress. %1$d posts are done. Each step does %2$d posts. The next step runs in the background.', 'skyphusion-ai' );
	echo '<p>' . esc_html( sprintf( $sentence, (int) $cursor['seen'], (int) $cursor['batch'] ) ) . '</p>';
}
