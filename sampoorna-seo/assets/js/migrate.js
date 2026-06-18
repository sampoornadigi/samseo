/**
 * Sampoorna SEO — migration batch importer.
 *
 * Drives a resumable import: POSTs batches to admin-ajax until the server
 * reports no remaining posts, updating a progress bar. Pure vanilla JS.
 */
( function () {
	'use strict';

	var cfg = window.SampoornaMigrate || {};

	function $( id ) {
		return document.getElementById( id );
	}

	function init() {
		var btn = $( 'sseo-migrate-start' );
		if ( ! btn || ! cfg.ajaxUrl ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var source = btn.getAttribute( 'data-source' ) || '',
				total = parseInt( btn.getAttribute( 'data-total' ) || '0', 10 ),
				progress = $( 'sseo-migrate-progress' ),
				bar = $( 'sseo-migrate-bar' ),
				status = $( 'sseo-migrate-status' ),
				done = 0,
				written = 0;

			btn.disabled = true;
			if ( progress ) {
				progress.style.display = 'block';
			}

			function step( afterId ) {
				var body = new URLSearchParams();
				body.set( 'action', 'sampoorna_seo_migrate_batch' );
				body.set( 'nonce', cfg.nonce || '' );
				body.set( 'source', source );
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
							finish( written );
						}
					} )
					.catch( function () { fail( 'Network error.' ); } );
			}

			function finish( count ) {
				if ( bar ) {
					bar.style.width = '100%';
				}
				if ( status ) {
					status.textContent = 'Done. Imported ' + count + ' post(s). Reloading…';
				}
				window.location = window.location.pathname + window.location.search.replace( /&?sampoorna_seo_notice=[^&]*/, '' ) + ( window.location.search ? '&' : '?' ) + 'sampoorna_seo_notice=migrate_imported';
			}

			function fail( msg ) {
				btn.disabled = false;
				if ( status ) {
					status.textContent = msg;
				}
			}

			step( 0 );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
