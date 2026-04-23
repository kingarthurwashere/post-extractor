/**
 * Post Extractor — tabbed settings UI.
 */
(function () {
	'use strict';

	var root = document.getElementById( 'pe-admin-root' );
	if ( ! root ) {
		return;
	}

	var tabs = root.querySelectorAll( '.pe-admin__tab .nav-tab' );
	var panels = root.querySelectorAll( '[data-pe-panel]' );
	if ( ! tabs.length || ! panels.length ) {
		return;
	}

	function activate( tabId ) {
		tabs.forEach( function ( t ) {
			var id = t.getAttribute( 'data-pe-tab' );
			var isActive = id === tabId;
			t.classList.toggle( 'nav-tab-active', isActive );
			t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
		} );
		panels.forEach( function ( p ) {
			var match = p.getAttribute( 'data-pe-panel' ) === tabId;
			p.toggleAttribute( 'hidden', ! match );
			p.setAttribute( 'aria-hidden', match ? 'false' : 'true' );
		} );
		// URL hash (optional) for support links
		if ( history.replaceState && tabId ) {
			var url = new URL( window.location.href );
			url.hash = 'pe-' + tabId;
			history.replaceState( null, '', url );
		}
	}

	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			activate( tab.getAttribute( 'data-pe-tab' ) );
		} );
		tab.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				activate( tab.getAttribute( 'data-pe-tab' ) );
			}
		} );
	} );

	// Initial: hash or first tab (overview | settings | reference)
	var initial = ( window.location.hash || '' ).replace( /^#pe-/, '' );
	if ( ! initial || ! root.querySelector( '[data-pe-panel="' + initial + '"]' ) ) {
		initial = tabs[0].getAttribute( 'data-pe-tab' ) || 'overview';
	}
	activate( initial );
} )();
