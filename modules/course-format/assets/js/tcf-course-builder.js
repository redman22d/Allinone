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
			applyAdministratorRestrictions();
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
			new window.MutationObserver(function () {
				applyConditional();
				applyAdministratorRestrictions();
			})
				.observe(document.body, { childList: true, subtree: true });
		}

		// Safety net for in-place updates that do not mutate the tree, e.g. the
		// select's displayed label changing without adding/removing nodes.
		window.setInterval(function () {
			applyConditional();
			applyAdministratorRestrictions();
		}, 400);

		['click', 'change', 'input'].forEach(function (eventName) {
			document.addEventListener(eventName, function () {
				applyConditional();
				applyAdministratorRestrictions();
			}, true);
		});
	}


	/* ------------------------------------------------------------------ *
	 * 3. Administrator restrictions
	 * ------------------------------------------------------------------ */

	var CATEGORY_TOKENS = [
		'add category',
		'+ add category',
		'add new category'
	];

	function textLooksLikeCategoryAdd(text) {
		text = (text || '').replace(/\s+/g, ' ').trim().toLowerCase();
		return CATEGORY_TOKENS.indexOf(text) !== -1 || (
			text.indexOf('add') !== -1 && text.indexOf('category') !== -1
		);
	}

	function hideCategoryAddControls() {
		if (!cfg.hideInstructorCategoryAdd) {
			return;
		}

		var candidates = document.querySelectorAll(
			'button, a, [role="button"]'
		);

		Array.prototype.forEach.call(candidates, function (node) {
			var text = node.textContent || '';
			if (!textLooksLikeCategoryAdd(text)) {
				return;
			}

			// Do not hide the category select itself; only an add/create control.
			if (node.querySelector('input, select, textarea')) {
				return;
			}

			node.style.display = 'none';
			node.setAttribute('aria-hidden', 'true');
			node.setAttribute('data-tcf-category-add-hidden', 'true');
		});
	}

	function assessmentFileSizeInputs() {
		return document.querySelectorAll(
			'input[name*="upload_file_size_limit"], ' +
			'input[name*="upload_file_size_limit" i], ' +
			'[data-name*="upload_file_size_limit"] input'
		);
	}

	function wrapperForElement(element) {
		if (!element || !element.closest) {
			return null;
		}

		return element.closest(WRAPPER_SELECTOR);
	}

	function enforceAssessmentFileSizeUI() {
		var inputs = assessmentFileSizeInputs();
		var cap = parseFloat(cfg.assessmentFileSizeMb || 0);
		if (!cap || !isFinite(cap)) {
			return;
		}

		Array.prototype.forEach.call(inputs, function (input) {
			input.value = String(cap);
			input.setAttribute('value', String(cap));
			input.setAttribute('max', String(cap));
			input.setAttribute('readonly', 'readonly');
			input.setAttribute('aria-readonly', 'true');
			input.style.pointerEvents = 'none';

			var wrapper = wrapperForElement(input);
			if (wrapper) {
				wrapper.style.opacity = '0.75';
				wrapper.setAttribute('data-tcf-global-file-size-limit', String(cap));
				var existing = wrapper.querySelector('[data-tcf-file-size-notice]');
				if (!existing) {
					existing = document.createElement('div');
					existing.setAttribute('data-tcf-file-size-notice', 'true');
					existing.style.marginTop = '6px';
					existing.style.fontSize = '12px';
					existing.textContent = 'Maximum file size is controlled by the site administrator: ' + cap + ' MB.';
					wrapper.appendChild(existing);
				}
			}
		});
	}

	function applyAdministratorRestrictions() {
		hideCategoryAddControls();
		enforceAssessmentFileSizeUI();
		guardInstructorImageUploads();
	}

	/* ------------------------------------------------------------------ *
	 * 3b. Instructor image upload size guard
	 *
	 * Server-side, TCF_Plugin::enforce_instructor_image_upload_limit()
	 * rejects oversized instructor images after the full file has already
	 * been uploaded. This is a best-effort client-side pre-check so an
	 * instructor picking an obviously-too-large image (e.g. a 40MB photo
	 * against a 5MB cap) finds out immediately instead of waiting through
	 * a full upload just to be rejected.
	 *
	 * We can't reliably tell, client-side, whether a given wp.media upload
	 * is the course featured image, a profile photo, or something else
	 * (see the matching note on detect_instructor_image_context() in
	 * class-tcf-plugin.php), so this intentionally uses the single largest
	 * of the three admin-configured limits as its threshold: it only ever
	 * blocks a file that is guaranteed to be rejected no matter which
	 * context actually applies. The server-side check remains the source
	 * of truth and still runs on every upload regardless of what happens
	 * here.
	 * ------------------------------------------------------------------ */

	var imageGuardBound = false;

	function guardInstructorImageUploads() {
		if (imageGuardBound || !cfg.limitInstructorImages) {
			return;
		}

		var capMb = parseFloat(cfg.imageSizeLimitMb || 0);
		if (!capMb || !isFinite(capMb)) {
			return;
		}

		// wp.Uploader.queue is the Backbone collection every WordPress
		// media-modal upload (the standard picker Tutor's course builder
		// opens for the featured image, and the one most profile-photo
		// UIs use too) adds an Attachment model to as soon as a file is
		// picked/dropped, before the upload request is sent. Binding here
		// covers any wp.media modal on the page regardless of which
		// button opened it, without needing to know Tutor's internal
		// field/selector names.
		if (!window.wp || !window.wp.Uploader || !window.wp.Uploader.queue) {
			return;
		}

		imageGuardBound = true;
		var capBytes = capMb * 1024 * 1024;

		window.wp.Uploader.queue.on('add', function (attachment) {
			var file = attachment && typeof attachment.get === 'function' ? attachment.get('file') : null;
			if (!file || !file.type || file.type.indexOf('image/') !== 0) {
				return;
			}
			if (file.size <= capBytes) {
				return;
			}

			// Pull the file back out of the queue so WordPress never
			// starts uploading it.
			window.wp.Uploader.queue.remove(attachment);

			var message = (cfg.i18n && cfg.i18n.imageTooLarge
				? cfg.i18n.imageTooLarge
				: 'This image is larger than the %s MB limit set by the site administrator and was not uploaded.'
			).replace('%s', String(capMb));
			window.alert(message);
		});
	}

	/* ------------------------------------------------------------------ *
	 * 4. Kick off
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
