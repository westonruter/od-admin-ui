<?php
/**
 * Plugin Name: Optimization Detective Admin UI
 * Plugin URI: https://gist.github.com/westonruter/004094f1d49b8b98492deb3dd20bc55e
 * Description: Provides an admin UI to inspect URL Metrics.
 * Requires at least: 6.5
 * Requires PHP: 7.2
 * Version: 0.1.1
 * Author: Weston Ruter
 * Author URI: https://weston.ruter.net/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: optimization-detective-admin-ui
 * Update URI: https://gist.github.com/westonruter/004094f1d49b8b98492deb3dd20bc55e
 * Gist Plugin URI: https://gist.github.com/westonruter/004094f1d49b8b98492deb3dd20bc55e
 *
 * @package OptimizationDetective\AdminUi
 */

namespace OptimizationDetective\AdminUi;

const POST_TYPE_SLUG = 'od_url_metrics';

use WP_Post_Type;
use WP_Post;
use OD_URL_Metrics_Post_Type;
use DateTime;
use DateTimeZone;
use Exception;

add_action(
	'registered_post_type_' . POST_TYPE_SLUG,
	static function ( string $post_type, WP_Post_Type $post_type_object ): void {
		$post_type_object->show_ui           = true;
		$post_type_object->show_in_menu      = true;
		$post_type_object->_edit_link        = 'post.php?post=%d';
		$post_type_object->cap->create_posts = 'do_not_allow';
	},
	10,
	2
);

// Customize the columns that appear on the URL Metrics post list table.
add_filter(
	'manage_' . POST_TYPE_SLUG . '_posts_columns',
	static function ( $columns ) {
		$date_column = $columns['date'];
		unset( $columns['date'] );

		$columns['post_name']     = __( 'Slug', 'default' );
		$columns['date']          = $date_column;
		$columns['modified_date'] = __( 'Modified Date', 'optimization-detective-admin-ui' );

		return $columns;
	}
);

// Populate the custom columns.
add_action(
	'manage_' . POST_TYPE_SLUG . '_posts_custom_column',
	static function ( $column, $post_id ): void {
		if ( 'modified_date' === $column ) {
			echo esc_html(
				sprintf(
					/* translators: 1: Post date, 2: Post time. */
					__( '%1$s at %2$s', 'default' ),
					/* translators: Post date format. See https://www.php.net/manual/datetime.format.php */
					get_the_modified_time( __( 'Y/m/d', 'default' ), $post_id ),
					/* translators: Post time format. See https://www.php.net/manual/datetime.format.php */
					get_the_modified_time( __( 'g:i a', 'default' ), $post_id )
				)
			);
		} elseif ( 'post_name' === $column ) {
			echo '<code>' . esc_html( get_post_field( 'post_name', $post_id ) ) . '</code>';
		}
	},
	10,
	2
);

// Hide "Published" from the Date column since URL Metrics only ever have this post status.
add_filter(
	'post_date_column_status',
	static function ( $status, $post ) {
		if ( get_post_type( $post ) === POST_TYPE_SLUG ) {
			$status = '';
		}

		return $status;
	},
	10,
	2
);

// Enable sorting by additional columns.
add_filter(
	'manage_edit-' . POST_TYPE_SLUG . '_sortable_columns',
	static function ( array $columns ): array {
		$columns['modified_date'] = 'modified_date';
		return $columns;
	}
);

// Enable filtering by custom columns.
add_filter(
	'request',
	static function ( $vars ): array {
		if ( ! is_admin() || ! isset( $vars['order'], $vars['orderby'] ) ) {
			return $vars;
		}

		if (
			'modified_date' === $vars['orderby']
			&&
			in_array( $vars['order'], array( 'asc', 'desc' ), true )
		) {
			$vars = array_merge(
				$vars,
				array(
					'orderby' => 'modified',
					'order'   => $vars['order'],
				)
			);
		}

		return $vars;
	}
);

