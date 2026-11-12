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
 * - filter axleLicenseTracker.announce( text, kind ): the sentence read out to screen readers
 *   after an action (kind is notice, busy or copied).
 * - action axleLicenseTracker.confirmOpened( id ) and axleLicenseTracker.confirmClosed( id ):
 *   when an in-place confirm step opens or closes.
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

	function text( key, fallback ) {
		var config = window.axleLicenseTracker || {};
		return config.i18n && config.i18n[ key ] ? config.i18n[ key ] : fallback;
	}

	function announce( message, kind ) {
		if ( ! message || ! window.wp || ! window.wp.a11y ) {
			return;
		}
		var h = hooks();
		var final = h ? String( h.applyFilters( 'axleLicenseTracker.announce', message, kind ) ) : message;
		window.wp.a11y.speak( final, 'assertive' );
	}

	/** Reads the notice that says what just happened. */
	function notices() {
		var notice = document.querySelector( '.alc-notice' );
		if ( notice ) {
			announce( notice.textContent.replace( /\s+/g, ' ' ).trim(), 'notice' );
		}
	}

	/**
	 * In-place confirm steps. Without JavaScript the step is simply shown and
	 * the trigger hidden; here the step starts closed and opens on the trigger.
	 */
	function confirms() {
		var h = hooks();
		document.querySelectorAll( '.alc-confirm' ).forEach( function ( box ) {
			var trigger = document.querySelector( '[data-alc-confirm="' + box.id + '"]' );
			var cancel = box.querySelector( '[data-alc-confirm-cancel]' );
			if ( ! trigger || ! cancel ) {
				return;
			}
			trigger.hidden = false;
			cancel.hidden = false;
			box.hidden = true;

			trigger.addEventListener( 'click', function () {
				box.hidden = false;
				trigger.setAttribute( 'aria-expanded', 'true' );
				box.focus();
				if ( h ) {
					h.doAction( 'axleLicenseTracker.confirmOpened', box.id );
				}
			} );
			cancel.addEventListener( 'click', function () {
				box.hidden = true;
				trigger.setAttribute( 'aria-expanded', 'false' );
				trigger.focus();
				if ( h ) {
					h.doAction( 'axleLicenseTracker.confirmClosed', box.id );
				}
			} );
			box.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key ) {
					cancel.click();
				}
			} );
		} );
	}

	/** A form that fetches other sites takes a while: show it is working. */
	function busyForms() {
		document.querySelectorAll( 'form[data-alc-busy]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function () {
				var spinner = form.querySelector( '.spinner' );
				if ( spinner ) {
					spinner.classList.add( 'is-active' );
				}
				form.querySelectorAll( 'button[type="submit"]' ).forEach( function ( button ) {
					button.setAttribute( 'aria-busy', 'true' );
					// Disabling at once would drop the button from the submission in some browsers.
					window.setTimeout( function () {
						button.disabled = true;
					}, 0 );
				} );
				announce( form.getAttribute( 'data-alc-busy' ), 'busy' );
			} );
		} );
	}

	/** Copy buttons for the calendar link. */
	function copyButtons() {
		document.querySelectorAll( '[data-alc-copy]' ).forEach( function ( button ) {
			var field = document.getElementById( button.getAttribute( 'data-alc-copy' ) );
			if ( ! field ) {
				return;
			}
			button.hidden = false;
			button.addEventListener( 'click', function () {
				field.focus();
				field.select();
				var done = function () {
					announce( text( 'copied', 'Link copied.' ), 'copied' );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( field.value ).then( done, function () {
						announce( text( 'copyFailed', 'Could not copy. Select the link and copy it by hand.' ), 'copied' );
					} );
				} else {
					announce( text( 'copyFailed', 'Could not copy. Select the link and copy it by hand.' ), 'copied' );
				}
			} );
		} );
	}

	function init() {
		costs();
		rowActions();
		saved();
		notices();
		confirms();
		busyForms();
		copyButtons();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
