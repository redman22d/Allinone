/**
 * Tutor LMS Course Format — Course Builder integration.
 *
 * Registers the custom fields into the Tutor LMS course builder via the public
 * `window.Tutor.CourseBuilder.Basic.registerField()` slot API, then keeps the
 * live-session fields hidden while the course type is "Prerecorded".
 *
 * Facts about Tutor LMS 4.0.7 this relies on (verified against the shipped
 * bundle `assets/js/tutor-course-builder.js`):
 *
 *  - `window.Tutor` is assigned inside a React `useEffect` in
 *    CourseBuilderSlotContext, so it does NOT exist when this script first
 *    runs. We poll for it.
 *  - The Basics section exposes exactly two slots: `after_description` and
 *    `after_settings`.
 *  - Every rendered field is wrapped in `[data-cy="form-field-wrapper"]` and
 *    the actual control carries `name="<field name>"`.
 *  - Registered field names are POSTed flat on save and read back from the
 *    course details response — both handled on the PHP side.
 */
(function () {
	'use strict';

	var cfg = window.tcfCourseFormat;
	if (!cfg || !cfg.fields || !cfg.fields.length) {
		return;
	}

	var WRAPPER_SELECTOR = '[data-cy="form-field-wrapper"]';
	var DEPENDENT_FIELDS = ['tcf_course_format', 'tcf_session_count', 'tcf_session_length'];
	var TYPE_FIELD = 'tcf_course_type';

	var registered = false;
	var pollTimer = null;

	/* ------------------------------------------------------------------ *
	 * 1. Registration
	 * ------------------------------------------------------------------ */

	function getApi() {
		if (window.Tutor &&
			window.Tutor.CourseBuilder &&
			window.Tutor.CourseBuilder.Basic &&
			typeof window.Tutor.CourseBuilder.Basic.registerField === 'function') {
			return window.Tutor.CourseBuilder.Basic;
		}
		return null;
	}

	function buildRules(field) {
		if (!cfg.requireLive || field.name === TYPE_FIELD) {
			return field.rules || undefined;
		}

		var message = (cfg.i18n && cfg.i18n.required) || 'This field is required';
		var liveTypes = cfg.liveTypes || [];

		return {
			validate: function (value, formValues) {
				var type = formValues ? formValues[TYPE_FIELD] : '';
				if (liveTypes.indexOf(type) === -1) {
					return true;
				}
				var empty = value === '' || value === null || value === undefined;
				return empty ? message : true;
			}
		};
	}

	function registerFields() {
		var api = getApi();
		if (!api) {
			return false;
		}

		cfg.fields.forEach(function (field) {
			var definition = {};
			Object.keys(field).forEach(function (key) {
				definition[key] = field[key];
			});

			var rules = buildRules(field);
			if (rules) {
				definition.rules = rules;
			}

			api.registerField(cfg.slot || 'after_settings', definition);
		});

		return true;
	}

	function boot() {
		if (registered) {
			return;
		}
		if (registerFields()) {
			registered = true;
			if (pollTimer) {
				window.clearInterval(pollTimer);
				pollTimer = null;
			}
			startConditional();
		}
	}

	/* ------------------------------------------------------------------ *
	 * 2. Conditional visibility
	 * ------------------------------------------------------------------ */

	function typeOptions() {
		var match = cfg.fields.filter(function (field) {
			return field.name === TYPE_FIELD;
		})[0];
		return (match && match.options) || [];
	}

	/**
	 * Read the selected course type straight from the DOM.
	 *
	 * Radio fields render real `input[type=radio]` elements holding the raw
	 * value. Select fields render a read-only text input showing the option
	 * LABEL, so we map the label back to its value.
	 *
	 * @return {string} Course type value, or '' when it cannot be determined.
	 */
	function readCourseType() {
		var checked = document.querySelector('input[type="radio"][name="' + TYPE_FIELD + '"]:checked');
		if (checked) {
			return checked.value;
		}

		if (document.querySelector('input[type="radio"][name="' + TYPE_FIELD + '"]')) {
			return '';
		}

		var text = document.querySelector('input[name="' + TYPE_FIELD + '"]');
		if (!text) {
			return '';
		}

		var label = text.value;
		var option = typeOptions().filter(function (item) {
			return item.label === label;
		})[0];

		return option ? option.value : '';
	}

	function wrapperFor(name) {
		var input = document.querySelector('input[name="' + name + '"]');
		if (!input || !input.closest) {
			return null;
		}

		var wrapper = input.closest(WRAPPER_SELECTOR);
		if (wrapper) {
			wrapper.setAttribute('data-tcf-field', name);
		}
		return wrapper;
	}

	function setVisibility(wrapper, visible) {
		if (!wrapper) {
			return;
		}

		// Inline style is required: the wrapper carries an emotion
		// `display: flex` rule that would beat the UA `[hidden]` rule.
		var next = visible ? '' : 'none';
		if (wrapper.style.display !== next) {
			wrapper.style.display = next;
		}
		wrapper.setAttribute('aria-hidden', visible ? 'false' : 'true');
	}

	function applyConditional() {
		// Nothing mounted yet: do not hide anything.
		if (!document.querySelector('input[name="' + TYPE_FIELD + '"]')) {
			return;
		}

		var live = (cfg.liveTypes || []).indexOf(readCourseType()) !== -1;

		DEPENDENT_FIELDS.forEach(function (name) {
			setVisibility(wrapperFor(name), live);
		});
	}

	function startConditional() {
		applyConditional();

		if (window.MutationObserver) {
			new window.MutationObserver(applyConditional)
				.observe(document.body, { childList: true, subtree: true });
		}

		// Safety net for in-place updates that do not mutate the tree, e.g. the
		// select's displayed label changing without adding/removing nodes.
		window.setInterval(applyConditional, 400);

		['click', 'change', 'input'].forEach(function (eventName) {
			document.addEventListener(eventName, applyConditional, true);
		});
	}

	/* ------------------------------------------------------------------ *
	 * 3. Kick off
	 * ------------------------------------------------------------------ */

	boot();

	if (!registered) {
		var attempts = 0;
		pollTimer = window.setInterval(function () {
			attempts += 1;
			boot();
			if (registered || attempts > 200) {
				window.clearInterval(pollTimer);
				pollTimer = null;
			}
		}, 100);
	}
})();
