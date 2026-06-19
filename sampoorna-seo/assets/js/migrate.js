/**
 * Sampoorna SEO — migration batch importer.
 *
 * Drives a resumable import per source: POSTs batches to admin-ajax until the
 * server reports no remaining posts, updating that source's progress bar.
 * Supports multiple detected sources on the page. Pure vanilla JS.
 */
( function () {
	'use strict';

	var cfg = window.SampoornaMigrate || {};

	function init() {
		if ( ! cfg.ajaxUrl ) {
			return;
		}
		var buttons = document.querySelectorAll( '.sseo-migrate-start' );
		Array.prototype.forEach.call( buttons, function ( btn ) {
			btn.addEventListener( 'click', function () {
				runImport( btn );
			} );
		} );
	}

	function runImport( btn ) {
		var source = btn.getAttribute( 'data-source' ) || '',
			objectType = btn.getAttribute( 'data-type' ) || 'post',
			total = parseInt( btn.getAttribute( 'data-total' ) || '0', 10 ),
			wrap = document.querySelector( '.sseo-migrate-progress[data-slug="' + source + '"]' ),
			bar = wrap ? wrap.querySelector( '.sseo-migrate-bar' ) : null,
			status = wrap ? wrap.querySelector( '.sseo-migrate-status' ) : null,
			done = 0,
			written = 0;

		btn.disabled = true;
		if ( wrap ) {
			wrap.style.display = 'block';
		}

		function step( afterId ) {
			var body = new URLSearchParams();
			body.set( 'action', 'sampoorna_seo_migrate_batch' );
			body.set( 'nonce', cfg.nonce || '' );
			body.set( 'source', source );
			body.set( 'object_type', objectType );
			body.set( 'after_id', String( afterId ) );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( ! res || ! res.success || ! res.data ) {
						fail( res && res.data && res.data.message ? res.data.message : 'Import failed.' );
						return;
					}
					var d = res.data;
					done += d.processed;
					written += d.written;
					var pct = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 100;
					if ( bar ) {
						bar.style.width = pct + '%';
					}
					if ( status ) {
						status.textContent = 'Imported ' + written + ' post(s), ' + done + ' scanned…';
					}
					if ( d.remaining > 0 && d.processed > 0 ) {
						step( d.last_id );
					} else {
						finish();
					}
				} )
				.catch( function () { fail( 'Network error.' ); } );
		}

		function finish() {
			if ( bar ) {
				bar.style.width = '100%';
			}
			if ( status ) {
				status.textContent = 'Done. Imported ' + written + ' post(s). Reloading…';
			}
			var search = window.location.search.replace( /&?sampoorna_seo_notice=[^&]*/, '' );
			window.location = window.location.pathname + search + ( search ? '&' : '?' ) + 'sampoorna_seo_notice=migrate_imported';
		}

		function fail( msg ) {
			btn.disabled = false;
			if ( status ) {
				status.textContent = msg;
			}
		}

		step( 0 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
