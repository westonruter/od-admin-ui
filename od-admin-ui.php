<?php
/**
 * Plugin Name: Optimization Detective Admin UI
 * Plugin URI: https://github.com/westonruter/od-admin-ui
 * Description: Provides an admin UI to inspect URL Metrics from the Optimization Detective plugin.
 * Requires at least: 6.5
 * Requires PHP: 7.2
 * Requires Plugins: optimization-detective
 * Version: 0.5.3
 * Author: Weston Ruter
 * Author URI: https://weston.ruter.net/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: od-admin-ui
 * Update URI: https://github.com/westonruter/od-admin-ui
 * GitHub Plugin URI: https://github.com/westonruter/od-admin-ui
 *
 * @package OptimizationDetective\AdminUi
 */

namespace OptimizationDetective\AdminUi;

use DateTime;
use DateTimeZone;
use Exception;
use OD_Tag_Visitor_Registry;
use OD_URL_Metric;
use OD_URL_Metric_Group;
use OD_URL_Metric_Group_Collection;
use OD_URL_Metrics_Post_Type;
use WP_Post;
use WP_Post_Type;
use WP_Screen;
use WP_Admin_Bar;

const POST_TYPE_SLUG = 'od_url_metrics';

const DASHICON_CLASS = 'dashicons-info-outline';

add_action(
	'registered_post_type_' . POST_TYPE_SLUG,
	static function ( string $post_type, WP_Post_Type $post_type_object ): void {
		$post_type_object->show_ui           = true;
		$post_type_object->show_in_menu      = true;
		$post_type_object->_edit_link        = 'post.php?post=%d';
		$post_type_object->cap->create_posts = 'do_not_allow';
		$post_type_object->menu_icon         = DASHICON_CLASS;
	},
	10,
	2
);

// Customize the columns that appear on the URL Metrics post list table.
add_filter(
	'manage_' . POST_TYPE_SLUG . '_posts_columns',
	static function ( $columns ) {
		unset( $columns['date'] );
		$columns['post_name'] = __( 'Slug', 'default' );
		$columns['max_size']  = __( 'Size', 'od-admin-ui' );
		$columns['modified']  = __( 'Modified', 'od-admin-ui' );
		$columns['date']      = __( 'Created', 'od-admin-ui' );
		return $columns;
	}
);

// Populate the custom columns.
add_action(
	'manage_' . POST_TYPE_SLUG . '_posts_custom_column',
	static function ( $column, $post_id ): void {
		$post = get_post( $post_id );
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
		} elseif ( 'max_size' === $column && $post instanceof WP_Post ) {
			$max_size    = 0;
			$url_metrics = OD_URL_Metrics_Post_Type::get_url_metrics_from_post( $post );
			foreach ( $url_metrics as $url_metric ) {
				$max_size = max( $max_size, strlen( (string) wp_json_encode( $url_metric ) ) );
			}
			print_url_metric_size_meter_markup( $max_size );
		}
	},
	10,
	2
);

/**
 * Print the METER markup.
 *
 * @param int $length URL Metric size.
 */
function print_url_metric_size_meter_markup( int $length ): void {
	$limit_kib   = 64;
	$limit_bytes = $limit_kib * 1024;
	printf(
		'<meter value="%s" min="0" max="%s" optimum="%s" high="%s" title="%s"></meter>',
		esc_attr( (string) $length ),
		esc_attr( (string) $limit_bytes ),
		esc_attr( (string) ( $limit_bytes * 0.25 ) ),
		esc_attr( (string) ( $limit_bytes * 0.5 ) ),
		esc_attr(
			sprintf(
				/* translators: 1: URL Metric size in bytes, 2: percentage of total budget, 3: maximum allowed in KiB */
				__( '%1$s bytes (%2$s%% of %3$s KiB limit)', 'od-admin-ui' ),
				number_format_i18n( $length ),
				ceil( ( $length / $limit_bytes ) * 100 ),
				$limit_kib
			)
		)
	);
}

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
		// TODO: Apparently these $columns can be configured to eliminate the need to add the pre_get_posts action below so that the default sort is by Modified.
		return $columns;
	}
);

