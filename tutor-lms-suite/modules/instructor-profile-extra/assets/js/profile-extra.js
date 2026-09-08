(function () {
    "use strict";

    var BRAND_PATHS = {
        instagram: "M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5zm0 2a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3H7zm5 3a5 5 0 1 1 0 10 5 5 0 0 1 0-10zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6zm5-3.5a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4z",
        telegram: "M21.9 4.5 18.4 21c-.3 1.2-1 1.5-2 .9l-5.5-4.1-2.7 2.6c-.3.3-.5.5-1 .5l.4-5.6L18.6 6c.5-.4-.1-.6-.7-.2L6.4 13.3 1 11.6c-1.2-.4-1.2-1.2.2-1.8L20.3 3.7c1-.4 1.9.2 1.6.8z",
        whatsapp: "M20.5 3.5A11.9 11.9 0 0 0 12 0C5.4 0 .1 5.3.1 11.9c0 2.1.5 4.1 1.6 5.9L0 24l6.4-1.7a11.9 11.9 0 0 0 5.6 1.4h.1c6.5 0 11.8-5.3 11.8-11.9 0-3.1-1.2-6.1-3.4-8.3zM12 21.6h-.1c-1.8 0-3.6-.5-5.1-1.4l-.4-.2-3.8 1 1-3.7-.2-.4a9.7 9.7 0 0 1-1.5-5.1C1.9 6.5 6.4 2 12 2c2.6 0 5 1 6.8 2.8 1.8 1.8 2.8 4.2 2.8 6.8 0 5.5-4.3 10-9.6 10zm5.5-7.5c-.3-.2-1.9-.9-2.2-1-.3-.1-.5-.2-.7.2-.2.3-.8 1-.9 1.2-.2.2-.3.2-.6.1-1.5-.7-2.5-1.3-3.5-2.9-.3-.5.3-.5.8-1.6.1-.2.1-.4 0-.6-.1-.2-.7-1.8-1-2.5-.3-.7-.5-.6-.7-.6h-.6c-.2 0-.6.1-.9.4-.3.3-1.2 1.1-1.2 2.8s1.2 3.2 1.4 3.4c.2.2 2.3 3.5 5.6 4.9.8.3 1.4.5 1.9.6.8.3 1.5.2 2 .1.6-.1 1.9-.8 2.1-1.6.3-.8.3-1.5.2-1.6-.1-.1-.3-.2-.6-.4z",
        tiktok: "M14.5 3c.3 2.6 1.7 4.2 4.3 4.4v2.8c-1.5 0-2.9-.5-4.2-1.4v6.5c0 3.6-2.8 6.2-6.4 6.2S1.8 18.9 1.8 15.3 4.6 9.1 8.2 9.1c.4 0 .8 0 1.2.1v3c-.4-.1-.8-.2-1.2-.2-1.9 0-3.3 1.5-3.3 3.3s1.4 3.3 3.3 3.3 3.2-1.5 3.2-3.3V3h3.1z"
    };
    var BRAND_VIEWBOXES = {
        instagram: "0 0 24 24",
        telegram: "0 0 24 24",
        whatsapp: "0 0 24 24",
        tiktok: "0 0 24 24"
    };
    var BRAND_FILLS = {
        instagram: "#E4405F",
        telegram: "#26A5E4",
        whatsapp: "#25D366",
        tiktok: "currentColor"
    };
    var NETWORKS = ["instagram", "telegram", "whatsapp", "tiktok"];

    function brandSvg(network, size, fill) {
        var path = BRAND_PATHS[network];
        if (!path) return "";
        var color = fill || "currentColor";
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="' + (BRAND_VIEWBOXES[network] || "0 0 24 24") + '" fill="none" role="presentation" aria-hidden="true"><path fill="' + color + '" d="' + path + '"/></svg>';
    }

    function detectNetworkFrom(el) {
        if (!el) return null;
        var href = ((el.getAttribute && el.getAttribute("href")) || "").toLowerCase();
        if (href.indexOf("instagram.com") !== -1) return "instagram";
        if (href.indexOf("t.me") !== -1 || href.indexOf("telegram.") !== -1) return "telegram";
        if (href.indexOf("wa.me") !== -1 || href.indexOf("whatsapp") !== -1) return "whatsapp";
        if (href.indexOf("tiktok.com") !== -1) return "tiktok";
        var hay = (
            (el.getAttribute && (el.getAttribute("aria-label") || el.getAttribute("title") || "")) +
            " " +
            (el.className || "")
        ).toLowerCase();
        for (var i = 0; i < NETWORKS.length; i++) {
            if (hay.indexOf(NETWORKS[i]) !== -1) return NETWORKS[i];
        }
        return null;
    }

    function swapSvg(container, network, size, fill) {
        if (!container || !network || !BRAND_PATHS[network]) return;
        if (container.getAttribute("data-tlms-ipe-icon") === network) return;
        var existing = container.querySelector("svg");
        var svgSize = size;
        if (existing) {
            svgSize = parseInt(existing.getAttribute("width") || existing.getAttribute("height") || String(size), 10) || size;
        }
        var html = brandSvg(network, svgSize, fill);
        if (existing) {
            existing.outerHTML = html;
        } else {
            container.insertAdjacentHTML("afterbegin", html);
        }
        container.setAttribute("data-tlms-ipe-icon", network);
    }

    function replaceSettingIcons() {
        NETWORKS.forEach(function (network) {
            var input = document.getElementById("_tutor_profile_" + network);
            if (!input) return;
            var field = input.closest(".tutor-social-field");
            if (!field) return;
            var icon = field.querySelector(".tutor-social-icon");
            if (!icon) return;
            swapSvg(icon, network, 20, BRAND_FILLS[network]);
        });
    }

    function replaceDashboardHeaderSocialIcons() {
        // Tutor LMS also renders the saved social links in the private
        // dashboard profile header, directly below the instructor name/
        // occupation. Those links are separate from .tutor-social-field,
        // so normalize them by their destination URL as well.
        var root = document.querySelector('[data-tlms-ipe-tutor-only]') || document.body;
        if (!root) return;
        root.querySelectorAll('a[href]').forEach(function (link) {
            var network = detectNetworkFrom(link);
            if (!network) return;
            // Do not alter the actual social-setting input rows here;
            // replaceSettingIcons() handles those.
            if (link.closest('.tutor-social-field')) return;
            var icon = link.querySelector('svg, i, span[class*="icon"], span[class*="social"]');
            var target = icon || link;
            if (target === link && link.children.length > 0) {
                target = link.children[0];
            }
            if (!target) target = link;
            target.classList.add('tlms-ipe-social-icon');
            target.setAttribute('data-tlms-ipe-network', network);
            // Header links should contain only their icon. Replacing the
            // existing Tutor/theme glyph prevents the wrong globe/phone/etc.
            // from being painted over the correct brand mark.
            var svg = link.querySelector('svg');
            if (svg) {
                svg.outerHTML = brandSvg(network, 18, 'currentColor');
            } else {
                var oldIcon = target !== link ? target : null;
                if (oldIcon && oldIcon !== link) {
                    oldIcon.outerHTML = brandSvg(network, 18, 'currentColor');
                } else {
                    link.innerHTML = brandSvg(network, 18, 'currentColor');
                }
            }
            link.classList.add('tlms-ipe-header-social-' + network);
        });
    }

    function refreshSocialIcons() {
        // Tutor LMS can redraw these areas after AJAX saves/navigation, so
        // normalize both the profile settings rows and the saved-link icons
        // shown under the tutor name/occupation in the private dashboard.
        replaceSettingIcons();
        replaceDashboardHeaderSocialIcons();
    }

    function watchDashboardSocialFields() {
        if (!window.MutationObserver || !document.body || document.body.dataset.tlmsIpeSocialObserver === "1") return;
        document.body.dataset.tlmsIpeSocialObserver = "1";
        var queued = false;
        var run = function () {
            if (queued) return;
            queued = true;
            window.requestAnimationFrame(function () { queued = false; refreshSocialIcons(); });
        };
        new MutationObserver(run).observe(document.body, { childList: true, subtree: true });
    }

    function safeJson(value, fallback) {
        try { return JSON.parse(value); } catch (e) { return fallback; }
    }
    function emitValue(hidden, value) {
        if (!hidden) return;
        hidden.value = JSON.stringify(value);
        hidden.dispatchEvent(new Event("input", { bubbles: true }));
        hidden.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function setupLocationMore() {
        // Use delegated binding because Tutor LMS may replace the profile markup
        // after the initial page load.
        if (document.body && document.body.dataset.tlmsIpeMoreBound !== "1") {
            document.body.dataset.tlmsIpeMoreBound = "1";
            document.body.addEventListener("click", function (event) {
                var button = event.target.closest ? event.target.closest(".tlms-ipe-location-more") : null;
                if (!button) return;
                var wrap = button.closest(".tlms-ipe-location-display");
                var lines = wrap ? wrap.querySelector(".tlms-ipe-location-lines") : null;
                if (!lines) return;
                var collapsed = lines.classList.toggle("tlms-ipe-location-collapsed");
                button.setAttribute("aria-expanded", collapsed ? "false" : "true");
                button.textContent = collapsed ? "Show more" : "Show less";
            });
        }
    }

    function setupPublicDrawer() {
        document.querySelectorAll(".tlms-ipe-public-extra").forEach(function (drawer) {
            var summary = drawer.querySelector("summary");
            if (!summary) return;
            var sync = function () {
                summary.setAttribute("aria-expanded", drawer.open ? "true" : "false");
            };
            sync();
            drawer.addEventListener("toggle", sync);
        });
    }

    function bindLocationDropdowns() {
        var root = document.querySelector("[data-tlms-ipe-tutor-only]");
        if (!root) return;
        var wilayaDropdown = root.querySelector('.tlms-ipe-check-dropdown[data-dropdown="tlms_ipe_wilaya"]');
        var communeDropdown = root.querySelector('.tlms-ipe-check-dropdown[data-dropdown="tlms_ipe_commune"]');
        if (!wilayaDropdown || !communeDropdown) return;
        var wilayaHidden = document.getElementById("tlms_ipe_wilaya_value");
        var communeHidden = document.getElementById("tlms_ipe_commune_value");
        if (!wilayaHidden || !communeHidden) return;

        var wilayaPlaceholder = (wilayaDropdown.querySelector(".tlms-ipe-dropdown-text") || {}).textContent || "";
        var communePlaceholder = (communeDropdown.querySelector(".tlms-ipe-dropdown-text") || {}).textContent || "";

        var wilayaSelected = safeJson(wilayaHidden.value || "[]", []);
        if (!Array.isArray(wilayaSelected)) wilayaSelected = [];
        wilayaSelected = wilayaSelected.map(String);

        var communeSelected = safeJson(communeHidden.value || "[]", []);
        if (!Array.isArray(communeSelected)) communeSelected = [];
        communeSelected = communeSelected.map(String);

        function toggleDropdown(dropdown) {
            var toggle = dropdown.querySelector(".tlms-ipe-dropdown-toggle");
            if (!toggle) return;
            toggle.addEventListener("click", function () {
                var open = dropdown.classList.toggle("is-open");
                toggle.setAttribute("aria-expanded", open ? "true" : "false");
            });
        }
        toggleDropdown(wilayaDropdown);
        toggleDropdown(communeDropdown);
        document.addEventListener("click", function (event) {
            [wilayaDropdown, communeDropdown].forEach(function (dropdown) {
                if (!dropdown.contains(event.target)) {
                    dropdown.classList.remove("is-open");
                    var toggle = dropdown.querySelector(".tlms-ipe-dropdown-toggle");
                    if (toggle) toggle.setAttribute("aria-expanded", "false");
                }
            });
        });

        function refreshText(dropdown, key, values, placeholder) {
            var text = dropdown.querySelector(".tlms-ipe-dropdown-text");
            var count = document.querySelector('[data-count-for="' + key + '"]');
            if (count) count.textContent = values.length ? values.length + " selected" : "";
            if (!text) return;
            var labels = [];
            dropdown.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
                if (values.indexOf(String(box.value)) !== -1) {
                    var span = box.parentElement.querySelector("span");
                    if (span) labels.push(span.textContent.trim());
                }
            });
            text.textContent = labels.length ? labels.slice(0, 3).join(", ") + (labels.length > 3 ? " +" + (labels.length - 3) : "") : placeholder;
        }

        function applyCommuneFilter() {
            var searchInput = communeDropdown.querySelector('input[type="search"]');
            var query = searchInput ? searchInput.value.trim().toLowerCase() : "";
            var changed = false;
            communeDropdown.querySelectorAll(".tlms-ipe-option").forEach(function (option) {
                var box = option.querySelector('input[type="checkbox"]');
                var belongs = wilayaSelected.length === 0 || wilayaSelected.indexOf(option.getAttribute("data-wilaya")) !== -1;
                var matches = query === "" || option.textContent.trim().toLowerCase().indexOf(query) !== -1;
                option.hidden = !(belongs && matches);
                if (!belongs && box && box.checked) {
                    box.checked = false;
                    var idx = communeSelected.indexOf(String(box.value));
                    if (idx !== -1) { communeSelected.splice(idx, 1); changed = true; }
                }
            });
            if (changed) emitValue(communeHidden, communeSelected);
            refreshText(communeDropdown, "tlms_ipe_commune", communeSelected, communePlaceholder);
        }

        wilayaDropdown.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
            box.checked = wilayaSelected.indexOf(String(box.value)) !== -1;
            box.addEventListener("change", function () {
                wilayaSelected = Array.prototype.map.call(wilayaDropdown.querySelectorAll('input[type="checkbox"]:checked'), function (el) { return String(el.value); });
                emitValue(wilayaHidden, wilayaSelected);
                refreshText(wilayaDropdown, "tlms_ipe_wilaya", wilayaSelected, wilayaPlaceholder);
                applyCommuneFilter();
            });
        });

        communeDropdown.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
            box.checked = communeSelected.indexOf(String(box.value)) !== -1;
            box.addEventListener("change", function () {
                communeSelected = Array.prototype.map.call(communeDropdown.querySelectorAll('input[type="checkbox"]:checked'), function (el) { return String(el.value); });
                emitValue(communeHidden, communeSelected);
                refreshText(communeDropdown, "tlms_ipe_commune", communeSelected, communePlaceholder);
            });
        });

        var wilayaSearch = wilayaDropdown.querySelector('input[type="search"]');
        if (wilayaSearch) {
            wilayaSearch.addEventListener("input", function () {
                var query = wilayaSearch.value.trim().toLowerCase();
                wilayaDropdown.querySelectorAll(".tlms-ipe-option").forEach(function (option) {
                    option.hidden = query !== "" && option.textContent.trim().toLowerCase().indexOf(query) === -1;
                });
            });
        }
        var communeSearch = communeDropdown.querySelector('input[type="search"]');
        if (communeSearch) communeSearch.addEventListener("input", applyCommuneFilter);

        refreshText(wilayaDropdown, "tlms_ipe_wilaya", wilayaSelected, wilayaPlaceholder);
        applyCommuneFilter();
    }

    function bindPrivateSessionControl() {
        var root = document.querySelector("[data-tlms-ipe-tutor-only]");
        if (!root) return;
        var checkbox = root.querySelector("#tlms_ipe_accept_private_sessions");
        var state = root.querySelector("#tlms_ipe_accept_private_sessions_value");
        var section = root.querySelector("[data-private-session-options]");
        var typesHidden = root.querySelector("#tlms_ipe_tutoring_types_value");
        if (!checkbox || !state || !section || !typesHidden) return;
        function update(notify) {
            var accepted = checkbox.checked;
            state.value = accepted ? "1" : "0";
            section.hidden = !accepted;
            if (!accepted) {
                root.querySelectorAll('[data-tlms-ipe-type]').forEach(function (box) { box.checked = false; });
                if (notify) emitValue(typesHidden, []);
            } else if (notify) {
                var selectedTypes = Array.prototype.map.call(root.querySelectorAll('[data-tlms-ipe-type]:checked'), function (box) { return box.value; });
                emitValue(typesHidden, selectedTypes);
            }
            if (notify) {
                state.dispatchEvent(new Event("input", { bubbles: true }));
                state.dispatchEvent(new Event("change", { bubbles: true }));
            }
        }
        checkbox.addEventListener("change", function () { update(true); });
        root.querySelectorAll('[data-tlms-ipe-type]').forEach(function (box) {
            box.addEventListener("change", function () {
                if (!checkbox.checked) { box.checked = false; return; }
                emitValue(typesHidden, Array.prototype.map.call(root.querySelectorAll('[data-tlms-ipe-type]:checked'), function (el) { return el.value; }));
            });
        });
        update(false);
    }

    function syncRepeater(list, hidden, fields) {
        var rows = [];
        list.querySelectorAll(".tlms-ipe-repeat-row").forEach(function (row) {
            var item = {}, hasValue = false;
            fields.forEach(function (field) {
                var input = row.querySelector('[data-field="' + field + '"]');
                var value = input ? input.value.trim() : "";
                item[field] = value;
                if (value !== "") hasValue = true;
            });
            if (hasValue) rows.push(item);
        });
        hidden.value = JSON.stringify(rows);
        hidden.dispatchEvent(new Event("input", { bubbles: true }));
        hidden.dispatchEvent(new Event("change", { bubbles: true }));
    }
    function createField(label, type, field, placeholder, value) {
        var wrap = document.createElement("div"), labelNode = document.createElement("label"), input = document.createElement("input");
        wrap.className = "tlms-ipe-repeat-field";
        labelNode.className = "tlms-ipe-mini-label"; labelNode.textContent = label;
        input.className = "tutor-form-control tlms-ipe-mini-input"; input.type = type; input.setAttribute("data-field", field);
        if (placeholder) input.placeholder = placeholder; if (value) input.value = value;
        wrap.appendChild(labelNode); wrap.appendChild(input); return wrap;
    }
    function initRepeater(type) {
        var list = document.getElementById("tlms-ipe-" + type + "-list"), hidden = document.getElementById("tlms_ipe_" + type);
        if (!list || !hidden) return;
        var fields = type === "education" ? ["name", "place", "date"] : ["job", "place", "years"];
        var labels = type === "education" ? { name: "Qualification", place: "Institution / Place", date: "Date" } : { job: "Job / Role", place: "Place", years: "Years" };
        var types = type === "education" ? { name: "text", place: "text", date: "date" } : { job: "text", place: "text", years: "number" };
        var placeholders = type === "education" ? { name: "Degree / qualification", place: "Institution", date: "" } : { job: "Job / role", place: "Company / school", years: "Years" };
        var items = safeJson(list.getAttribute("data-items") || hidden.value || "[]", []);
        if (!Array.isArray(items)) items = [];
        function markDirty() {
            var dirty = document.getElementById("tlms_ipe_" + type + "_dirty");
            if (dirty) dirty.value = "1";
        }
        function addRow(item) {
            item = item || {}; var row = document.createElement("div"); row.className = "tlms-ipe-repeat-row";
            fields.forEach(function (field) { var n = createField(labels[field], types[field], field, placeholders[field], item[field] || ""); if (field === "years") n.querySelector("input").min = "0"; row.appendChild(n); });
            var remove = document.createElement("button"); remove.type = "button"; remove.className = "tutor-btn tutor-btn-sm tutor-btn-outline tlms-ipe-remove-row"; remove.textContent = "Remove";
            remove.addEventListener("click", function () { row.remove(); markDirty(); syncRepeater(list, hidden, fields); }); row.appendChild(remove); list.appendChild(row);
            row.querySelectorAll("input").forEach(function (input) { input.addEventListener("input", function () { markDirty(); syncRepeater(list, hidden, fields); }); input.addEventListener("change", function () { markDirty(); syncRepeater(list, hidden, fields); }); });
        }
        items.forEach(addRow);
        document.querySelectorAll('.tlms-ipe-add-row[data-target="' + type + '"]').forEach(function (button) { button.addEventListener("click", function () { addRow({}); markDirty(); syncRepeater(list, hidden, fields); }); });
        syncRepeater(list, hidden, fields);
    }
    function bindChoicePills() {
        document.querySelectorAll(".tlms-ipe-choice-grid").forEach(function (wrapper) {
            var hidden = wrapper.closest(".tlms-ipe-dashboard-fields").querySelector('input[type="hidden"][name="tlms_ipe_tutoring_types"]');
            if (!hidden) return;
            var current = safeJson(hidden.value || "[]", []); if (!Array.isArray(current)) current = [];
            wrapper.querySelectorAll('input[type="checkbox"]').forEach(function (box) { box.checked = current.map(String).indexOf(String(box.value)) !== -1; box.addEventListener("change", function () { emitValue(hidden, Array.prototype.map.call(wrapper.querySelectorAll('input[type="checkbox"]:checked'), function (el) { return el.value; })); }); });
        });
    }
    function bindCheckDropdown(dropdown) {
        var key = dropdown.getAttribute("data-dropdown"), hidden = document.getElementById(key + "_value"), toggle = dropdown.querySelector(".tlms-ipe-dropdown-toggle"), panel = dropdown.querySelector(".tlms-ipe-dropdown-panel"), text = dropdown.querySelector(".tlms-ipe-dropdown-text"), count = document.querySelector('[data-count-for="' + key + '"]');
        if (!hidden || !toggle || !panel) return;
        var placeholder = text ? text.textContent : "";
        var selected = safeJson(hidden.value || "[]", []); if (!Array.isArray(selected)) selected = []; selected = selected.map(String);
        function refresh() {
            var boxes = dropdown.querySelectorAll('input[type="checkbox"]'); boxes.forEach(function (box) { box.checked = selected.indexOf(String(box.value)) !== -1; });
            if (count) count.textContent = selected.length ? selected.length + " selected" : "";
            if (text) { var labels = []; boxes.forEach(function (box) { if (selected.indexOf(String(box.value)) !== -1) { var span = box.parentElement.querySelector("span"); if (span) labels.push(span.textContent.trim()); } }); text.textContent = labels.length ? labels.slice(0, 3).join(", ") + (labels.length > 3 ? " +" + (labels.length - 3) : "") : placeholder; }
        }
        toggle.addEventListener("click", function () { var open = dropdown.classList.toggle("is-open"); toggle.setAttribute("aria-expanded", open ? "true" : "false"); });
        dropdown.querySelectorAll('input[type="checkbox"]').forEach(function (box) { box.addEventListener("change", function () { selected = Array.prototype.map.call(dropdown.querySelectorAll('input[type="checkbox"]:checked'), function (el) { return String(el.value); }); emitValue(hidden, selected); refresh(); }); });
        var search = dropdown.querySelector('input[type="search"]'); if (search) search.addEventListener("input", function () { var query = search.value.trim().toLowerCase(); dropdown.querySelectorAll(".tlms-ipe-option").forEach(function (option) { option.hidden = query !== "" && option.textContent.trim().toLowerCase().indexOf(query) === -1; }); });
        document.addEventListener("click", function (event) { if (!dropdown.contains(event.target)) { dropdown.classList.remove("is-open"); toggle.setAttribute("aria-expanded", "false"); } });
        refresh();
    }
    function initTutorFields() {
        if (!document.querySelector("[data-tlms-ipe-tutor-only]")) return;
        bindPrivateSessionControl(); bindLocationDropdowns(); bindChoicePills(); initRepeater("education"); initRepeater("experience");
        document.querySelectorAll(".tlms-ipe-check-dropdown:not(.tlms-ipe-location-dropdown)").forEach(bindCheckDropdown);
    }

    function init() {
        refreshSocialIcons();
        initTutorFields();
        setupLocationMore();
        window.setTimeout(function () {
            refreshSocialIcons();
            setupLocationMore();
        }, 50);
    }
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init); else init();
})();