// Show "View URL Metrics" instead of "Edit Post" on the edit post screen.
add_filter(
	'post_type_labels_' . POST_TYPE_SLUG,
	static function ( $labels ) {
		$labels->edit_item = __( 'View URL Metrics', 'optimization-detective' );
		return $labels;
	}
);

// Disable quick edit for the URL Metrics post type.
add_filter(
	'quick_edit_enabled_for_post_type',
	static function ( $enabled, $post_type ) {
		if ( POST_TYPE_SLUG === $post_type ) {
			$enabled = false;
		}
		return $enabled;
	},
	10,
	2
);

// Disable bulk edit for the URL Metrics post type.
add_filter( 'bulk_actions-edit-' . POST_TYPE_SLUG, '__return_empty_array' );

// Replace the trash row action with the delete post row action (even though trash was not enabled for the post type?).
add_filter(
	'post_row_actions',
	static function ( array $actions, WP_Post $post ): array {
		if ( POST_TYPE_SLUG === $post->post_type ) {
			unset( $actions['trash'] ); // Remove the default Trash link.

			// Check if the user has the capability to delete the post.
			if ( current_user_can( 'delete_post', $post->ID ) ) {
				$actions['delete'] = sprintf(
					'<a href="%s" class="submitdelete" aria-label="%s">%s</a>',
					get_delete_post_link( $post->ID, '', true ),
					/* translators: %s: Post title. */
					esc_attr( sprintf( __( 'Delete &#8220;%s&#8221; permanently', 'default' ), get_the_title( $post ) ) ),
					__( 'Delete Permanently', 'default' )
				);
			}
		}
		return $actions;
	},
	10,
	2
);

// Remove the submit metabox.
add_action(
	'admin_menu',
	static function (): void {
		remove_meta_box( 'submitdiv', 'od_url_metrics', 'side' );
	}
);

// Make the title read only (with a hack!).
add_action(
	'admin_head',
	static function (): void {
		$current_screen = get_current_screen();
		if ( $current_screen && 'post' === $current_screen->base && 'od_url_metrics' === $current_screen->post_type ) {
			?>
			<script>
				jQuery(document).ready(function ($) {
					$('#title').prop('readonly', true);
				});
			</script>
			<?php
		}
	}
);

// Add metabox show the contents of the URL Metrics.
add_action(
	'add_meta_boxes',
	static function (): void {
		add_meta_box(
			'od_url_metrics_big_metabox',
			__( 'URL Metrics', 'optimization-detective' ),
			static function ( WP_Post $post ): void {
				if ( ! class_exists( 'OD_URL_Metrics_Post_Type' ) ) {
					return;
				}

				try {
					$timezone = new DateTimeZone( get_option( 'timezone_string' ) );
				} catch ( Exception $e ) {
					$timezone = null;
				}
				$url_metrics = OD_URL_Metrics_Post_Type::get_url_metrics_from_post( $post );

				foreach ( $url_metrics as $url_metric ) {
					/** @var DateTime $date */
					$date = DateTime::createFromFormat( 'U.u', $url_metric->get_timestamp() );
					$date->setTimezone( $timezone );
					echo '<details open>';

					echo '<summary>';
					printf( '<time datetime="%s" title="%s">', esc_attr( $date->format( 'c' ) ), esc_attr( $date->format( 'Y-m-d H:i:s.u T' ) ) );
					// translators: %s: human-readable time difference.
					$formatted_date = sprintf( __( '%s ago', 'default' ), human_time_diff( $url_metric->get_timestamp() ) );
					echo esc_html( $formatted_date );
					echo '</time>';
					echo ' | ';
					printf( '<a href="%s" target="_blank">%s</a>', esc_url( $url_metric->get_url() ), __( 'View', 'default' ) );
					echo '</summary>';
					echo '<pre style="overflow-x: auto;">';
					echo esc_html( wp_json_encode( $url_metric->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
					echo '</pre>';
					echo '</details>';
				}
			},
			'od_url_metrics',
			'normal',
			'high'
		);
	}
);
