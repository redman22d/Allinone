(function () {
    'use strict';

    var settings = window.TLSAgeSafetyUI || {};
    var overlay = null;
    var previousFocus = null;
    var closeHandler = null;
    var previousBodyOverflow = '';

    function closeNotice() {
        if (!overlay) return;
        if (closeHandler) document.removeEventListener('keydown', closeHandler, true);
        overlay.remove();
        overlay = null;
        closeHandler = null;
        document.body.style.overflow = previousBodyOverflow;
        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
        previousFocus = null;
    }

    function showNotice(message, title) {
        if (!document.body) return;
        message = String(message || settings.message || 'Please ask your parent or guardian for help with this action.');
        title = String(title || settings.title || 'This action needs a parent or guardian');

        if (overlay) {
            var existingTitle = overlay.querySelector('.tls-age-notice-dialog h2');
            var existingMessage = overlay.querySelector('.tls-age-notice-dialog p');
            if (existingTitle) existingTitle.textContent = title;
            if (existingMessage) existingMessage.textContent = message;
            return;
        }

        previousFocus = document.activeElement;
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        overlay = document.createElement('div');
        overlay.className = 'tls-age-notice-backdrop';
        overlay.setAttribute('data-tls-age-notice', '1');

        var dialog = document.createElement('section');
        dialog.className = 'tls-age-notice-dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'tls-age-notice-title');
        dialog.setAttribute('aria-describedby', 'tls-age-notice-message');

        var icon = document.createElement('div');
        icon.className = 'tls-age-notice-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '🔒';

        var heading = document.createElement('h2');
        heading.id = 'tls-age-notice-title';
        heading.textContent = title;

        var copy = document.createElement('p');
        copy.id = 'tls-age-notice-message';
        copy.textContent = message;

        var close = document.createElement('button');
        close.className = 'tls-age-notice-close';
        close.type = 'button';
        close.textContent = String(settings.close || 'Got it');
        close.addEventListener('click', closeNotice);

        dialog.appendChild(icon);
        dialog.appendChild(heading);
        dialog.appendChild(copy);
        dialog.appendChild(close);
        overlay.appendChild(dialog);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) closeNotice();
        });
        document.body.appendChild(overlay);

        closeHandler = function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeNotice();
            } else if (event.key === 'Tab') {
                // This dialog has one active control; keep keyboard focus inside it.
                event.preventDefault();
                close.focus();
            }
        };
        document.addEventListener('keydown', closeHandler, true);
        close.focus();
    }

    var actionSelector = [
        '.tutor-course-enroll-button',
        '.tutor-btn-enroll',
        '.tutor-course-purchase-btn',
        '.tutor-course-add-to-cart',
        '.tutor-course-buy-button',
        '.tutor-enroll-button',
        'a[href*="tutor_course_checkout"]',
        'a[href*="enroll"]',
        'a[href*="enrol"]',
        'a[href*="checkout"]',
        'form[action*="checkout"] button[type="submit"]',
        'form[action*="enroll"] button[type="submit"]',
        'form[action*="enrol"] button[type="submit"]'
    ].join(',');

    var blockedLabel = /^(?:enrol(?:l)?(?:\s+now)?|buy(?:\s+now)?|purchase(?:\s+now)?|checkout|pay\s+now|add\s+to\s+cart|join\s+(?:this\s+)?course|register\s+for\s+course|write\s+(?:a\s+)?review|submit\s+(?:a\s+)?review|post\s+(?:a\s+)?comment|ask\s+(?:a\s+)?question|send\s+(?:a\s+)?message)\s*[→›]?$/i;

    function isSafetyResponse(data) {
        var payload = data && data.data ? data.data : data;
        if (!payload) return false;
        var code = String(payload.code || '');
        var message = String(payload.message || '');
        return /^tls_child_/i.test(code) || /parent or guardian|guardian approval|cannot enrol|cannot enroll|disabled for this account|not available for this account|learning-safety restrictions/i.test(message);
    }

    function isNativeFreeCourseEnrollmentForm(element) {
        if (!element) return false;

        // Tutor LMS Core only renders this native enrol form for a course that
        // its own template treats as non-purchasable/free. A theme or page cache
        // may cause the server-localized allow-list course ID to be stale or 0,
        // so do not let that presentation detail block the child in the browser.
        // This is NOT an authorization bypass: tutor_before_enroll and
        // tutor_enroll_data re-check the real Tutor price type, guardian approval,
        // age band and saved permission on the server before creating enrolment.
        var form = element.tagName === 'FORM' ? element : (element.closest ? element.closest('form') : null);
        if (!form) return false;

        // Themes commonly override Tutor's template and omit its CSS class or
        // method attribute. The hidden Tutor action + course ID are the stable server contract.
        // The server independently verifies the actual child permission and
        // canonical Tutor course price before allowing an enrolment record.
        var actionField = form.querySelector('input[name="tutor_course_action"]');
        var courseField = form.querySelector('input[name="tutor_course_id"]');
        if (!actionField || actionField.value !== '_tutor_course_enroll_now' || !courseField) return false;
        return parseInt(courseField.value || 0, 10) > 0;
    }

    function isFeedbackAction(element) {
        if (!element) return false;
        if (element.closest('.write-course-review-link-btn,.tutor-course-enrolled-review-wrap,.tutor-write-review-form,.tlms-ps-rating-cta,.tutor-comment-box,.tutor-comment-form,.tutor-qna-form,.tutor-add-question,.tutor-lesson-comment-form,.tutor-write-comment,.tutor-comment-textarea')) return true;
        var label = (element.innerText || element.textContent || '').replace(/\s+/g, ' ').replace(/[!.,:;]+$/g, '').trim();
        return /\b(?:write|submit|edit|post|ask)\b.{0,24}\b(?:review|comment|question)\b/i.test(label);
    }

    function isRestrictedAction(element) {
        if (!element || element.closest('[data-tls-age-allow="1"]')) return false;
        if (settings.feedbackAllowed && isFeedbackAction(element)) return false;
        if (isNativeFreeCourseEnrollmentForm(element)) return false;
        if (element.matches(actionSelector) || element.closest(actionSelector)) return true;

        var href = element.getAttribute('href') || '';
        if (/(?:tutor_course_checkout|enroll|enrol|checkout|add-to-cart|purchase)/i.test(href)) return true;

        var action = element.getAttribute('data-action') || element.getAttribute('name') || '';
        if (/(?:enroll|enrol|checkout|purchase|pay_now)/i.test(action)) return true;

        var label = (element.innerText || element.textContent || '').replace(/\s+/g, ' ').replace(/[!.,:;]+$/g, '').trim();
        return blockedLabel.test(label) || /\b(?:enrol(?:l|lment|ment)?|buy(?:\s+now|\s+course)?|purchase|checkout|pay\s+now|add\s+to\s+(?:cart|basket)|write\s+(?:a\s+)?review|submit\s+(?:a\s+)?review|post\s+(?:a\s+)?comment|ask\s+(?:a\s+)?question|send\s+(?:a\s+)?message)\b/i.test(label);
    }

    function markRestrictedActions() {
        var candidates = document.querySelectorAll('a,button,input[type="submit"],[role="button"]');
        candidates.forEach(function (element) {
            if (isRestrictedAction(element)) {
                element.classList.add('tls-action-restricted');
                element.setAttribute('aria-disabled', 'true');
                if (!element.getAttribute('title')) {
                    element.setAttribute('title', settings.title || 'Ask a parent or guardian for help');
                }
            }
        });
    }

    if (settings.restricted) {
        document.addEventListener('click', function (event) {
            var element = event.target && event.target.closest ? event.target.closest('a,button,input[type="submit"],[role="button"]') : null;
            if (settings.feedbackAllowed && isFeedbackAction(element)) {
                return;
            }
            if (!isRestrictedAction(element)) return;
            event.preventDefault();
            event.stopPropagation();
            if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
            showNotice(settings.message, settings.title);
        }, true);

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.matches) return;
            if (isNativeFreeCourseEnrollmentForm(form)) return;
            var target = (form.getAttribute('action') || '') + ' ' + (form.querySelector('input[name="tutor_action"],input[name="action"]') || {}).value;
            if (/(?:checkout|enroll|enrol|purchase|pay_now)/i.test(target) || form.querySelector(actionSelector)) {
                event.preventDefault();
                event.stopPropagation();
                if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
                showNotice(settings.message, settings.title);
            }
        }, true);

        // Tutor and WordPress AJAX restrictions return a structured 403. Show the
        // same notice instead of leaving the learner with a raw JSON/error response.
        if (window.jQuery) {
            window.jQuery(document).on('ajaxError.tlsAgeSafety', function (event, xhr) {
                if (!xhr || xhr.status !== 403) return;
                var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : xhr.responseJSON;
                if (isSafetyResponse(data)) {
                    showNotice(data.message || settings.message, data.title || settings.title);
                }
            });
        }

        if (typeof window.fetch === 'function') {
            var originalFetch = window.fetch;
            window.fetch = function () {
                return originalFetch.apply(this, arguments).then(function (response) {
                    if (response && response.status === 403 && response.clone) {
                        response.clone().json().then(function (data) {
                            var payload = data && data.data ? data.data : data;
                            if (isSafetyResponse(payload)) {
                                showNotice(payload.message || settings.message, payload.title || settings.title);
                            }
                        }).catch(function () {});
                    }
                    return response;
                });
            };
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', markRestrictedActions, { once: true });
        } else {
            markRestrictedActions();
        }

    }

    if (settings.flash && settings.flash.message) {
        showNotice(settings.flash.message, settings.flash.title || settings.title);
    }
}());
