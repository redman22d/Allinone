/**
 * Tutor LMS Course Format - classic wp-admin metabox behaviour.
 *
 * Hides the live-session fields while the course type is not a live type.
 */
(function () {
	'use strict';

	function init() {
		var box = document.querySelector('.tcf-metabox');
		if (!box || box.getAttribute('data-tcf-bound') === '1') {
			return;
		}

		var select = box.querySelector('select[name="tcf_course_type"]');
		var liveOnly = box.querySelector('.tcf-live-only');
		if (!select || !liveOnly) {
			return;
		}

		var liveTypes = [];
		try {
			liveTypes = JSON.parse(box.getAttribute('data-live-types') || '[]');
		} catch (error) {
			liveTypes = ['direct', 'hybrid'];
		}

		function sync() {
			var isLive = liveTypes.indexOf(select.value) !== -1;

			// Use the `hidden` property (not just style.display) because PHP
			// renders the attribute for prerecorded courses; leaving it in
			// place would keep the block hidden after switching to a live type.
			liveOnly.hidden = !isLive;

			// Disabled inputs are not submitted, keep them out of the payload
			// so the server clears the stored values for prerecorded courses.
			Array.prototype.forEach.call(
				liveOnly.querySelectorAll('input, select'),
				function (input) {
					input.disabled = !isLive;
				}
			);
		}

		select.addEventListener('change', sync);
		sync();
		box.setAttribute('data-tcf-bound', '1');
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// The classic editor can re-render the metabox area (e.g. after a preview
	// or when Gutenberg mounts), so re-bind when new nodes appear.
	if (window.MutationObserver) {
		new window.MutationObserver(function () {
			init();
		}).observe(document.body, { childList: true, subtree: true });
	}
})();
