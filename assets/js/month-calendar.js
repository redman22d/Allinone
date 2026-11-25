(function () {
    'use strict';
    function init(root) {
        if (!window.TLSMonthCalendar || root.dataset.tlsReady === '1') return;
        root.dataset.tlsReady = '1';
        var tutorId = root.getAttribute('data-tutor-id');
        var monthTitle = root.querySelector('.tls-calendar-month');
        var daysWrap = root.querySelector('.tls-calendar-days');
        var status = root.querySelector('.tls-calendar-status');
        var slotsWrap = root.querySelector('.tls-calendar-slots');
        var duration = root.querySelector('.tls-calendar-duration');
        var type = root.querySelector('.tls-calendar-type');
        var sessionFormat = root.querySelector('.tls-calendar-session-format');
        var prev = root.querySelector('.tls-calendar-prev');
        var next = root.querySelector('.tls-calendar-next');
        var durationMap = {};
        try { durationMap = JSON.parse(root.getAttribute('data-durations') || '{}'); } catch (e) { durationMap = {}; }
        var todayParts = String(root.getAttribute('data-today') || '').split('-').map(Number);
        var today = todayParts.length === 3 && todayParts.every(Number.isFinite)
            ? new Date(todayParts[0], todayParts[1] - 1, todayParts[2])
            : new Date();
        today.setHours(0, 0, 0, 0);
        var view = new Date(today.getFullYear(), today.getMonth(), 1);
        var availableWeekdays = [];
        var selectedDate = '';

        function formatDate(date) {
            return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
        }
        function announce(message, error) {
            status.textContent = message;
            status.classList.toggle('is-error', !!error);
        }
        function ajax(action, data, nonce) {
            var body = new URLSearchParams(Object.assign({ action: action, nonce: nonce }, data));
            return fetch(TLSMonthCalendar.ajax, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (response) {
                return response.json().then(function (json) { return { ok: response.ok, json: json }; });
            });
        }
        function bookingForm() {
            return document.querySelector('.tlms-ps-request-booking[data-tutor-id="' + tutorId + '"]');
        }
        function clearBookingSelection() {
            var booking = bookingForm();
            if (!booking) return;
            ['preferred_date', 'preferred_start', 'preferred_end', 'preferred_duration', 'preferred_timezone'].forEach(function (name) {
                var field = booking.querySelector('[name="' + name + '"]');
                if (field) field.value = '';
            });
            var summary = root.querySelector('.tls-calendar-picked-slot');
            var summaryValue = root.querySelector('.tls-calendar-picked-value');
            if (summary) summary.hidden = true;
            if (summaryValue) summaryValue.textContent = '';
        }
        function syncMethodToBooking() {
            var booking = bookingForm();
            if (!booking || !type) return;
            var formatField = booking.querySelector('[name="session_type"]');
            var format = sessionFormat && sessionFormat.value ? sessionFormat.value : (formatField && formatField.value ? formatField.value : 'one_to_one');
            var available = [];
            function collectAvailable(selectedFormat) {
                available = [];
                Array.prototype.forEach.call(type.options, function (option) {
                    var allowed = selectedFormat === 'group' ? option.getAttribute('data-allow-group') === '1' : option.getAttribute('data-allow-one') === '1';
                    option.disabled = !allowed;
                    option.hidden = !allowed;
                    if (allowed) available.push(option);
                });
            }
            collectAvailable(format);
            if (!available.length) {
                var fallbackFormat = format === 'group' ? 'one_to_one' : 'group';
                collectAvailable(fallbackFormat);
                if (available.length) {
                    if (sessionFormat && sessionFormat.tagName === 'SELECT') sessionFormat.value = fallbackFormat;
                    format = fallbackFormat;
                }
            }
            var selectedOption = type.options[type.selectedIndex];
            if (!selectedOption || selectedOption.disabled) {
                if (available.length) type.value = available[0].value;
            }
            var methodField = booking.querySelector('[name="type"]');
            if (methodField) methodField.value = type.value || '';
            if (formatField) formatField.value = format;
            return available.length > 0;
        }
        function syncDurationOptions() {
            syncMethodToBooking();
            var values = Array.isArray(durationMap[type.value]) ? durationMap[type.value] : [];
            var oldValue = duration.value;
            duration.replaceChildren();
            values.forEach(function (minutes) {
                var option = document.createElement('option');
                option.value = String(minutes);
                option.textContent = String(minutes) + ' min';
                duration.appendChild(option);
            });
            var booking = bookingForm();
            var tryout = booking && booking.querySelector('[name="request_tryout"]');
            var tryoutDuration = tryout ? Number(tryout.getAttribute('data-tryout-duration')) : 0;
            if (tryout && tryout.checked && values.indexOf(tryoutDuration) !== -1) duration.value = String(tryoutDuration);
            else if (values.indexOf(Number(oldValue)) !== -1) duration.value = oldValue;
            duration.disabled = values.length === 0;
        }
        function loadSlots(dateString) {
            selectedDate = dateString;
            clearBookingSelection();
            slotsWrap.replaceChildren();
            announce(TLSMonthCalendar.labels.loading, false);
            ajax('tlms_ps_slots', {
                tutor_id: tutorId, date: dateString,
                duration: duration.value, type: type.value, timezone: root.getAttribute('data-timezone') || '',
                session_type: sessionFormat && sessionFormat.value ? sessionFormat.value : 'one_to_one'
            }, TLSMonthCalendar.slotNonce).then(function (result) {
                var json = result.json || {};
                var data = json.data || {};
                if (!result.ok || !json.success) {
                    announce(data.message || TLSMonthCalendar.labels.error, true);
                    return;
                }
                var slots = Array.isArray(data.slots) ? data.slots : [];
                if (!slots.length) {
                    announce(data.message || TLSMonthCalendar.labels.empty, false);
                    return;
                }
                announce(data.message || '', false);
                slots.forEach(function (slot) {
                    if (!slot || !/^\d{2}:\d{2}$/.test(slot.start || '') || !/^\d{2}:\d{2}$/.test(slot.end || '')) return;
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'tls-calendar-slot';
                    button.textContent = slot.start + ' – ' + slot.end;
                    button.addEventListener('click', function () {
                        var booking = bookingForm();
                        if (booking) {
                            var values = {
                                preferred_date: selectedDate,
                                preferred_start: slot.start,
                                preferred_end: slot.end,
                                preferred_duration: duration.value,
                                preferred_timezone: root.getAttribute('data-timezone') || ''
                            };
                            Object.keys(values).forEach(function (name) {
                                var field = booking.querySelector('[name="' + name + '"]');
                                if (field) field.value = values[name];
                            });
                            var summary = root.querySelector('.tls-calendar-picked-slot');
                            var summaryValue = root.querySelector('.tls-calendar-picked-value');
                            if (summaryValue) {
                                var pickedDate = new Date(selectedDate + 'T00:00:00');
                                var formatOption = sessionFormat && sessionFormat.options ? sessionFormat.options[sessionFormat.selectedIndex] : null;
                                var formatLabel = formatOption ? formatOption.textContent.trim() : (sessionFormat && sessionFormat.value === 'group' ? 'Group format' : '1-to-1');
                                var methodOption = type && type.options ? type.options[type.selectedIndex] : null;
                                var methodLabel = methodOption ? methodOption.textContent.trim() : '';
                                summaryValue.textContent = pickedDate.toLocaleDateString(undefined, { dateStyle: 'medium' }) + ' · ' + slot.start + '–' + slot.end + ' · ' + duration.value + ' min' + (formatLabel ? ' · ' + formatLabel : '') + (methodLabel ? ' · ' + methodLabel : '');
                            }
                            if (summary) summary.hidden = false;
                            syncMethodToBooking();
                        }
                        slotsWrap.querySelectorAll('.tls-calendar-slot').forEach(function (item) { item.classList.remove('is-selected'); });
                        button.classList.add('is-selected');
                        announce(TLSMonthCalendar.labels.picked, false);
                    });
                    slotsWrap.appendChild(button);
                });
            }).catch(function () { announce(TLSMonthCalendar.labels.error, true); });
        }
        function renderDays() {
            daysWrap.replaceChildren();
            monthTitle.textContent = view.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
            var first = new Date(view.getFullYear(), view.getMonth(), 1);
            var offset = first.getDay();
            var start = new Date(view.getFullYear(), view.getMonth(), 1 - offset);
            for (var i = 0; i < 42; i++) {
                var day = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
                var dayButton = document.createElement('button');
                dayButton.type = 'button';
                dayButton.className = 'tls-calendar-day';
                dayButton.textContent = String(day.getDate());
                dayButton.disabled = day < today || day.getMonth() !== view.getMonth() || availableWeekdays.indexOf(day.getDay()) === -1;
                if (day.getMonth() !== view.getMonth()) dayButton.classList.add('is-outside');
                if (day.toDateString() === today.toDateString()) dayButton.classList.add('is-today');
                if (formatDate(day) === selectedDate) dayButton.classList.add('is-selected');
                if (!dayButton.disabled) dayButton.classList.add('has-availability');
                dayButton.addEventListener('click', function (dateValue) {
                    return function () {
                        daysWrap.querySelectorAll('.tls-calendar-day').forEach(function (item) { item.classList.remove('is-selected'); });
                        this.classList.add('is-selected');
                        loadSlots(dateValue);
                    };
                }(formatDate(day)));
                daysWrap.appendChild(dayButton);
            }
        }
        function loadMonth() {
            availableWeekdays = [];
            selectedDate = '';
            clearBookingSelection();
            slotsWrap.replaceChildren();
            announce(TLSMonthCalendar.labels.select, false);
            ajax('tls_month_availability', {
                tutor_id: tutorId, year: view.getFullYear(), month: view.getMonth() + 1
            }, TLSMonthCalendar.nonce).then(function (result) {
                var json = result.json || {};
                if (!result.ok || !json.success) {
                    announce((json.data && json.data.message) || TLSMonthCalendar.labels.error, true);
                    return;
                }
                availableWeekdays = Array.isArray(json.data.weekdays) ? json.data.weekdays.map(Number) : [];
                renderDays();
            }).catch(function () { announce(TLSMonthCalendar.labels.error, true); });
        }
        prev.addEventListener('click', function () {
            var minMonth = new Date(today.getFullYear(), today.getMonth(), 1);
            var candidate = new Date(view.getFullYear(), view.getMonth() - 1, 1);
            if (candidate >= minMonth) { view = candidate; loadMonth(); }
        });
        next.addEventListener('click', function () {
            view = new Date(view.getFullYear(), view.getMonth() + 1, 1);
            loadMonth();
        });
        duration.addEventListener('change', function () { if (selectedDate) loadSlots(selectedDate); });
        var bookingAtInit = bookingForm();
        if (bookingAtInit) {
            var preferredFormatField = bookingAtInit.querySelector('[name="session_type"]');
            if (sessionFormat && sessionFormat.tagName === 'SELECT' && preferredFormatField && Array.prototype.some.call(sessionFormat.options, function (option) { return option.value === preferredFormatField.value; })) {
                sessionFormat.value = preferredFormatField.value;
            }
            var preferredMethodField = bookingAtInit.querySelector('[name="type"]');
            if (preferredMethodField && Array.prototype.some.call(type.options, function (option) { return option.value === preferredMethodField.value; })) {
                type.value = preferredMethodField.value;
            }
        }
        if (sessionFormat && sessionFormat.tagName === 'SELECT') {
            sessionFormat.addEventListener('change', function () {
                syncMethodToBooking();
                syncDurationOptions();
                clearBookingSelection();
                if (selectedDate) loadSlots(selectedDate);
            });
        }
        var tryoutCheckbox = bookingAtInit && bookingAtInit.querySelector('[name="request_tryout"]');
        if (tryoutCheckbox) {
            tryoutCheckbox.addEventListener('change', function () {
                syncDurationOptions();
                if (selectedDate) loadSlots(selectedDate);
            });
        }
        type.addEventListener('change', function () {
            syncMethodToBooking();
            syncDurationOptions();
            clearBookingSelection();
            if (selectedDate) loadSlots(selectedDate);
        });
        syncMethodToBooking();
        syncDurationOptions();
        loadMonth();
    }
    function boot() { document.querySelectorAll('.tls-month-calendar').forEach(init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
}());
