(function () {
  'use strict';

  var page = (window.tcfNavIcon && window.tcfNavIcon.page) || 'tcf-manual-enrollment';
  var ICON_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">' +
    '<path d="M16 11c1.93 0 3.5-1.57 3.5-3.5S17.93 4 16 4s-3.5 1.57-3.5 3.5S14.07 11 16 11Zm-8 0c1.93 0 3.5-1.57 3.5-3.5S9.93 4 8 4 4.5 5.57 4.5 7.5 6.07 11 8 11Zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4Zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45v2h7v-2c0-2.66-5.33-4-8-4Z" fill="currentColor"/>' +
    '</svg>';

  function findLink() {
    // Match by the dashboard page slug in the href, which is stable
    // regardless of how Tutor structures the surrounding menu markup.
    var links = document.querySelectorAll('a[href*="' + page + '"]');
    for (var i = 0; i < links.length; i++) {
      var link = links[i];
      if (link.closest('.tutor-dashboard-menu, .tutor-dashboard-permalinks, [class*="dashboard-menu"], [class*="dashboard-nav"], nav')) {
        return link;
      }
    }
    return links.length ? links[0] : null;
  }

  function addIcon() {
    var link = findLink();
    if (!link) return false;
    if (link.querySelector('.tcf-nav-icon')) return true;

    var icon = document.createElement('span');
    icon.className = 'tcf-nav-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = ICON_SVG;
    icon.style.display = 'inline-flex';
    icon.style.alignItems = 'center';
    icon.style.justifyContent = 'center';
    icon.style.marginRight = '8px';
    icon.style.flex = '0 0 16px';
    icon.style.lineHeight = '0';

    link.insertBefore(icon, link.firstChild);
    return true;
  }

  function boot() {
    if (addIcon()) return;

    // Tutor's dashboard sidebar can render slightly after DOMContentLoaded
    // (e.g. while other menu items are being localized), so keep watching
    // briefly instead of giving up after a single attempt.
    var observer = new MutationObserver(function () {
      if (addIcon()) observer.disconnect();
    });
    observer.observe(document.body, { childList: true, subtree: true });
    window.setTimeout(function () { observer.disconnect(); }, 8000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