// Default to sorting posts by modified.
add_action(
	'pre_get_posts',
	static function ( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$current_screen = get_current_screen();
		if ( ! ( $current_screen instanceof WP_Screen ) || 'edit' !== $current_screen->base || POST_TYPE_SLUG !== $current_screen->post_type ) {
			return;
		}

		// TODO: This is gross. There must be a better way to set the default sort via the filter above.
		if ( POST_TYPE_SLUG === $query->get( 'post_type' ) && ! isset( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query->set( 'orderby', 'modified' );
			$query->set( 'order', 'DESC' );
			$_GET['orderby'] = 'modified';
			$_GET['order']   = 'desc';
		}
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
					esc_attr( sprintf( __( 'Inspect &#8220;%s&#8221;', 'od-admin-ui' ), get_the_title( $post ) ) ),
					__( 'Inspect', 'od-admin-ui' )
				);
			}

			$actions['view'] = sprintf(
				'<a href="%s" rel="bookmark" aria-label="%s">%s</a>',
				esc_url( get_the_title( $post ) ),
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'View &#8220;%s&#8221;', 'od-admin-ui' ), get_the_title( $post ) ) ),
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

/**
 * Print styles.
 */
function print_styles(): void {
	global $typenow;
	if ( POST_TYPE_SLUG !== $typenow ) {
		return;
	}
	?>
	<style>
		.fixed .column-max_size {
			width: 10%;
		}
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
}

add_action( 'admin_head-post.php', __NAMESPACE__ . '\print_styles', 100 );
add_action( 'admin_head-edit.php', __NAMESPACE__ . '\print_styles', 100 );

/**
 * Gets device slug.
 *
 * @param OD_URL_Metric_Group $group Group.
 * @return string Slug.
 */
function get_device_slug( OD_URL_Metric_Group $group ): string {
	if ( $group->get_minimum_viewport_width() === 0 ) {
		return 'mobile';
	} elseif ( $group->get_maximum_viewport_width() === PHP_INT_MAX || $group->get_maximum_viewport_width() === null ) {
		return 'desktop';
	} elseif ( $group->get_minimum_viewport_width() >= 600 ) {
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
		return __( 'mobile', 'od-admin-ui' );
	} elseif ( $group->get_maximum_viewport_width() === PHP_INT_MAX || $group->get_maximum_viewport_width() === null ) {
		return __( 'desktop', 'od-admin-ui' );
	} elseif ( $group->get_minimum_viewport_width() >= 600 ) {
		return __( 'tablet', 'od-admin-ui' );
	} else {
		return __( 'phablet', 'od-admin-ui' );
	}
}

/**
 * Gets device emoji character.
 *
 * @param OD_URL_Metric_Group $group Group.
 * @return string Emoji.
 */
