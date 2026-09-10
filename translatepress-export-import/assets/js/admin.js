/* TranslatePress Export / Import — admin tabs + UX */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	ready(function () {
		// Tabs.
		var tabs = document.querySelectorAll('.tpei-tabs .nav-tab');
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function (e) {
				e.preventDefault();
				var name = tab.getAttribute('data-tab');
				tabs.forEach(function (t) { t.classList.remove('nav-tab-active'); });
				tab.classList.add('nav-tab-active');
				document.querySelectorAll('.tpei-tab').forEach(function (panel) {
					panel.hidden = (panel.id !== 'tpei-tab-' + name);
				});
				try {
					var url = new URL(window.location.href);
					url.searchParams.set('tab', name);
					window.history.replaceState(null, '', url.toString());
				} catch (err) { /* ignore */ }
			});
		});

		// Show CSV delimiter row only for CSV format.
		var formatRadios = document.querySelectorAll('input[name="format"]');
		var delimiterRow = document.querySelector('.tpei-delimiter-row');
		function toggleDelimiter() {
			if (!delimiterRow) return;
			var checked = document.querySelector('input[name="format"]:checked');
			delimiterRow.style.display = (checked && checked.value === 'csv') ? '' : 'none';
		}
		formatRadios.forEach(function (r) { r.addEventListener('change', toggleDelimiter); });
		toggleDelimiter();

		// Confirm overwrite imports.
		var importForm = document.querySelector('form[action*="admin-post.php"] input[name="tpei_file"]');
		if (importForm) {
			importForm.closest('form').addEventListener('submit', function (e) {
				var mode = document.querySelector('input[name="update_mode"]:checked');
				var dry = document.querySelector('input[name="dry_run"]');
				if (mode && mode.value === 'overwrite' && dry && !dry.checked) {
					if (!window.confirm('Overwrite mode will replace existing translations with the file values. Continue?')) {
						e.preventDefault();
					}
				}
			});
		}
	});
})();
