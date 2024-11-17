<?php
/**
 * Plugin Name: Optimization Detective Admin UI
 * Plugin URI: https://gist.github.com/westonruter/004094f1d49b8b98492deb3dd20bc55e
 * Description: Provides an admin UI to inspect URL Metrics from the Optimization Detective plugin.
 * Requires at least: 6.5
 * Requires PHP: 7.2
 * Requires Plugins: optimization-detective
 * Version: 0.3.0
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

use DateTime;
use DateTimeZone;
use Exception;
use OD_URL_Metric_Group;
use OD_URL_Metric_Group_Collection;
use OD_URL_Metrics_Post_Type;
use WP_Post;
use WP_Post_Type;
use WP_Screen;

const POST_TYPE_SLUG = 'od_url_metrics';

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

		$columns['post_name'] = __( 'Slug', 'default' );
		$columns['date']      = $date_column;
		$columns['modified']  = __( 'Modified Date', 'optimization-detective-admin-ui' );

		return $columns;
	}
);

// Populate the custom columns.
add_action(
	'manage_' . POST_TYPE_SLUG . '_posts_custom_column',
	static function ( $column, $post_id ): void {
		if ( 'modified' === $column ) {
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
		$columns['modified']  = 'modified';
		$columns['post_name'] = 'post_name';
		return $columns;
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

			if ( isset( $actions['edit'] ) ) {
				$actions['edit'] = sprintf(
					'<a href="%s" aria-label="%s">%s</a>',
					get_edit_post_link( $post->ID ),
					/* translators: %s: Post title. */
					esc_attr( sprintf( __( 'Inspect &#8220;%s&#8221;', 'optimization-detective-admin-ui' ), get_the_title( $post ) ) ),
					__( 'Inspect', 'optimization-detective-admin-ui' )
				);
			}

			$actions['view'] = sprintf(
				'<a href="%s" rel="bookmark" aria-label="%s">%s</a>',
				esc_url( get_the_title( $post ) ),
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'View &#8220;%s&#8221;', 'optimization-detective-admin-ui' ), get_the_title( $post ) ) ),
				__( 'View', 'default' )
			);

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

// Remove submit metabox.
add_action(
	'admin_menu',
	static function (): void {
		remove_meta_box( 'submitdiv', POST_TYPE_SLUG, 'side' );
	}
);