function get_device_emoji( OD_URL_Metric_Group $group ): string {
	if ( $group->get_maximum_viewport_width() === PHP_INT_MAX || $group->get_maximum_viewport_width() === null ) {
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
			__( 'Data', 'od-admin-ui' ),
			static function ( WP_Post $post ): void {
				try {
					$timezone = new DateTimeZone( get_option( 'timezone_string' ) );
				} catch ( Exception $e ) {
					$timezone = null;
				}
				$url_metrics = OD_URL_Metrics_Post_Type::get_url_metrics_from_post( $post );

				$etag                   = count( $url_metrics ) > 0 ? $url_metrics[0]->get_etag() : md5( '' );
				$url_metrics_collection = new OD_URL_Metric_Group_Collection( $url_metrics, $etag, od_get_breakpoint_max_widths(), od_get_url_metrics_breakpoint_sample_size(), od_get_url_metric_freshness_ttl() );

				$true_label  = __( 'true', 'od-admin-ui' );
				$false_label = __( 'false', 'od-admin-ui' );
				?>
				<table>
					<tr>
						<th><?php esc_html_e( 'Is every group complete:', 'od-admin-ui' ); ?></th>
						<td><?php echo esc_html( $url_metrics_collection->is_every_group_complete() ? $true_label : $false_label ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Is every group populated:', 'od-admin-ui' ); ?></th>
						<td><?php echo esc_html( $url_metrics_collection->is_every_group_populated() ? $true_label : $false_label ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Is any group populated:', 'od-admin-ui' ); ?></th>
						<td><?php echo esc_html( $url_metrics_collection->is_any_group_populated() ? $true_label : $false_label ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Common LCP element:', 'od-admin-ui' ); ?></th>
						<td>
							<?php
							$lcp_element = $url_metrics_collection->get_common_lcp_element();
							if ( null !== $lcp_element ) {
								echo '<code>' . esc_html( $lcp_element->get_xpath() ) . '</code>';
							} else {
								esc_html_e( 'none', 'od-admin-ui' );
							}
							?>
						</td>
					</tr>
				</table>

				<h1><?php esc_html_e( 'Viewport Groups', 'od-admin-ui' ); ?></h1>
				<table>
					<tr>
						<th colspan="2"></th>
						<th><?php esc_html_e( 'Min.', 'od-admin-ui' ); ?></th>
						<th><?php esc_html_e( 'Max.', 'od-admin-ui' ); ?></th>
						<th><?php esc_html_e( 'Count', 'od-admin-ui' ); ?></th>
						<th><?php esc_html_e( 'Complete', 'od-admin-ui' ); ?></th>
						<th><?php esc_html_e( 'LCP Element', 'od-admin-ui' ); ?></th>
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
								<?php echo esc_html( $group->get_maximum_viewport_width() === PHP_INT_MAX || $group->get_maximum_viewport_width() === null ? '∞' : (string) $group->get_maximum_viewport_width() ); ?>
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
									esc_html_e( 'unknown', 'od-admin-ui' );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h1><?php esc_html_e( 'URL Metrics', 'optimization-detective' ); ?></h1>
				<?php

				usort(
					$url_metrics,
					static function ( $a, $b ) {
						return $b->get_timestamp() <=> $a->get_timestamp();
					}
				);
				foreach ( $url_metrics as $url_metric ) {
					$date = DateTime::createFromFormat( 'U.u', (string) $url_metric->get_timestamp() );
					assert( $date instanceof DateTime );
					if ( $timezone instanceof DateTimeZone ) {
						$date->setTimezone( $timezone );
					}
					printf( '<details class="url-metric" open id="url-metric-%s">', esc_attr( $url_metric->get_uuid() ) );

					echo '<summary>';
					printf( '<time datetime="%s" title="%s">', esc_attr( $date->format( 'c' ) ), esc_attr( $date->format( 'Y-m-d H:i:s.u T' ) ) );
					/* translators: %s: human-readable time difference. */
					$formatted_date = sprintf( __( '%s ago', 'default' ), human_time_diff( (int) $url_metric->get_timestamp() ) );
					echo esc_html( $formatted_date );
					echo '</time>';
					echo ' | ';
					printf( '<a href="%s">%s</a>', esc_url( $url_metric->get_url() ), esc_html__( 'View', 'default' ) );
					echo ' | ';
					$length = strlen( (string) wp_json_encode( $url_metric ) );
					print_url_metric_size_meter_markup( $length );
					echo ' | ';
					$group = $url_metrics_collection->get_group_for_viewport_width( $url_metric->get_viewport_width() );
					esc_html_e( 'Viewport Group:', 'od-admin-ui' );
					echo ' ';
					if ( $group->get_minimum_viewport_width() === 0 ) {
						echo esc_html( '≤' . $group->get_maximum_viewport_width() . 'px' ); // TODO: Technically get_maximum_viewport_width could be null if there are no breakpoints, but this is unlikely.
					} elseif ( $group->get_maximum_viewport_width() === PHP_INT_MAX || $group->get_maximum_viewport_width() === null ) {
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
		$edit_link = get_edit_post_link( $post, 'raw' );
		if ( null === $edit_link ) {
			return;
		}
		wp_print_inline_script_tag(
			sprintf(
				'console.log( %s )',
				wp_json_encode(
					"[Optimization Detective] Inspect URL Metrics: $edit_link"
				),
				array( 'type' => 'module' )
			)
		);
	}
);

add_action(
	'admin_bar_menu',
	static function ( WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! od_can_optimize_response() ) {
			return;
		}

		$slug = od_get_url_metrics_slug( od_get_normalized_query_vars() );
		$post = OD_URL_Metrics_Post_Type::get_post( $slug );
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}
		$edit_link = get_edit_post_link( $post, 'raw' );
		if ( null === $edit_link ) {
			return;
		}

		$url_metrics = OD_URL_Metrics_Post_Type::get_url_metrics_from_post( $post );
		usort(
			$url_metrics,
			static function ( OD_URL_Metric $a, OD_URL_Metric $b ): int {
				return $b->get_timestamp() <=> $a->get_timestamp();
			}
		);

		// TODO: We should not have to re-construct this.
		$tag_visitor_registry = new OD_Tag_Visitor_Registry();
		do_action( 'od_register_tag_visitors', $tag_visitor_registry );
		$current_etag           = od_get_current_url_metrics_etag( $tag_visitor_registry, $GLOBALS['wp_query'], od_get_current_theme_template() );
		$url_metrics_collection = new OD_URL_Metric_Group_Collection( $url_metrics, $current_etag, od_get_breakpoint_max_widths(), od_get_url_metrics_breakpoint_sample_size(), od_get_url_metric_freshness_ttl() );

		$args = array(
			'id'    => 'od-url-metrics',
			'title' => '<span class="ab-icon dashicons ' . DASHICON_CLASS . '"></span><span class="ab-label">' . __( 'URL Metrics', 'od-admin-ui' ) . '</span>',
			'href'  => $edit_link,
		);

		$all_complete = true;

		$args['title'] .= ' ';
		foreach ( $url_metrics_collection as $group ) {
			$etags       = array_values(
				array_unique(
					array_map(
						static function ( OD_URL_Metric $url_metric ) {
							return $url_metric->get_etag();
						},
						iterator_to_array( $group )
					)
				)
			);
			$is_complete = (
				$group->is_complete()
				||
				// Handle case using the DevMode plugin when the freshness TTL is zeroed out.
				( $group->get_freshness_ttl() === 0 && $group->count() === $group->get_sample_size() && isset( $etags[0] ) && $etags[0] === $current_etag )
			);
			$all_complete = $all_complete && $is_complete;
			if ( $is_complete ) {
				$class_name = 'od-complete';
			} elseif ( $group->count() > 0 ) {
				$class_name = 'od-populated';
			} else {
				$class_name = 'od-empty';
			}
			$class_name .= sprintf( ' od-viewport-min-width-%d', $group->get_minimum_viewport_width() );

			$tooltip = get_device_label( $group ) . ': ' . $group->count() . '/' . $group->get_sample_size();
			if ( $is_complete ) {
				$tooltip .= sprintf( ', %s', __( 'complete', 'od-admin-ui' ) );
			}

			$group_url_metrics = iterator_to_array( $group );
			usort(
				$group_url_metrics,
				static function ( OD_URL_Metric $a, OD_URL_Metric $b ): int {
					return $b->get_timestamp() <=> $a->get_timestamp();
				}
			);
			if ( isset( $group_url_metrics[0] ) ) {
				/* translators: %s: human-readable time difference. */
				$tooltip .= ', ' . sprintf( __( '%s ago', 'default' ), human_time_diff( (int) $group_url_metrics[0]->get_timestamp() ) );
			}

			$args['title'] .= sprintf(
				'<span class="%s" title="%s"></span>',
				esc_attr( "od-viewport-group-indicator $class_name" ),
				esc_attr( $tooltip )
			);
		}

		if ( $all_complete ) {
			$args['meta']['title'] = __( 'Every viewport group is complete, being fully populated without any stale URL metrics.', 'od-admin-ui' );
		} elseif ( $url_metrics_collection->is_every_group_populated() ) {
			$args['meta']['title'] = __( 'Every viewport group is populated although some URL Metrics are stale or not enough samples have been collected.', 'od-admin-ui' );
		} elseif ( $url_metrics_collection->is_any_group_populated() ) {
			$args['meta']['title'] = __( 'Not every viewport group has been populated.', 'od-admin-ui' );
		} else {
			$args['meta']['title'] = __( 'No URL Metrics have been collected yet.', 'od-admin-ui' );
		}

		$wp_admin_bar->add_node( $args );
		add_action( 'wp_footer', __NAMESPACE__ . '\print_admin_bar_styles' );
	},
	100
);

/**
 * Print admin bar styles.
 */
function print_admin_bar_styles(): void {
	if ( ! is_admin_bar_showing() ) {
		return;
	}
	?>
	<style>
		#wpadminbar .od-viewport-group-indicator {
			display: inline-block;
			vertical-align: middle;
			width: 6px;
			height: 20px;
			border: solid 1px black;
			background-color: gray;
			opacity: 0.5;
		}
		#wpadminbar .od-viewport-group-indicator.od-populated {
			background-color: orange;
		}
		#wpadminbar .od-viewport-group-indicator.od-complete {
			background-color: lime;
		}

		#wpadminbar #wp-admin-bar-od-url-metrics .od-viewport-group-indicator:hover {
			opacity: 1;
			outline: solid 2px currentColor;
		}
		@media screen and (max-width: 782px) {
			#wpadminbar #wp-admin-bar-od-url-metrics .ab-icon {
				display: none;
			}
			#wpadminbar #wp-admin-bar-od-url-metrics {
				display: block;
			}
			#wpadminbar .od-viewport-group-indicator {
				height: 32px;
				width: 8px;
			}
		}
		<?php
		// TODO: Nice if we could reuse the OD_URL_Metric_Group_Collection here!
		$width_tuples = array();
		$min_width    = 0;
		foreach ( od_get_breakpoint_max_widths() as $max_width ) {
			$width_tuples[] = array( $min_width, $max_width );
			$min_width      = $max_width;
		}
		$width_tuples[] = array( $min_width, null );

		foreach ( $width_tuples as list( $viewport_min_width, $viewport_max_width ) ) {
			?>
			@media screen and <?php echo od_generate_media_query( $viewport_min_width, $viewport_max_width ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> {
				#wpadminbar #wp-admin-bar-od-url-metrics:not(:has(.od-viewport-group-indicator:hover)) .od-viewport-group-indicator.od-viewport-min-width-<?php echo $viewport_min_width; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> {
					opacity: 1;
				}
			}
			<?php
		}
		?>
	</style>
	<?php
}
