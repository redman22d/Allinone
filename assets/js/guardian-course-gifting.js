(function () {
    'use strict';

    var config = window.TLSGuardianGiftRecipients || {};
    var children = Array.isArray(config.children) ? config.children : [];
    var labels = config.labels || {};
    if (!children.length) return;

    var selectedChild = null;
    var observer = null;
    var modalId = 'tutor-gift-this-course-modal';

    function notify(message) {
        if (window.TutorCore && window.TutorCore.toast && typeof window.TutorCore.toast.info === 'function') {
            window.TutorCore.toast.info(message);
        } else if (typeof window.tutor_toast === 'function') {
            window.tutor_toast('Ustudy', message, 'info');
        }
    }

    function setValue(input, value) {
        if (!input) return;
        var descriptor = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
        if (descriptor && descriptor.set) descriptor.set.call(input, value);
        else input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function findFields(modal) {
        return {
            name: modal.querySelector('#tutor-gift-this-course-form input[name="recipient_name"]'),
            email: modal.querySelector('#tutor-gift-this-course-form input[name="recipient_email"]')
        };
    }

    function applyRecipient() {
        var modal = document.getElementById(modalId);
        if (!modal) return;
        var fields = findFields(modal);
        if (!fields.email || !fields.name) return;

        if (selectedChild) {
            setValue(fields.name, selectedChild.name);
            setValue(fields.email, selectedChild.email);
            [fields.name, fields.email].forEach(function (field) {
                field.readOnly = true;
                field.setAttribute('aria-readonly', 'true');
                field.classList.add('tls-guardian-gift-recipient-locked');
            });
        } else {
            [fields.name, fields.email].forEach(function (field) {
                if (field.classList.contains('tls-guardian-gift-recipient-locked')) {
                    setValue(field, '');
                }
                field.readOnly = false;
                field.removeAttribute('aria-readonly');
                field.classList.remove('tls-guardian-gift-recipient-locked');
            });
        }

        var form = modal.querySelector('#tutor-gift-this-course-form');
        if (form && !form.dataset.tlsRecipientGuard) {
            form.dataset.tlsRecipientGuard = '1';
            form.addEventListener('submit', function (event) {
                if (!selectedChild) return;
                var current = findFields(modal);
                if (!current.email || !current.name
                    || current.email.value.trim().toLowerCase() !== selectedChild.email.toLowerCase()
                    || current.name.value.trim() !== selectedChild.name) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    notify(labels.invalid || 'The gift recipient must match the selected linked child.');
                }
            }, true);
        }
    }

    function attachToNativeButton() {
        var button = document.querySelector('button.tutor-btn-gift[data-tutor-modal-target="' + modalId + '"]');
        if (!button || button.dataset.tlsRecipientPicker) return;

        var wrapper = document.createElement('div');
        wrapper.className = 'tls-parent-gift-recipient-picker';
        var label = document.createElement('label');
        label.setAttribute('for', 'tls-parent-gift-recipient');
        label.textContent = labels.label || 'Who is this course for?';
        var select = document.createElement('select');
        select.id = 'tls-parent-gift-recipient';
        select.className = 'tls-parent-gift-recipient-picker__select';
        select.required = true;

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = labels.choose || 'Choose a linked child';
        select.appendChild(placeholder);

        children.forEach(function (child, index) {
            if (!child || !child.email || !child.name) return;
            var option = document.createElement('option');
            option.value = String(index);
            option.textContent = child.name;
            select.appendChild(option);
        });

        var other = document.createElement('option');
        other.value = '__other__';
        other.textContent = labels.other || 'Someone else (enter details in Tutor LMS form)';
        select.appendChild(other);

        var hint = document.createElement('p');
        hint.className = 'tls-parent-gift-recipient-picker__hint';
        hint.textContent = labels.hint || 'Choose a linked child to prefill the native Tutor LMS gift form. Tutor LMS handles checkout and gift delivery.';
        wrapper.appendChild(label);
        wrapper.appendChild(select);
        wrapper.appendChild(hint);
        button.parentNode.insertBefore(wrapper, button);
        button.dataset.tlsRecipientPicker = '1';

        select.addEventListener('change', function () {
            if (select.value === '__other__' || select.value === '') {
                selectedChild = null;
            } else {
                selectedChild = children[Number(select.value)] || null;
            }
            applyRecipient();
        });

        // Capture before Tutor's modal controller handles the click so the
        // recipient can be filled as soon as the existing native modal opens.
        button.addEventListener('click', function (event) {
            if (!select.value) {
                event.preventDefault();
                event.stopImmediatePropagation();
                notify(labels.required || 'Choose a linked child or select another recipient before continuing.');
                select.focus();
                return;
            }
            selectedChild = select.value === '__other__' ? null : (children[Number(select.value)] || null);
            window.setTimeout(applyRecipient, 0);
            window.setTimeout(applyRecipient, 100);
            window.setTimeout(applyRecipient, 300);
        }, true);
    }

    function startObserver() {
        attachToNativeButton();
        if (observer || !document.documentElement) return;
        observer = new MutationObserver(function () {
            attachToNativeButton();
            applyRecipient();
        });
        observer.observe(document.documentElement, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'style', 'aria-hidden']
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startObserver);
    } else {
        startObserver();
    }
})();
