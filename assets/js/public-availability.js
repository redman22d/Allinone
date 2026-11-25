(function () {
    'use strict';
    function init(root) {
        if (!window.TLSAvailability || !root || root.dataset.tlsBound === '1') return;
        root.dataset.tlsBound = '1';
        var date = root.querySelector('.tls-availability-date');
        var duration = root.querySelector('.tls-availability-duration');
        var type = root.querySelector('.tls-availability-type');
        var status = root.querySelector('.tls-availability-status');
        var slots = root.querySelector('.tls-availability-slots');
        var tutorId = root.getAttribute('data-tutor-id');
        function show(text, isError) {
            status.textContent = text;
            status.classList.toggle('is-error', !!isError);
        }
        function load() {
            if (!date.value || !tutorId) return;
            slots.replaceChildren();
            show(TLSAvailability.labels.loading, false);
            var body = new URLSearchParams({
                action: 'tlms_ps_slots',
                nonce: TLSAvailability.nonce,
                tutor_id: tutorId,
                date: date.value,
                duration: duration.value,
                type: type.value
            });
            fetch(TLSAvailability.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
                .then(function (response) { return response.json().then(function (json) { return { ok: response.ok, json: json }; }); })
                .then(function (result) {
                    var payload = result.json && result.json.data ? result.json.data : {};
                    if (!result.ok || !result.json.success) {
                        show(payload.message || TLSAvailability.labels.failed, true);
                        return;
                    }
                    var available = Array.isArray(payload.slots) ? payload.slots : [];
                    if (!available.length) {
                        show(payload.message || TLSAvailability.labels.empty, false);
                        return;
                    }
                    show(payload.message || '', false);
                    available.forEach(function (slot) {
                        if (!slot || !/^\d{2}:\d{2}$/.test(slot.start || '') || !/^\d{2}:\d{2}$/.test(slot.end || '')) return;
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'tls-slot-button';
                        button.textContent = slot.start + ' – ' + slot.end;
                        button.addEventListener('click', function () {
                            var form = root.parentElement.querySelector('.tlms-ps-request-booking');
                            var preference = form && form.querySelector('[name="preferred_time"]');
                            if (preference) {
                                preference.value = date.value + ' ' + slot.start + '–' + slot.end;
                                preference.dispatchEvent(new Event('input', { bubbles: true }));
                                preference.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                            slots.querySelectorAll('.tls-slot-button').forEach(function (item) { item.classList.remove('is-selected'); });
                            button.classList.add('is-selected');
                            show(TLSAvailability.labels.selected, false);
                        });
                        slots.appendChild(button);
                    });
                })
                .catch(function () { show(TLSAvailability.labels.failed, true); });
        }
        date.addEventListener('change', load);
        duration.addEventListener('change', load);
        type.addEventListener('change', load);
        load();
    }
    function boot() { document.querySelectorAll('.tls-availability').forEach(init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
