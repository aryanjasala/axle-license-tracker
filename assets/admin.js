/**
 * Plugins > Licenses: cost formatting, row actions and the saved hook.
 *
 * Hooks (wp.hooks, namespace axleLicenseTracker), read when the page has
 * loaded so scripts that load later can still add to them:
 *
 * - filter axleLicenseTracker.rowActions( actions, row ): the row actions,
 *   an array of { key, label, url }. row is { id, state, risk, vendor }.
 * - filter axleLicenseTracker.costFormat( text, minor, currency ): how a cost is shown.
 * - action axleLicenseTracker.detailsSaved( component, fields ): after details were saved.
 */
( function () {
	'use strict';

	function hooks() {
		return window.wp && window.wp.hooks ? window.wp.hooks : null;
	}

	function formatCost( minor, currency, decimals ) {
		var amount = minor / Math.pow( 10, decimals );
		var config = window.axleLicenseTracker || {};
		var text;
		try {
			text = new Intl.NumberFormat( config.locale || undefined, { style: 'currency', currency: currency } ).format( amount );
		} catch ( error ) {
			text = amount.toFixed( decimals ) + ' ' + currency;
		}
		var h = hooks();
		return h ? String( h.applyFilters( 'axleLicenseTracker.costFormat', text, minor, currency ) ) : text;
	}

	function costs() {
		document.querySelectorAll( '.alc-wrap .alc-cost[data-minor]' ).forEach( function ( cell ) {
			cell.textContent = formatCost( Number( cell.getAttribute( 'data-minor' ) ), cell.getAttribute( 'data-currency' ), Number( cell.getAttribute( 'data-decimals' ) ) );
		} );
	}

	function rowActions() {
		var h = hooks();
		if ( ! h ) {
			return;
		}
		document.querySelectorAll( '.alc-table tr[data-component]' ).forEach( function ( tr ) {
			var box = tr.querySelector( '.alc-row-actions' );
			if ( ! box ) {
				return;
			}
			var links = box.querySelectorAll( 'a[data-action]' );
			var actions = Array.prototype.map.call( links, function ( link ) {
				return { key: link.getAttribute( 'data-action' ), label: link.firstChild ? link.firstChild.textContent : '', url: link.getAttribute( 'href' ), element: link };
			} );
			var row = {
				id: tr.getAttribute( 'data-component' ),
				state: tr.getAttribute( 'data-state' ),
				risk: tr.getAttribute( 'data-risk' ),
				vendor: tr.getAttribute( 'data-vendor' ),
			};
			var filtered = h.applyFilters( 'axleLicenseTracker.rowActions', actions.slice(), row );
			if ( ! Array.isArray( filtered ) ) {
				return;
			}
			var same = filtered.length === actions.length && filtered.every( function ( action, index ) {
				return action === actions[ index ];
			} );
			if ( same ) {
				return;
			}
			box.textContent = '';
			filtered.forEach( function ( action, index ) {
				if ( ! action || ! action.url || ! action.label ) {
					return;
				}
				var span = document.createElement( 'span' );
				span.className = String( action.key || 'custom' );
				var link = action.element || document.createElement( 'a' );
				if ( ! action.element ) {
					link.setAttribute( 'href', action.url );
					link.setAttribute( 'data-action', String( action.key || 'custom' ) );
					link.textContent = action.label;
				}
				span.appendChild( link );
				if ( index < filtered.length - 1 ) {
					span.appendChild( document.createTextNode( ' | ' ) );
				}
				box.appendChild( span );
			} );
		} );
	}

	function saved() {
		var h = hooks();
		var config = window.axleLicenseTracker || {};
		if ( h && config.saved ) {
			h.doAction( 'axleLicenseTracker.detailsSaved', config.saved.component, config.saved.fields );
		}
	}

	function init() {
		costs();
		rowActions();
		saved();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
