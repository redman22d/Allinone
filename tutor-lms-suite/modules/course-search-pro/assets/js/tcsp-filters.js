/* global tcspConfig, jQuery */
( function ( $ ) {
	'use strict';

	if ( typeof tcspConfig === 'undefined' ) {
		return;
	}

	var cfg = tcspConfig;
	// The server-rendered URL filters are authoritative. Do not restore stale
	// selections from another visit (or the old standalone plugin).
	function rebuildMunicipalities() {
		var $municipality = $( '.tcsp-municipality' ).first();
		var $wilaya = $( '.tcsp-wilaya' ).first();
		if ( ! $municipality.length || ! $wilaya.length ) { return; }

		function normalizeCode( value ) {
			value = String( value == null ? '' : value ).trim();
			if ( ! value ) { return ''; }
			return value.replace( /^0+/, '' ) || '0';
		}

		function esc( value ) {
			return $( '<div/>' ).text( value == null ? '' : value ).html();
		}

		var selectedWilayas = $wilaya.find( 'input[type=checkbox]:checked' ).map( function () {
			return String( this.value == null ? '' : this.value ).trim();
		} ).get().filter( function ( value, index, arr ) {
			return value !== '' && arr.indexOf( value ) === index;
		} );

		var $panel = $municipality.find( '.tcsp-multiselect-panel' ).first();
		if ( ! $panel.length ) { return; }

		var enabled = selectedWilayas.length === 1;
		var selectedNormalized = enabled ? normalizeCode( selectedWilayas[0] ) : '';
		var saved = [];

		$panel.find( 'input[name="tcsp_municipality[]"]:checked' ).each( function () {
			saved.push( String( this.value ) );
		} );


		$municipality.toggleClass( 'tcsp-municipality-hidden', ! enabled );
		$municipality.toggleClass( 'tcsp-multiselect--disabled', ! enabled );
		$municipality.find( '.tcsp-multiselect-toggle' ).prop( 'disabled', ! enabled );

		// Keep the Match selector, but rebuild the municipality options from the
		// exact canonical location map localized by PHP from Tlms_Ipe_Locations.
		var $logic = $panel.find( '.tcsp-multiselect-logic' ).first();
		var map = ( cfg && cfg.communes && typeof cfg.communes === 'object' ) ? cfg.communes : {};
		var options = [];
		var matchedKey = null;

		Object.keys( map ).some( function ( key ) {
			if ( normalizeCode( key ) === selectedNormalized ) {
				matchedKey = key;
				return true;
			}
			return false;
		} );

		if ( enabled && matchedKey !== null && Array.isArray( map[ matchedKey ] ) ) {
			options = map[ matchedKey ];
		}

		$panel.find( '.tcsp-multiselect-option, .tcsp-municipality-empty' ).remove();

		if ( enabled && options.length ) {
			var html = '';
			options.forEach( function ( option ) {
				if ( ! option || typeof option !== 'object' ) { return; }
				var value = option.value != null ? String( option.value ) : ( option.id != null ? String( option.id ) : '' );
				var label = option.label != null ? String( option.label ) : ( option.name != null ? String( option.name ) : value );
				if ( ! value || ! label ) { return; }
				var checked = saved.indexOf( value ) !== -1 ? ' checked' : '';
				html += '<label class="tcsp-multiselect-option" data-wilaya="' + esc( String( matchedKey ) ) + '">' +
					'<input type="checkbox" name="tcsp_municipality[]" value="' + esc( value ) + '" data-wilaya="' + esc( String( matchedKey ) ) + '"' + checked + '>' +
					 esc( label ) + '</label>';
			} );
			$logic.after( html );
		}

		var count = $panel.find( 'input[name="tcsp_municipality[]"]:checked' ).length;
		var $count = $municipality.find( '.tcsp-multiselect-count' );
		if ( count ) {
			if ( $count.length ) { $count.text( count ); }
			else { $municipality.find( '.tcsp-multiselect-toggle' ).append( '<span class="tcsp-multiselect-count">' + count + '</span>' ); }
		} else if ( $count.length ) {
			$count.remove();
		}

		if ( enabled && ! options.length ) {
			$logic.after( $( '<div class="tcsp-municipality-empty"></div>' ).text( 'No municipalities found for this Wilaya.' ) );
		}
	}

	function escHtml( value ) {
		return $( '<div/>' ).text( value == null ? '' : value ).html();
	}
	function escAttr( value ) {
		return escHtml( value ).replace( /"/g, '&quot;' );
	}

	function syncCourseFormatFilter() {
		var live = $( '.tcsp-course_type input[type=checkbox]:checked' ).filter( function(){ return this.value === 'direct' || this.value === 'hybrid'; } ).length > 0;
		var $sub = $( '[data-tcsp-course-format-subfilter]' );
		$sub.toggleClass( 'is-visible', live ).attr( 'aria-hidden', live ? 'false' : 'true' );
		if ( ! live ) $sub.find( 'input[type=checkbox]' ).prop( 'checked', false );
	}
	// Keep checkbox interactions native. The previous document-level click
	// interception could make theme/Tutor delegated handlers and the browser's
	// label activation fight over the checkbox state. Only react after the
	// checkbox has actually changed.

	// Own the checkbox click completely. Some Tutor/theme scripts delegate
	// clicks from document and may submit/re-render the archive after the first
	// checkbox interaction. That used to make a second checked item disappear
	// visually (and also reset the ALL/ANY selector). We prevent the label's
	// native second activation and toggle the input exactly once ourselves.
	$( document ).on( 'click.tcspCheckboxState', '.tcsp-multiselect-option', function( e ) {
		// Let a real checkbox click use the browser's native state change.
		// Only intercept clicks on the surrounding label itself to avoid the
		// browser toggling the same checkbox twice.
		e.stopImmediatePropagation();
		if ( e.target && 'INPUT' === e.target.tagName ) { return; }
		e.preventDefault();
		var input = $( this ).find( 'input[type=checkbox]' ).first()[ 0 ];
		if ( ! input || input.disabled ) { return false; }
		input.checked = ! input.checked;
		$( input ).trigger( 'change' );
		return false;
	} );

	$( document ).on( 'change.tcspCheckboxState', '.tcsp-multiselect input[type=checkbox]', function( e ){
		// NOTE: stopImmediatePropagation() here stops jQuery from calling any
		// *other* delegated handler bound to `document` for this same change
		// event — including handlers bound to more specific selectors like
		// '.tcsp-wilaya input[type=checkbox]', even though they were
		// registered separately below. Those handlers used to never fire,
		// which is why picking a Wilaya only updated the Municipality list
		// after a full "Apply" page reload instead of immediately. Do all of
		// the per-widget follow-up work for this one event right here.
		e.stopImmediatePropagation();
		var $wrap = $( this ).closest( '.tcsp-multiselect' );
		$wrap.find( '.tcsp-multiselect-count' ).text( $wrap.find( 'input[type=checkbox]:checked' ).length );
		if ( $wrap.hasClass( 'tcsp-wilaya' ) ) {
			rebuildMunicipalities();
		}
		if ( $wrap.hasClass( 'tcsp-course_type' ) ) {
			syncCourseFormatFilter();
		}

	} );
	$( rebuildMunicipalities );
	$( syncCourseFormatFilter );

	function placeCourseFormatStickers() {
		$( '.tcsp-format-sticker' ).each( function(){
			var $sticker = $( this );
			if ( $sticker.closest( '.tutor-course-thumbnail, .tutor-course-thumbnail-wrap' ).length ) return;
			var $thumb = $sticker.closest( '.tutor-course-card' ).find( '.tutor-course-thumbnail, .tutor-course-thumbnail-wrap' ).first();
			if ( $thumb.length ) { $thumb.css( 'position', 'relative' ); $sticker.appendTo( $thumb ); }
		} );
	}
	$( placeCourseFormatStickers );
	$( document ).on( 'tutor_lms_course_list_updated ajaxComplete', function(){ setTimeout( placeCourseFormatStickers, 0 ); } );

	// ------------------------------------------------------------------
	// Tutor LMS 4.x may paginate the course loop through AJAX. In that
	// request the browser URL still contains the active TCSP filters, but
	// Tutor's AJAX payload does not necessarily copy those GET parameters.
	// Put the active filter/search state on the AJAX URL so PHP sees exactly
	// the same state when it builds the page-2/page-3 WP_Query.
	// ------------------------------------------------------------------
	function getTcspStateParams() {
		var params = new URLSearchParams( window.location.search );
		var state  = new URLSearchParams();

		params.forEach( function ( value, key ) {
			if ( key.indexOf( 'tcsp_' ) === 0 || key === 's' ) {
				state.append( key, value );
			}
		} );

		return state;
	}

	function appendTcspStateToUrl( url ) {
		if ( ! url || typeof url !== 'string' ) {
			return url;
		}

		var state = getTcspStateParams();
		if ( ! state.toString() ) {
			return url;
		}

		try {
			var target = new URL( url, window.location.href );
			state.forEach( function ( value, key ) {
				// Do not duplicate a value already supplied by Tutor.
				if ( ! target.searchParams.has( key ) ) {
					target.searchParams.append( key, value );
				}
			} );
			return target.toString();
		} catch ( err ) {
			return url;
		}
	}

	// Covers Tutor's AJAX pagination/filter requests, including POST-based
	// requests: query-string values are available to PHP through $_GET even
	// when the AJAX request itself uses POST.
	if ( $.ajaxPrefilter ) {
		$.ajaxPrefilter( function ( options ) {
			if ( ! options || ! options.url || options.url.indexOf( 'admin-ajax.php' ) === -1 ) {
				return;
			}
			if ( options.url.indexOf( 'action=' + encodeURIComponent( cfg.suggestAction ) ) !== -1 ) {
				return;
			}
			options.url = appendTcspStateToUrl( options.url );
		} );
	}

	// Some Tutor/theme pagination implementations use normal links instead
	// of AJAX. Make those links carry the same filter state as well.
	$( document ).on( 'click', '.tutor-pagination a, .tutor-pagination a.page-numbers, .page-numbers a, a.page-numbers', function () {
		var $link = $( this );
		var href  = $link.attr( 'href' );
		if ( href ) {
			$link.attr( 'href', appendTcspStateToUrl( href ) );
		}
	} );

	// ------------------------------------------------------------------
	// Resilience: some Tutor LMS themes/setups paginate or filter the
	// course archive over AJAX and swap out the whole loop wrapper. If our
	// filter bar happens to live inside that swapped region, it gets wiped
	// along with it — this is what causes the bar to "disappear" on page 2.
	// We keep a saved copy of the bar (with the request's current filter
	// values already baked in from the initial server render) and put it
	// back if it's ever found missing after any AJAX call or DOM mutation.
	// ------------------------------------------------------------------
	var $bar        = $( '.tcsp-filter-bar' );
	var barHTML     = $bar.length ? $bar[ 0 ].outerHTML : null;
	var $barAnchor  = $bar.length ? $bar.parent() : null;
	var $carousel   = $( '.tcsp-sponsored-carousel' ).first();
	var carouselHTML = $carousel.length ? $carousel[ 0 ].outerHTML : null;
	var restoring   = false;

	// ------------------------------------------------------------------
	// Sidebar layout: move the filter bar into a narrow sidebar column and
	// everything else that Tutor renders alongside it (result count,
	// sponsored carousel, "Courses" heading, the course grid, pagination)
	// into a wider results column next to it. Done in JS rather than PHP
	// because Tutor's own course-loop markup (grid + pagination) is not
	// something this plugin renders or controls — it is echoed by Tutor's
	// archive template around the same hook our filter bar attaches to, so
	// restructuring after the fact is the only reliable way to reach it.
	//
	// Only restructures inside Tutor's own known course-list wrapper
	// ( [tutor-course-list-container] / .tutor-pagination-wrapper-replaceable ).
	// If the filter bar was placed elsewhere — e.g. a site owner dropped the
	// [tcsp_filters] shortcode into their own custom template/sidebar via
	// the documented escape hatch — its surrounding markup isn't Tutor's to
	// begin with, so it's left exactly where the site owner put it instead
	// of being torn apart.
	// ------------------------------------------------------------------
	function placeTutorSortNearTop( $results ) {
		if ( ! $results || ! $results.length ) { return; }

		// Tutor renders its course-ordering/header after the content our plugin
		// adds (result count, sponsored carousel, Courses heading). When the
		// sponsored block is enabled this can create a very large visual gap
		// between the search/filter sidebar and Tutor's Relevance control. Keep
		// the normal heading + Tutor ordering directly at the top of the results
		// column, then show sponsored courses underneath that header.
		var sortSelectors = [
			'.tutor-course-list-header',
			'.tutor-course-listing-header',
			'.tutor-course-ordering',
			'.tutor-course-sort',
			'.tutor-course-list-header-wrapper'
		];
		var $sort = $();
		for ( var i = 0; i < sortSelectors.length; i++ ) {
			$sort = $results.find( sortSelectors[i] ).first();
			if ( $sort.length ) { break; }
		}
		if ( ! $sort.length ) { return; }

		var $heading = $results.find( '.tcsp-normal-results-heading' ).first();
		var $count = $results.find( '.tcsp-result-count' ).first();
		var $carousel = $results.find( '.tcsp-sponsored-carousel' ).first();

		if ( $heading.length ) { $sort.before( $heading ); }
		if ( $count.length ) { $sort.before( $count ); }
		if ( $carousel.length ) { $sort.after( $carousel ); }
	}

	function applySidebarLayout() {
		var $form = $( '.tcsp-filter-bar' ).first();
		if ( ! $form.length || $form.closest( '.tcsp-sidebar' ).length ) {
			var $existingResults = $( '.tcsp-results-col' ).first();
			if ( $existingResults.length ) { placeTutorSortNearTop( $existingResults ); }
			if ( $form.length ) { $form.addClass( 'tcsp-sidebar-ready' ); }
			return;
		}

		var $parent = $form.closest( '[tutor-course-list-container], .tutor-pagination-wrapper-replaceable' );
		if ( ! $parent.length ) {
			return;
		}

		var children = $parent.children().toArray();
		var $layout  = $( '<div class="tcsp-layout"></div>' );
		var $sidebar = $( '<aside class="tcsp-sidebar"></aside>' );
		var $results = $( '<div class="tcsp-results-col"></div>' );

		children.forEach( function ( el ) {
			var $el = $( el );
			if ( $el.is( $form ) ) {
				$sidebar.append( $el );
			} else {
				$results.append( $el );
			}
		} );

		$layout.append( $sidebar, $results );
		$parent.append( $layout );
		placeTutorSortNearTop( $results );

		// Reveal only after the filter has been moved into its final sidebar
		// position, preventing a one-frame/one-second flash at the old top
		// position while the archive JavaScript initializes.
		$form.addClass( 'tcsp-sidebar-ready' );
	}

	// Insert freshly-fetched/restored sponsored-carousel markup in the
	// right place regardless of whether the sidebar layout has already
	// been applied: inside the results column when it exists, otherwise
	// next to the filter bar the way the flat (pre-sidebar) markup does.
	function insertCarouselMarkup( html ) {
		var $results = $( '.tcsp-results-col' ).first();
		if ( $results.length ) {
			var $count = $results.find( '.tcsp-result-count' ).first();
			if ( $count.length ) {
				$count.after( html );
			} else {
				$results.prepend( html );
			}
			return;
		}
		if ( $barAnchor && $barAnchor.length ) {
			var $newBar = $barAnchor.find( '.tcsp-filter-bar' ).first();
			if ( $newBar.length ) {
				$newBar.after( html );
			} else {
				$barAnchor.prepend( html );
			}
		}
	}

	function restoreArchiveUIIfMissing() {
		if ( restoring || ! $barAnchor || ! $barAnchor.length || ! document.body.contains( $barAnchor[ 0 ] ) ) {
			return;
		}

		var needsBar = !! barHTML && ! $( '.tcsp-filter-bar' ).length;
		var needsCarousel = !! carouselHTML && ! $( '.tcsp-sponsored-carousel' ).length;
		// IMPORTANT: do not rebuild the Municipality list on every DOM mutation.
		// rebuildMunicipalities() itself changes the DOM, and the old body-wide
		// MutationObserver consequently triggered an endless restore/rebuild loop
		// that left archive pages spinning and could hide the filters.
		if ( ! needsBar && ! needsCarousel ) {
			forEachCarousel( initCarousel );
			return;
		}

		restoring = true;
		if ( needsBar ) {
			$barAnchor.prepend( barHTML );
		}
		if ( needsCarousel ) {
			insertCarouselMarkup( carouselHTML );
		}
		restoring = false;

		applySidebarLayout();
		rebuildMunicipalities();

		// A restored carousel did not pass through DOMContentLoaded, so it must
		// be initialized explicitly.
		forEachCarousel( initCarousel );
	}

	// ------------------------------------------------------------------
	// Re-roll the sponsored carousel's course order whenever the course
	// loop itself is paginated/filtered over AJAX. get_sponsored_carousel_ids()
	// draws a fresh weighted-random rotation on every PHP request, which is
	// why a full page reload shows a different order — but an AJAX
	// pagination request never reloads the page, so without this the
	// carousel stays frozen on whatever order rendered on page 1.
	// ------------------------------------------------------------------
	var carouselRefreshInFlight = false;

	function refreshCarousel() {
		if ( carouselRefreshInFlight || ! cfg.refreshCarouselAction ) {
			return;
		}
		carouselRefreshInFlight = true;

		var data = { action: cfg.refreshCarouselAction, nonce: cfg.refreshCarouselNonce };
		getTcspStateParams().forEach( function ( value, key ) {
			data[ key ] = value;
		} );

		$.ajax( {
			url: cfg.ajaxUrl,
			method: 'GET',
			dataType: 'json',
			data: data
		} ).done( function ( res ) {
			if ( ! res || ! res.success || typeof res.data.html !== 'string' ) {
				return;
			}
			var $existing = $( '.tcsp-sponsored-carousel' ).first();
			var html = $.trim( res.data.html );

			if ( ! html ) {
				// No sponsored course matches this page's filters anymore.
				if ( $existing.length ) $existing.remove();
				carouselHTML = null;
				return;
			}

			carouselHTML = html;
			if ( $existing.length ) {
				$existing.replaceWith( html );
			} else {
				insertCarouselMarkup( html );
			}
			forEachCarousel( initCarousel );
		} ).always( function () {
			carouselRefreshInFlight = false;
		} );
	}

	// Actions the plugin itself fires over admin-ajax.php; an ajaxComplete
	// caused by one of these is not course-loop pagination and should not
	// trigger a carousel re-roll.
	function isOwnAjaxAction( url ) {
		if ( ! url ) return false;
		var actions = [ cfg.suggestAction, cfg.wishlistAction, cfg.refreshCarouselAction ];
		for ( var i = 0; i < actions.length; i++ ) {
			if ( actions[ i ] && url.indexOf( 'action=' + encodeURIComponent( actions[ i ] ) ) !== -1 ) {
				return true;
			}
		}
		return false;
	}

	$( document ).on( 'ajaxComplete', function ( event, xhr, settings ) {
		window.setTimeout( restoreArchiveUIIfMissing, 50 );
		window.setTimeout( restoreArchiveUIIfMissing, 250 );
		window.setTimeout( restoreArchiveUIIfMissing, 700 );

		if ( settings && settings.url && settings.url.indexOf( 'admin-ajax.php' ) !== -1 && ! isOwnAjaxAction( settings.url ) ) {
			window.setTimeout( refreshCarousel, 300 );
		}
	} );

	// No body-wide MutationObserver: it can reinsert stale filter markup and erase
	// live multiselect selections while Tutor is updating the archive.

	// Apply the sidebar layout to whatever markup is already on the page;
	// the script is enqueued in the footer, so the archive HTML is already
	// present by the time this runs.
	applySidebarLayout();

	// ------------------------------------------------------------------
	// Multiselect (category / tag) dropdown toggles. Delegated on document
	// so they keep working even after restoreBarIfMissing() re-inserts a
	// fresh copy of the bar's markup.
	// ------------------------------------------------------------------

	$( document ).on( 'change.tcspLogicControl', '.tcsp-logic-select', function( e ){
		e.stopImmediatePropagation();
	} );

	$( document ).on( 'click', '.tcsp-multiselect-toggle', function ( e ) {
		e.preventDefault();
		var $toggle = $( this );
		var $panel  = $toggle.next( '.tcsp-multiselect-panel' );
		var open    = ! $panel.attr( 'hidden' );

		$( '.tcsp-multiselect-panel' ).not( $panel ).attr( 'hidden', true );
		$( '.tcsp-multiselect-toggle' ).not( $toggle ).attr( 'aria-expanded', 'false' );

		if ( open ) {
			$panel.attr( 'hidden', true );
			$toggle.attr( 'aria-expanded', 'false' );
		} else {
			$panel.removeAttr( 'hidden' );
			$toggle.attr( 'aria-expanded', 'true' );
		}
	} );

	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.tcsp-multiselect' ).length ) {
			$( '.tcsp-multiselect-panel' ).attr( 'hidden', true );
			$( '.tcsp-multiselect-toggle' ).attr( 'aria-expanded', 'false' );
		}
	} );

	// ------------------------------------------------------------------
	// Strip no-op filter fields before the form submits. A plain HTML GET
	// form serializes EVERY named field, blank or not — so clicking
	// "Apply" with nothing actually changed still produces a URL like
	// ?s=&tcsp_price=&tcsp_min_rating=0&tcsp_min_students=0&tcsp_sort=...
	// Some multilingual setups (TranslatePress in particular) key off the
	// mere presence of a search parameter to trigger their own translated-
	// search query rewriting, even when it's empty — which can hijack the
	// whole result set on non-default-language pages. Since an empty/off
	// field never changes the query results either way, the safe fix is
	// to just not send it: disabled fields are skipped entirely when the
	// browser builds the GET query string.
	// ------------------------------------------------------------------
	$( document ).on( 'submit', '.tcsp-filter-bar', function () {
		var $form = $( this );

		// Only exclude fields the user hasn't actually enabled/disabled
		// again on the next load — jQuery submit fires before the browser
		// serializes the form, and disabled fields are dropped from that
		// serialization automatically.
		$form.find( '.tcsp-search-input' ).each( function () {
			if ( '' === $.trim( $( this ).val() ) ) {
				$( this ).prop( 'disabled', true );
			}
		} );

		$form.find( 'select[name="tcsp_price"], select[name="tcsp_level"]' ).each( function () {
			if ( '' === $( this ).val() ) {
				$( this ).prop( 'disabled', true );
			}
		} );

		$form.find( 'select[name="tcsp_min_rating"], select[name="tcsp_min_students"], select[name="tcsp_wilaya"], select[name="tcsp_municipality"], select[name="tcsp_verified"]' ).each( function () {
			if ( '0' === String( $( this ).val() ) ) {
				$( this ).prop( 'disabled', true );
			}
		} );

		var $sort = $form.find( 'select[name="tcsp_sort"]' );
		if ( $sort.length && cfg.defaultSort && $sort.val() === cfg.defaultSort ) {
			$sort.prop( 'disabled', true );
		}

		// Category/tag "match all / match any" logic only means anything
		// once at least one checkbox in that group is actually checked.
		var $catBoxes = $form.find( 'input[name="tcsp_category[]"]:checked' );
		if ( ! $catBoxes.length ) {
			$form.find( 'select[name="tcsp_category_logic"]' ).prop( 'disabled', true );
		}
		var $tagBoxes = $form.find( 'input[name="tcsp_tag[]"]:checked' );
		if ( ! $tagBoxes.length ) {
			$form.find( 'select[name="tcsp_tag_logic"]' ).prop( 'disabled', true );
		}
		[ 'wilaya', 'municipality' ].forEach( function ( name ) {
			var $boxes = $form.find( 'input[name="tcsp_' + name + '[]"]:checked' );
			if ( ! $boxes.length ) {
				$form.find( 'select[name="tcsp_' + name + '_logic"]' ).prop( 'disabled', true );
			}
		} );
	} );

	// ------------------------------------------------------------------
	// Autocomplete suggestions (only when the admin enabled the enhanced
	// suggestions). Delegated so it works after the bar is re-inserted.
	// ------------------------------------------------------------------
	if ( ! cfg.replaceSuggestions ) {
		return;
	}

	var timer    = null;
	var lastTerm = '';
	var xhr      = null;

	function esc( str ) {
		return $( '<div/>' ).text( str == null ? '' : str ).html();
	}

	function getBox( $input ) {
		return $input.closest( '.tcsp-search-wrap' ).find( '.tcsp-suggest-box' );
	}

	function hideBox( $box ) {
		$box.attr( 'hidden', true ).empty();
	}

	function renderItems( $box, items, term ) {
		if ( ! items.length ) {
			$box.html( '<div class="tcsp-suggest-empty">' + esc( cfg.i18n.noResults ) + '</div>' );
			$box.removeAttr( 'hidden' );
			return;
		}

		var html = '';
		items.forEach( function ( item ) {
			var badge = item.promoted
				? '<span class="tcsp-badge tcsp-badge--promoted">' + esc( item.tierLabel || cfg.i18n.promoted ) + '</span>'
				: '';
			var thumb = item.thumb
				? '<img class="tcsp-suggest-thumb" src="' + esc( item.thumb ) + '" alt="" loading="lazy">'
				: '<span class="tcsp-suggest-thumb tcsp-suggest-thumb--empty"></span>';

			html +=
				'<a class="tcsp-suggest-item' + ( item.promoted ? ' is-promoted' : '' ) + '" href="' + esc( item.url ) + '">' +
					thumb +
					'<span class="tcsp-suggest-meta">' +
						'<span class="tcsp-suggest-title">' + esc( item.title ) + badge + '</span>' +
						'<span class="tcsp-suggest-sub">' + esc( item.author ) +
							( item.price ? ' · ' + esc( item.price ) : '' ) +
						'</span>' +
					'</span>' +
				'</a>';
		} );

		var allUrl = '?s=' + encodeURIComponent( term ) + '&post_type=courses';
		html += '<a class="tcsp-suggest-all" href="' + allUrl + '">' + esc( cfg.i18n.viewAll ) + '</a>';

		$box.html( html ).removeAttr( 'hidden' );
	}

	function fetchSuggestions( $input, term ) {
		var $box = getBox( $input );
		if ( xhr && xhr.abort ) {
			xhr.abort();
		}
		$box.html( '<div class="tcsp-suggest-loading">' + esc( cfg.i18n.searching ) + '</div>' ).removeAttr( 'hidden' );

		xhr = $.ajax( {
			url: cfg.ajaxUrl,
			method: 'GET',
			dataType: 'json',
			data: {
				action: cfg.suggestAction,
				nonce: cfg.nonce,
				term: term
			}
		} ).done( function ( res ) {
			if ( res && res.success && res.data ) {
				renderItems( $box, res.data.items || [], term );
			} else {
				hideBox( $box );
			}
		} ).fail( function ( _, status ) {
			if ( status !== 'abort' ) {
				hideBox( $box );
			}
		} );
	}

	$( document ).on( 'input', '.tcsp-search-input', function () {
		var $input = $( this );
		var term   = $.trim( $input.val() );
		if ( term.length < cfg.minChars ) {
			hideBox( getBox( $input ) );
			lastTerm = '';
			return;
		}
		if ( term === lastTerm ) {
			return;
		}
		lastTerm = term;
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			fetchSuggestions( $input, term );
		}, 220 );
	} );

	$( document ).on( 'focus', '.tcsp-search-input', function () {
		var $input = $( this );
		var $box   = getBox( $input );
		if ( $box.children().length && $.trim( $input.val() ).length >= cfg.minChars ) {
			$box.removeAttr( 'hidden' );
		}
	} );

	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.tcsp-search-wrap' ).length ) {
			$( '.tcsp-suggest-box' ).each( function () {
				hideBox( $( this ) );
			} );
		}
	} );

	// Basic keyboard navigation.
	$( document ).on( 'keydown', '.tcsp-search-input', function ( e ) {
		var $box   = getBox( $( this ) );
		var $items = $box.find( '.tcsp-suggest-item' );
		if ( ! $items.length ) {
			return;
		}
		var $active = $items.filter( '.is-active' );
		var idx     = $items.index( $active );

		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			idx = ( idx + 1 ) % $items.length;
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			idx = ( idx - 1 + $items.length ) % $items.length;
		} else if ( e.key === 'Enter' && $active.length ) {
			window.location.href = $active.attr( 'href' );
			return;
		} else if ( e.key === 'Escape' ) {
			hideBox( $box );
			return;
		} else {
			return;
		}
		$items.removeClass( 'is-active' );
		$items.eq( idx ).addClass( 'is-active' );
	} );

    // ------------------------------------------------------------------
    // Sponsored-course carousel: native scrolling + mouse drag + instant
    // wishlist AJAX. Kept in the same scope as the code above (rather than
    // a separate IIFE) so restoreArchiveUIIfMissing()/refreshCarousel() can
    // call forEachCarousel()/initCarousel() directly after restoring or
    // re-fetching the carousel markup.
    // ------------------------------------------------------------------
    function forEachCarousel(callback) {
        var nodes = document.querySelectorAll('.tcsp-sponsored-carousel');
        Array.prototype.forEach.call(nodes, callback);
    }

    function initCarousel(carousel) {
        var track = carousel.querySelector('.tcsp-sponsored-track');
        var prev  = carousel.querySelector('.tcsp-carousel-prev');
        var next  = carousel.querySelector('.tcsp-carousel-next');
        if (!track || !prev || !next || carousel.getAttribute('data-tcsp-carousel-ready') === '1') {
            return;
        }
        carousel.setAttribute('data-tcsp-carousel-ready', '1');

        function update() {
            var max = Math.max(0, track.scrollWidth - track.clientWidth);
            var left = Math.max(0, track.scrollLeft);
            prev.disabled = max <= 2 || left <= 2;
            next.disabled = max <= 2 || left >= max - 2;
            prev.setAttribute('aria-disabled', prev.disabled ? 'true' : 'false');
            next.setAttribute('aria-disabled', next.disabled ? 'true' : 'false');
            carousel.classList.toggle('tcsp-carousel-is-scrollable', max > 2);
        }

        function step(direction) {
            var amount = Math.max(220, Math.round(track.clientWidth * 0.72));
            var card = track.querySelector('.tcsp-sponsored-card');
            if (card) {
                amount = Math.max(amount, Math.round(card.getBoundingClientRect().width + 14));
            }
            var target = track.scrollLeft + (amount * direction);
            track.style.scrollBehavior = 'smooth';
            track.scrollLeft = target;
        }

        prev.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            step(-1);
        });
        next.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            step(1);
        });

        track.addEventListener('wheel', function (e) {
            var dx = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : e.deltaY;
            var max = Math.max(0, track.scrollWidth - track.clientWidth);
            if (max <= 2 || !dx) return;
            var before = track.scrollLeft;
            track.scrollLeft = Math.max(0, Math.min(max, before + dx));
            if (track.scrollLeft !== before) {
                e.preventDefault();
            }
        }, { passive: false });

        // Mouse drag. Touch devices use the browser's native horizontal
        // overflow scrolling; this is more reliable across Safari/Chrome.
        var dragging = false;
        var moved = false;
        var startX = 0;
        var startScroll = 0;

        track.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            dragging = true;
            moved = false;
            startX = e.clientX;
            startScroll = track.scrollLeft;
            track.classList.add('is-dragging');
            track.style.scrollBehavior = 'auto';
        });

        window.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            var dx = e.clientX - startX;
            if (Math.abs(dx) > 4) moved = true;
            track.scrollLeft = startScroll - dx;
        });

        function endMouseDrag() {
            if (!dragging) return;
            dragging = false;
            track.classList.remove('is-dragging');
            track.style.scrollBehavior = '';
            window.setTimeout(function () { moved = false; }, 0);
        }
        window.addEventListener('mouseup', endMouseDrag);
        track.addEventListener('mouseleave', function () {
            // Do not end capture on leave; window mousemove keeps the drag alive.
        });

        track.addEventListener('click', function (e) {
            if (moved) {
                e.preventDefault();
                e.stopPropagation();
                moved = false;
            }
        }, true);

        track.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        window.setTimeout(update, 50);
        window.setTimeout(update, 400);

        // Re-check after images/font/layout settle; this fixes carousels that
        // initially measure as non-scrollable during the first paint.
        window.setTimeout(update, 1000);
    }

    function syncWishlist(button, wished) {
        button.classList.toggle('has-wish-listed', !!wished);
        button.classList.toggle('is-active', !!wished);
        button.classList.remove('is-saving', 'is-error');
        button.setAttribute('aria-pressed', wished ? 'true' : 'false');
        button.setAttribute('aria-label', wished ? 'Remove from wishlist' : 'Add to wishlist');
        button.setAttribute('title', wished ? 'Remove from wishlist' : 'Add to wishlist');
        var icon = button.querySelector('[class*="bookmark-"]');
        if (icon) icon.className = wished ? 'tutor-icon-bookmark-bold' : 'tutor-icon-bookmark-line';
    }

    function toggleWishlist(button) {
        if (button.getAttribute('data-wishlist-saving') === '1') return;
        if (!window.tcspConfig || !tcspConfig.wishlistNonce) return;

        var courseId = parseInt(button.getAttribute('data-course-id') || '0', 10);
        if (!courseId) return;

        var current = button.classList.contains('has-wish-listed') || button.classList.contains('is-active');
        var previous = current;
        var nonceKey = tcspConfig.wishlistNonceKey || '_tutor_nonce';

        button.setAttribute('data-wishlist-saving', '1');
        // Optimistic UI: user sees the state change immediately.
        syncWishlist(button, !current);
        button.classList.add('is-saving');

        var body = new URLSearchParams();
        body.append('action', 'tutor_course_add_to_wishlist');
        body.append('course_id', String(courseId));
        body.append(nonceKey, tcspConfig.wishlistNonce);

        fetch(tcspConfig.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        }).then(function (result) {
            var data = result.data;
            if (!result.ok || !data || !data.success) {
                throw new Error('Wishlist update failed');
            }

            // Tutor LMS returns status=added or status=removed.
            var status = data.data && data.data.status ? String(data.data.status) : '';
            if ('added' !== status && 'removed' !== status) {
                throw new Error('Unexpected wishlist response');
            }
            var wished = ('added' === status);
            syncWishlist(button, wished);

            // Keep duplicate instances of the same course synchronized.
            document.querySelectorAll('.tcsp-sponsored-wishlist[data-course-id="' + courseId + '"]').forEach(function (other) {
                if (other !== button) syncWishlist(other, wished);
            });
        }).catch(function () {
            syncWishlist(button, previous);
            button.classList.add('is-error');
        }).finally(function () {
            button.removeAttribute('data-wishlist-saving');
            window.setTimeout(function () { button.classList.remove('is-saving'); }, 150);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        forEachCarousel(initCarousel);
        document.addEventListener('click', function (e) {
            var button = e.target.closest ? e.target.closest('.tcsp-sponsored-wishlist') : null;
            if (!button) return;
            if (button.classList.contains('cart-required-login')) return;
            e.preventDefault();
            if (e.stopImmediatePropagation) e.stopImmediatePropagation();
            e.stopPropagation();
            toggleWishlist(button);
        });
    });
}( jQuery ) );
