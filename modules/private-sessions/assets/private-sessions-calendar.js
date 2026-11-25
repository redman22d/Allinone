(function () {
    'use strict';

    /*
     * Tutor LMS Pro 4.x Calendar does NOT consume arbitrary localized events or
     * render HTML overlays. Its React calendar calls admin-ajax.php with
     * action=get_calendar_materials and then groups the returned `data.response`
     * records by post_type/meta_info.
     *
     * This bridge intercepts only that calendar response and adds private-session
     * records using the exact native `tutor-google-meet` shape the calendar already
     * knows how to render. Nothing is painted on top of the calendar DOM.
     */
    var cfg = window.TLMSPSCalendar || {};
    var sourceEvents = Array.isArray(cfg.events) ? cfg.events : [];
    if (!sourceEvents.length || !window.fetch) return;

    var originalFetch = window.fetch;
    var installedFlag = '__tlmsPsCalendarBridgeInstalled';
    if (window[installedFlag]) return;
    window[installedFlag] = true;

    function getFormValue(body, name) {
        try {
            if (body instanceof FormData) {
                var value = body.get(name);
                return value == null ? '' : String(value);
            }
            if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
                return body.get(name) || '';
            }
            if (typeof body === 'string') {
                return new URLSearchParams(body).get(name) || '';
            }
        } catch (e) {}
        return '';
    }

    function isCalendarRequest(input, init) {
        var url = '';
        try {
            url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
        } catch (e) {}
        if (!url) url = String(cfg.ajaxUrl || '');
        if (url.indexOf('admin-ajax.php') === -1) return false;

        var body = init && init.body;
        if (!body && input && input instanceof Request) body = input.body;
        var action = getFormValue(body, 'action');
        return action === 'get_calendar_materials';
    }

    function nativeEvent(ev) {
        var date = String(ev.date || ev.event_date || '').slice(0, 10);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return null;

        var start = String(ev.start_datetime || ev.start || '').replace('T', ' ');
        var end = String(ev.end_datetime || ev.end || '').replace('T', ' ');
        var title = String(ev.title || ev.name || cfg.title || 'Private tutoring session');
        var url = String(ev.url || ev.link || cfg.dashboardUrl || '#');

        /*
         * CalendarGrid/EventCard expects:
         *   post_type = tutor-google-meet
         *   meta_info.gm_start_date = local calendar date
         *   meta_info.start_datetime = UTC datetime string
         *   post_date = any valid date string (used by list view)
         */
        return {
            ID: 'tlms-private-session-' + String(ev.session_id || ev.id || date + '-' + start),
            post_title: title,
            post_content: '',
            post_parent: 0,
            post_type: 'tutor-google-meet',
            post_status: 'publish',
            post_date: date + ' ' + (String(ev.start_time || start).slice(11, 19) || '00:00:00'),
            guid: url,
            course_info: null,
            meta_info: {
                gm_start_date: date,
                start_datetime: start,
                expire_date: end || start,
                is_expired: false
            },
            private_session: true,
            session_id: Number(ev.session_id || 0),
            source: 'tlms_private_sessions'
        };
    }

    function monthMatches(ev, year, monthZeroBased) {
        var date = String(ev.date || ev.event_date || '').slice(0, 10);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return false;
        var y = parseInt(date.slice(0, 4), 10);
        var m = parseInt(date.slice(5, 7), 10) - 1;
        return y === year && m === monthZeroBased;
    }

    function mergeIntoPayload(payload, year, monthZeroBased) {
        if (!payload || !payload.data || !Array.isArray(payload.data.response)) return payload;

        var response = payload.data.response.slice();
        var existing = Object.create(null);
        response.forEach(function (event) {
            if (event && event.ID != null) existing[String(event.ID)] = true;
            if (event && event.id != null) existing[String(event.id)] = true;
        });

        sourceEvents.forEach(function (source) {
            if (!monthMatches(source, year, monthZeroBased)) return;
            var event = nativeEvent(source);
            if (!event) return;
            var key = String(event.ID);
            if (!existing[key]) {
                response.push(event);
                existing[key] = true;
            }
        });

        payload.data.response = response;
        return payload;
    }

    window.fetch = function (input, init) {
        if (!isCalendarRequest(input, init)) {
            return originalFetch.apply(this, arguments);
        }

        var body = init && init.body;
        var year = parseInt(getFormValue(body, 'year'), 10);
        var month = parseInt(getFormValue(body, 'month'), 10);

        /* FormData may belong to a Request object instead of init.body. */
        if ((!Number.isFinite(year) || !Number.isFinite(month)) && input && input instanceof Request) {
            try {
                var clone = input.clone();
                return clone.formData().then(function (fd) {
                    var y = parseInt(getFormValue(fd, 'year'), 10);
                    var m = parseInt(getFormValue(fd, 'month'), 10);
                    return originalFetch.apply(this, arguments).then(function (response) {
                        return rewriteResponse(response, y, m);
                    });
                }.bind(this));
            } catch (e) {}
        }

        return originalFetch.apply(this, arguments).then(function (response) {
            return rewriteResponse(response, year, month);
        });
    };

    function rewriteResponse(response, year, month) {
        if (!response || typeof response.clone !== 'function') return response;
        if (!Number.isFinite(year) || !Number.isFinite(month)) return response;

        return response.clone().text().then(function (text) {
            var payload;
            try { payload = JSON.parse(text); } catch (e) { return response; }
            if (!payload || payload.success === false) return response;

            var before = payload.data && Array.isArray(payload.data.response)
                ? payload.data.response.length : -1;
            payload = mergeIntoPayload(payload, year, month);
            var after = payload.data && Array.isArray(payload.data.response)
                ? payload.data.response.length : -1;

            if (before === after) return response;

            var headers = new Headers(response.headers || {});
            headers.delete('content-length');
            headers.set('content-type', 'application/json');
            return new Response(JSON.stringify(payload), {
                status: response.status,
                statusText: response.statusText,
                headers: headers
            });
        }).catch(function () {
            return response;
        });
    }
})();
