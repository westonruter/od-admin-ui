/**
 * @typedef {Object} ViewportStatus
 * @property {number}      min_width     - Min width.
 * @property {boolean}     complete      - Complete
 * @property {number}      count         - Count
 * @property {string}      device_label  - Device label.
 * @property {number}      sample_size   - Sample size.
 * @property {string|null} last_modified - Last modified label.
 */

/**
 * @param {Object}           data
 * @param {string}           data.tooltip
 * @param {string|null}      data.edit_post_link
 * @param {ViewportStatus[]} data.viewport_statuses
 */
export default function ( data ) {
	const adminBarItem = document.getElementById(
		'wp-admin-bar-od-url-metrics'
	);
	const adminBarItemLink = adminBarItem.querySelector( 'a' );
	adminBarItemLink.title = data.tooltip;
	const viewportIndicatorsContainer =
		adminBarItem.querySelector( '.od-url-metrics' );

	adminBarItem.classList.remove( 'od-url-metrics-loading' );

	for ( const viewportStatus of data.viewport_statuses ) {
		const span = document.createElement( 'span' );
		span.classList.add(
			'od-viewport-group-indicator',
			`od-viewport-min-width-${ viewportStatus.min_width }`
		);
		if ( viewportStatus.complete ) {
			span.classList.add( 'od-complete' );
		} else if ( viewportStatus.count > 0 ) {
			span.classList.add( 'od-populated' );
		} else {
			span.classList.add( 'od-empty' );
		}

		span.title = viewportStatus.device_label;
		if ( viewportStatus.complete ) {
			span.title += ', complete'; // TODO: i18n.
		}
		if ( viewportStatus.last_modified ) {
			span.title += ', ' + viewportStatus.last_modified;
		}
		viewportIndicatorsContainer.appendChild( span );
	}

	if ( data.edit_post_link ) {
		adminBarItemLink.href = data.edit_post_link;
	}
}
