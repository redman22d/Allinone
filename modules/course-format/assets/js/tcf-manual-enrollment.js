(function () {
  'use strict';
  var root = document.querySelector('.tcf-manual-enrollment');
  if (!root || !window.tcfEnrollment) return;

  var course = root.querySelector('#tcf_enroll_course');
  var search = root.querySelector('#tcf_student_search');
  var results = root.querySelector('.tcf-search-results');
  var tableBody = root.querySelector('.tcf-enrolled-body');
  var count = root.querySelector('.tcf-enrolled-count');
  var notice = root.querySelector('.tcf-live-notice');
  var timer = null;

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>'"]/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
    });
  }

  function selectedCourse() { return course ? course.value : ''; }

  function showNotice(type, message) {
    if (!notice) return;
    notice.className = 'tcf-alert tcf-alert-' + type + ' tcf-live-notice';
    notice.textContent = message;
    notice.style.display = 'block';
    window.setTimeout(function () { notice.style.display = 'none'; }, 4500);
  }

  function refreshPage() {
    var url = new URL(window.location.href);
    url.searchParams.set('tcf_course', selectedCourse());
    window.location.href = url.toString();
  }

  function searchStudents(term) {
    if (!selectedCourse() || term.length < 2) {
      results.innerHTML = '';
      results.classList.remove('is-open');
      return;
    }
    results.innerHTML = '<div class="tcf-search-result"><span class="tcf-result-meta"><span class="tcf-spinner"></span>Searching students…</span></div>';
    results.classList.add('is-open');
    var body = new URLSearchParams();
    body.set('action', 'tcf_search_students');
    body.set('nonce', window.tcfEnrollment.nonce);
    body.set('course_id', selectedCourse());
    body.set('search', term);
    fetch(window.tcfEnrollment.ajaxUrl, { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString(), credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json.success) throw new Error((json.data && json.data.message) || 'Search failed.');
        var rows = json.data.results || [];
        if (!rows.length) {
          results.innerHTML = '<div class="tcf-search-result"><span class="tcf-result-meta">No matching students found.</span></div>';
          return;
        }
        results.innerHTML = rows.map(function (u) {
          return '<div class="tcf-search-result">' +
            '<div class="tcf-result-user"><strong>' + escapeHtml(u.display_name) + '</strong>' +
            '<span class="tcf-result-meta">@' + escapeHtml(u.user_login) + ' · ' + escapeHtml(u.user_email) + '</span></div>' +
            '<button type="button" class="tcf-add" data-user-id="' + escapeHtml(u.id) + '">Enroll</button>' +
            '</div>';
        }).join('');
      })
      .catch(function (err) {
        results.innerHTML = '<div class="tcf-search-result"><span class="tcf-result-meta">' + escapeHtml(err.message) + '</span></div>';
      });
  }

  function postAction(action, userId, enrollmentId, button) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', window.tcfEnrollment.nonce);
    body.set('course_id', selectedCourse());
    if (userId) body.set('student_id', userId);
    if (enrollmentId) body.set('enrollment_id', enrollmentId);
    button.disabled = true;
    button.innerHTML = '<span class="tcf-spinner"></span>' + (action === 'tcf_enroll_student' ? 'Adding…' : 'Removing…');
    fetch(window.tcfEnrollment.ajaxUrl, { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString(), credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json.success) throw new Error((json.data && json.data.message) || 'The request could not be completed.');
        showNotice('success', json.data.message || 'Done.');
        refreshPage();
      })
      .catch(function (err) {
        button.disabled = false;
        button.textContent = action === 'tcf_enroll_student' ? 'Enroll' : 'Remove';
        showNotice('error', err.message);
      });
  }

  if (course) {
    course.addEventListener('change', function () {
      search.value = '';
      results.classList.remove('is-open');
      refreshPage();
    });
  }
  if (search) {
    search.addEventListener('input', function () {
      window.clearTimeout(timer);
      var term = search.value.trim();
      timer = window.setTimeout(function () { searchStudents(term); }, 280);
    });
    search.addEventListener('focus', function () {
      if (search.value.trim().length >= 2) searchStudents(search.value.trim());
    });
  }
  document.addEventListener('click', function (e) {
    var add = e.target.closest('.tcf-add');
    if (add) {
      postAction('tcf_enroll_student', add.getAttribute('data-user-id'), '', add);
      return;
    }
    var remove = e.target.closest('.tcf-remove');
    if (remove) {
      if (window.confirm('Remove this student from the course?')) {
        postAction('tcf_remove_student', '', remove.getAttribute('data-enrollment-id'), remove);
      }
      return;
    }
    if (!e.target.closest('.tcf-search-wrap')) {
      results.classList.remove('is-open');
    }
  });
}());