// Make the title read only (with a hack!).
add_action(
	'admin_head',
	static function (): void {
		$current_screen = get_current_screen();
		if ( $current_screen instanceof WP_Screen && 'post' === $current_screen->base && POST_TYPE_SLUG === $current_screen->post_type ) {
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

add_action(
	'admin_head-post.php',
	static function (): void {
		global $typenow;
		if ( POST_TYPE_SLUG !== $typenow ) {
			return;
		}
		?>
		<style>
		#titlediv #title {
			border: none;
			background-color: transparent;
			padding: 0;
			margin: 0;
			font-family: inherit;
			color: inherit;
			cursor: text;
			font-weight: bold;
		}
		.device-emoji {
			font-size: x-large;
			display: inline-block;
		}
		.device-emoji-mobile {
			font-size: large;
		}
		.device-emoji-phablet {
			font-size: x-large;
		}
		.device-emoji-tablet {
			font-size: xx-large;
			transform: rotate(90deg);
		}
		.device-emoji-desktop {
			font-size: xxx-large;
		}
		.url-metric:nth-child(odd) {
			background-color: #f2f2f2; /* Light gray for odd rows */
		}
		</style>
		<?php
	},
	100
);

/**
 * Gets device slug.
 *
 * @param OD_URL_Metric_Group $group Group.
 * @return string Slug.
 */
function get_device_slug( OD_URL_Metric_Group $group ): string {
	if ( $group->get_minimum_viewport_width() === 0 ) {
		return 'mobile';
	} elseif ( $group->get_maximum_viewport_width() === PHP_INT_MAX ) {
		return 'desktop';
	} elseif ( $group->get_minimum_viewport_width() > 600 ) {
		return 'tablet';
	} else {
		return 'phablet';
	}
}

/**
 * Gets device label.
 *
 * @param OD_URL_Metric_Group $group Group.
 * @return string Label.
 */
function get_device_label( OD_URL_Metric_Group $group ): string {
	if ( $group->get_minimum_viewport_width() === 0 ) {
		return __( 'mobile', 'optimization-detective-admin-ui' );
	} elseif ( $group->get_maximum_viewport_width() === PHP_INT_MAX ) {
		return __( 'desktop', 'optimization-detective-admin-ui' );
	} elseif ( $group->get_minimum_viewport_width() > 600 ) {
		return __( 'tablet', 'optimization-detective-admin-ui' );
	} else {
		return __( 'phablet', 'optimization-detective-admin-ui' );
	}
}

/**
 * Gets device emoji character.
 *
 * @param OD_URL_Metric_Group $group Group.
 * @return string Emoji.
 */
function get_device_emoji( OD_URL_Metric_Group $group ): string {
	if ( $group->get_maximum_viewport_width() === PHP_INT_MAX ) {
		return '💻';
	} else {
		return '📱';
	}
}

/**
 * Gets device emoji markup.
 *
 * @param OD_URL_Metric_Group $group Group.
 */
function print_device_emoji_markup( OD_URL_Metric_Group $group ): void {
	printf( ' <span class="device-emoji device-emoji-%s">%s</span>', esc_attr( get_device_slug( $group ) ), esc_html( get_device_emoji( $group ) ) );
}

// Add metabox show the contents of the URL Metrics.
add_action(
	'add_meta_boxes',
	static function (): void {
		add_meta_box(
			'od_url_metrics_big_metabox',
			__( 'Data', 'optimization-detective-admin-ui' ),
			static function ( WP_Post $post ): void {
				try {
					$timezone = new DateTimeZone( get_option( 'timezone_string' ) );
				} catch ( Exception $e ) {
					$timezone = null;
				}
				$url_metrics = OD_URL_Metrics_Post_Type::get_url_metrics_from_post( $post );
				usort( $url_metrics, static function ( $a, $b ) {
					return $b->get_timestamp() <=> $a->get_timestamp();
				} );

				$url_metrics_collection = new OD_URL_Metric_Group_Collection( $url_metrics, od_get_breakpoint_max_widths(), od_get_url_metrics_breakpoint_sample_size(), od_get_url_metric_freshness_ttl() );

				$true_label  = __( 'true', 'optimization-detective-admin-ui' );
				$false_label = __( 'false', 'optimization-detective-admin-ui' );
				?>
				<table>
					<tr>
						<th><?php esc_html_e( 'Is every group complete:', 'optimization-detective-admin-ui' ); ?></th>
						<td><?php echo esc_html( $url_metrics_collection->is_every_group_complete() ? $true_label : $false_label ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Is every group populated:', 'optimization-detective-admin-ui' ); ?></th>
						<td><?php echo esc_html( $url_metrics_collection->is_every_group_populated() ? $true_label : $false_label ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Is any group populated:', 'optimization-detective-admin-ui' ); ?></th>
						<td><?php echo esc_html( $url_metrics_collection->is_any_group_populated() ? $true_label : $false_label ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Common LCP element:', 'optimization-detective-admin-ui' ); ?></th>
						<td>
							<?php
							$lcp_element = $url_metrics_collection->get_common_lcp_element();
							if ( null !== $lcp_element ) {
								echo '<code>' . esc_html( $lcp_element->get_xpath() ) . '</code>';
							} else {
								esc_html_e( 'none', 'optimization-detective-admin-ui' );
							}
							?>
						</td>
					</tr>
				</table>

				<h1><?php esc_html_e( 'Viewport Groups', 'optimization-detective-admin-ui' ); ?></h1>
				<table>
					<tr>
						<th colspan="2"></th>
						<th><?php esc_html_e( 'Min.', 'optimization-detective-admin-ui' ); ?></th>
						<th><?php esc_html_e( 'Max.', 'optimization-detective-admin-ui' ); ?></th>
						<th><?php esc_html_e( 'Count', 'optimization-detective-admin-ui' ); ?></th>
						<th><?php esc_html_e( 'Complete', 'optimization-detective-admin-ui' ); ?></th>
						<th><?php esc_html_e( 'LCP Element', 'optimization-detective-admin-ui' ); ?></th>
					</tr>
					<?php foreach ( $url_metrics_collection as $group ) : ?>
						<tr>
							<th>
								<?php echo esc_html( ucfirst( get_device_label( $group ) ) ); ?>
							</th>
							<td>
								<?php print_device_emoji_markup( $group ); ?>
							</td>
							<td>
								<?php echo esc_html( (string) $group->get_minimum_viewport_width() ); ?>
							</td>
							<td>
								<?php echo esc_html( $group->get_maximum_viewport_width() === PHP_INT_MAX ? '∞' : (string) $group->get_maximum_viewport_width() ); ?>
							</td>
							<td>
								<?php echo esc_html( (string) $group->count() ); ?>
							</td>
							<td>
								<?php echo esc_html( $group->is_complete() ? $true_label : $false_label ); ?>
							</td>
							<td>
								<?php
								$lcp_element = $group->get_lcp_element();
								if ( null !== $lcp_element ) {
									echo '<code>' . esc_html( $lcp_element->get_xpath() ) . '</code>';
								} else {
									esc_html_e( 'unknown', 'optimization-detective-admin-ui' );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h1><?php esc_html_e( 'URL Metrics', 'optimization-detective' ); ?></h1>
				<?php

				foreach ( $url_metrics as $url_metric ) {
					$date = DateTime::createFromFormat( 'U.u', (string) $url_metric->get_timestamp() );
					assert( $date instanceof DateTime );
					if ( $timezone instanceof DateTimeZone ) {
						$date->setTimezone( $timezone );
					}
					printf( '<details class="url-metric" open id="url-metric-%s">', esc_attr( $url_metric->get_uuid() ) );

					echo '<summary>';
					printf( '<time datetime="%s" title="%s">', esc_attr( $date->format( 'c' ) ), esc_attr( $date->format( 'Y-m-d H:i:s.u T' ) ) );
					// translators: %s: human-readable time difference.
					$formatted_date = sprintf( __( '%s ago', 'default' ), human_time_diff( (int) $url_metric->get_timestamp() ) );
					echo esc_html( $formatted_date );
					echo '</time>';
					echo ' | ';
					printf( '<a href="%s" target="_blank">%s</a>', esc_url( $url_metric->get_url() ), esc_html__( 'View', 'default' ) );
					echo ' | ';
					$group = $url_metrics_collection->get_group_for_viewport_width( $url_metric->get_viewport_width() );
					esc_html_e( 'Viewport Group:', 'optimization-detective-admin-ui' );
					echo ' ';
					if ( $group->get_minimum_viewport_width() === 0 ) {
						echo esc_html( '≤' . $group->get_maximum_viewport_width() . 'px' );
					} elseif ( $group->get_maximum_viewport_width() === PHP_INT_MAX ) {
						echo esc_html( '≥' . $group->get_minimum_viewport_width() . 'px' );
					} else {
						echo esc_html( $group->get_minimum_viewport_width() . 'px – ' . $group->get_maximum_viewport_width() . 'px' );
					}

					echo esc_html( ' (' . get_device_label( $group ) . ')' );
					print_device_emoji_markup( $group );

					echo '</summary>';
					echo '<pre style="overflow-x: auto;">';
					echo esc_html( (string) wp_json_encode( $url_metric->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
					echo '</pre>';
					echo '</details>';
				}
			},
			POST_TYPE_SLUG,
			'normal',
			'high'
		);
	}
);

// Add a link to the edit post link to the console (if WP_DEBUG is enabled).
add_action(
	'wp_print_footer_scripts',
	static function (): void {
		if ( ! od_can_optimize_response() || ! WP_DEBUG ) {
			return;
		}
		$slug = od_get_url_metrics_slug( od_get_normalized_query_vars() );
		$post = OD_URL_Metrics_Post_Type::get_post( $slug );
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}
		?>
		<script type="module">
			console.log( '[Optimization Detective] Inspect URL Metrics: ' + <?php echo wp_json_encode( get_edit_post_link( $post, 'raw' ) ); ?> );
		</script>
		<?php
	}
);
