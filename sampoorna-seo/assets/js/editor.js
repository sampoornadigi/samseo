/**
 * Sampoorna SEO — post editor meta box.
 *
 * Live character counters and a Google-style snippet preview. Pure vanilla JS,
 * no build step, no external dependencies. The authoritative on-page score is
 * computed server-side; this script only provides instant editing feedback.
 */
( function () {
	'use strict';

	var cfg = window.SampoornaSEO || { siteName: '', sep: '-', home: '' };

	var TITLE_MIN = 30,
		TITLE_MAX = 60,
		DESC_MIN = 70,
		DESC_MAX = 160;

	function $( id ) {
		return document.getElementById( id );
	}

	function band( len, min, max ) {
		if ( len === 0 ) {
			return 'bad';
		}
		if ( len >= min && len <= max ) {
			return 'good';
		}
		return 'ok';
	}

	function setCount( el, len, min, max ) {
		if ( ! el ) {
			return;
		}
		el.textContent = len + ' / ' + min + '–' + max;
		el.className = 'sseo-count sseo-count--' + band( len, min, max );
	}

	function renderTitle( titleInput ) {
		var v = ( titleInput.value || '' ).trim();
		if ( v ) {
			// Resolve the common tokens for an approximate preview.
			v = v
				.replace( /%sitename%/g, cfg.siteName )
				.replace( /%sep%/g, cfg.sep )
				.replace( /%title%/g, '' )
				.replace( /\s{2,}/g, ' ' )
				.trim();
		}
		return v || cfg.siteName;
	}

	function init() {
		var titleEl = $( 'sseo-title' ),
			descEl = $( 'sseo-desc' ),
			snipTitle = $( 'sseo-snippet-title' ),
			snipUrl = $( 'sseo-snippet-url' ),
			snipDesc = $( 'sseo-snippet-desc' );

		if ( ! titleEl && ! descEl ) {
			return;
		}

		var slug = titleEl ? titleEl.getAttribute( 'data-slug' ) || '' : '';

		function update() {
			if ( titleEl ) {
				setCount( $( 'sseo-title-count' ), titleEl.value.length, TITLE_MIN, TITLE_MAX );
				if ( snipTitle ) {
					snipTitle.textContent = renderTitle( titleEl );
				}
			}
			if ( descEl ) {
				setCount( $( 'sseo-desc-count' ), descEl.value.length, DESC_MIN, DESC_MAX );
				if ( snipDesc ) {
					snipDesc.textContent = descEl.value || '';
				}
			}
			if ( snipUrl ) {
				snipUrl.textContent = ( cfg.home || '' ) + slug;
			}
		}

		if ( titleEl ) {
			titleEl.addEventListener( 'input', update );
		}
		if ( descEl ) {
			descEl.addEventListener( 'input', update );
		}

		initAi( update );
		initScores();
		update();
	}

	function debounce( fn, wait ) {
		var t;
		return function () {
			clearTimeout( t );
			t = setTimeout( fn, wait );
		};
	}

	function postId() {
		if ( window.wp && wp.data && wp.data.select( 'core/editor' ) ) {
			var id = wp.data.select( 'core/editor' ).getCurrentPostId();
			if ( id ) {
				return String( id );
			}
		}
		var hidden = $( 'post_ID' );
		return hidden ? hidden.value : '';
	}

	function editorContent() {
		if ( window.wp && wp.data && wp.data.select( 'core/editor' ) ) {
			return wp.data.select( 'core/editor' ).getEditedPostContent() || '';
		}
		var classic = $( 'content' );
		return classic ? classic.value : '';
	}

	function bandClass( base, band ) {
		return base + ' ' + base + '--' + band;
	}

	function paintScores( scores ) {
		var chips = $( 'sseo-scores' ),
			checks = $( 'sseo-checks' );

		if ( chips ) {
			chips.textContent = '';
			scores.forEach( function ( s ) {
				var span = document.createElement( 'span' );
				span.className = bandClass( 'sseo-score', s.band );
				var num = document.createElement( 'span' );
				num.className = 'sseo-score__num';
				num.textContent = String( s.score );
				var label = document.createElement( 'span' );
				label.className = 'sseo-score__label';
				label.textContent = s.label;
				span.appendChild( num );
				span.appendChild( label );
				chips.appendChild( span );
			} );
		}

		if ( checks ) {
			checks.textContent = '';
			scores.forEach( function ( s ) {
				var title = document.createElement( 'p' );
				title.className = 'sseo-checks__title';
				var strong = document.createElement( 'strong' );
				strong.textContent = s.label;
				title.appendChild( strong );
				checks.appendChild( title );

				var ul = document.createElement( 'ul' );
				ul.className = 'sseo-checks';
				( s.checks || [] ).forEach( function ( c ) {
					var li = document.createElement( 'li' );
					li.className = bandClass( 'sseo-check', c.status );
					var cl = document.createElement( 'span' );
					cl.className = 'sseo-check__label';
					cl.textContent = c.label;
					var cm = document.createElement( 'span' );
					cm.className = 'sseo-check__msg';
					cm.textContent = c.msg;
					li.appendChild( cl );
					li.appendChild( cm );
					ul.appendChild( li );
				} );
				checks.appendChild( ul );
			} );
		}
	}

	function initScores() {
		var titleEl = $( 'sseo-title' ),
			descEl = $( 'sseo-desc' ),
			focusEl = $( 'sseo-focus' ),
			id = postId();

		if ( ! cfg.ajaxUrl || ! cfg.scoreNonce || ! id ) {
			return;
		}

		function recompute() {
			var body = new URLSearchParams();
			body.set( 'action', 'sampoorna_seo_score' );
			body.set( 'nonce', cfg.scoreNonce );
			body.set( 'post_id', id );
			body.set( 'title', titleEl ? titleEl.value : '' );
			body.set( 'desc', descEl ? descEl.value : '' );
			body.set( 'focus_keyword', focusEl ? focusEl.value : '' );
			body.set( 'content', editorContent() );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success && res.data && res.data.scores ) {
						paintScores( res.data.scores );
					}
				} )
				.catch( function () { /* leave server-rendered scores in place */ } );
		}

		var trigger = debounce( recompute, 800 );
		if ( titleEl ) {
			titleEl.addEventListener( 'input', trigger );
		}
		if ( descEl ) {
			descEl.addEventListener( 'input', trigger );
		}
		if ( focusEl ) {
			focusEl.addEventListener( 'input', trigger );
		}
		if ( window.wp && wp.data && wp.data.subscribe && wp.data.select( 'core/editor' ) ) {
			var last = wp.data.select( 'core/editor' ).getEditedPostContent();
			wp.data.subscribe( function () {
				var now = wp.data.select( 'core/editor' ).getEditedPostContent();
				if ( now !== last ) {
					last = now;
					trigger();
				}
			} );
		}
	}

	function initAi( update ) {
		var btn = $( 'sseo-ai-generate' ),
			msg = $( 'sseo-ai-msg' ),
			focusEl = $( 'sseo-focus' ),
			titleEl = $( 'sseo-title' ),
			descEl = $( 'sseo-desc' );

		if ( ! btn || ! cfg.ajaxUrl ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var original = btn.textContent;
			btn.disabled = true;
			btn.textContent = 'Generating…';
			if ( msg ) {
				msg.textContent = '';
				msg.className = 'sseo-ai__msg';
			}

			var body = new URLSearchParams();
			body.set( 'action', 'sampoorna_seo_generate_meta' );
			body.set( 'nonce', cfg.aiNonce || '' );
			body.set( 'post_id', btn.getAttribute( 'data-post' ) || '' );
			body.set( 'focus_keyword', focusEl ? focusEl.value : '' );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success && res.data ) {
						if ( titleEl && res.data.title ) {
							titleEl.value = res.data.title;
						}
						if ( descEl && res.data.description ) {
							descEl.value = res.data.description;
						}
						update();
						if ( msg ) {
							msg.textContent = 'Suggestions applied to the fields — review and save.';
							msg.className = 'sseo-ai__msg sseo-ai__msg--ok';
						}
					} else if ( msg ) {
						msg.textContent = ( res && res.data && res.data.message ) ? res.data.message : 'Generation failed.';
						msg.className = 'sseo-ai__msg sseo-ai__msg--err';
					}
				} )
				.catch( function () {
					if ( msg ) {
						msg.textContent = 'Network error. Please try again.';
						msg.className = 'sseo-ai__msg sseo-ai__msg--err';
					}
				} )
				.finally( function () {
					btn.disabled = false;
					btn.textContent = original;
				} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
