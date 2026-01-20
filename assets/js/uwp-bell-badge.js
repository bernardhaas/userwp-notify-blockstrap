( function ( $ ) {
	'use strict';

	/**
	 * Initialize the bell badge for the BlockStrap nav item.
	 *
	 * CONTEXT:
	 * ========
	 * The UsersWP Notifications plugin normally injects the badge via the
	 * 'uwp_setup_nav_menu_item' filter, which only fires for classic menus
	 * rendered via wp_nav_menu() walker. BlockStrap's block navigation bypasses
	 * that filter entirely, so we inject the badge client-side instead.
	 *
	 * This approach:
	 * - Works with both classic and block navigation
	 * - Avoids shortcode rendering issues in nav templates
	 * - Uses a lightweight REST endpoint for the count
	 * - Requires the nav link to have class 'uwp-bell-link' (set in Site Editor)
	 */
	function initBellBadge() {
		if ( typeof uwpBellBadge === 'undefined' ) {
			return;
		}

		var $link = $( '.uwp-bell-link' ).first();

		if ( ! $link.length ) {
			return;
		}

		$link.addClass( 'uwp-bell-link-initialized' );

		var $badge = $link.find( '.uwp-bell-badge' ).first();

		if ( ! $badge.length ) {
			$badge = $( '<span>', {
				'class': 'uwp-bell-badge',
				text: '',
			} );
			$link.append( $badge );
		}

		$badge.hide();

		fetchUnreadCount( $badge );
	}

	/**
	 * Fetch unread count from REST API.
	 *
	 * @param {jQuery} $badge Badge element.
	 */
	function fetchUnreadCount( $badge ) {
		var endpoint = ( uwpBellBadge.root || '' ) + 'unread-count';

		$.ajax( {
			url: endpoint,
			method: 'GET',
			dataType: 'json',
			beforeSend: function ( xhr ) {
				if ( uwpBellBadge.nonce ) {
					xhr.setRequestHeader( 'X-WP-Nonce', uwpBellBadge.nonce );
				}
			},
		} )
			.done( function ( response ) {
				if ( ! response || typeof response.count === 'undefined' ) {
					return;
				}

				var count = parseInt( response.count, 10 );

				if ( isNaN( count ) || count <= 0 ) {
					$badge.text( '' ).hide();
					return;
				}

				$badge.text( count ).show();
			} )
			.fail( function () {
				// Silently ignore errors – badge will stay hidden.
			} );
	}

	$( document ).ready( initBellBadge );
} )( jQuery );
