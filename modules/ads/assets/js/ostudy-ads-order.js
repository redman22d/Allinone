(function(){
  'use strict';
  var C = window.OStudyAdOrder || {};
  var T = C.i18n || {};

  function $(sel, ctx){ return (ctx || document).querySelector(sel); }
  function $$(sel, ctx){ return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function init(root){
    var tabs = $$('.osao__tab', root), panels = $$('.osao__panel', root);
    function showTab(name){
      tabs.forEach(function(t){ var on = t.getAttribute('data-tab') === name; t.classList.toggle('is-active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
      panels.forEach(function(p){ p.hidden = p.getAttribute('data-panel') !== name; });
    }
    tabs.forEach(function(t){ t.addEventListener('click', function(){ showTab(t.getAttribute('data-tab')); }); });

    initOrder(root, showTab);
    initTrack(root);
  }

  /* ---------------- Order form ---------------- */
  function initOrder(root, showTab){
    var form = $('.osao__form', root);
    if(!form) return;
    var started = Date.now(), step = 1, total = 3;
    var err = $('.osao__error', form);
    var btnPrev = $('[data-prev]', form), btnNext = $('[data-next]', form), btnSubmit = $('[data-submit]', form);
    var fileInput = form.elements.ad_image;
    var preview = $('.osao__preview', form), previewImg = $('.osao__preview img', form), drop = $('.osao__drop', form);
    var sizeHint = $('.osao__size-hint', form);

    if(form.elements.nonce) form.elements.nonce.value = C.nonce || '';

    var imgDims = null;           // natural size of the chosen image
    var availTimer = null, availOk = true;
    var totalBox = $('.osao__total', form), availBox = $('.osao__avail', form);

    function money(v){
      var n = Number(v || 0);
      var txt = n.toLocaleString(undefined, {minimumFractionDigits: Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2, maximumFractionDigits: 2});
      return C.pricing && C.pricing.currency ? txt + ' ' + C.pricing.currency : txt;
    }
    function selectedZones(){
      return $$('input[name="placements[]"]:checked', form).map(function(i){ return i.value; });
    }
    function currentDays(){ return parseInt(form.elements.duration_days.value, 10) || 7; }
    function quote(zones, days){
      var p = (C.pricing && C.pricing.perWeek) || {}, perWeek = 0;
      zones.forEach(function(z){ if(p[z]) perWeek += Number(p[z]); });
      var gross = perWeek * (days / 7), pct = 0;
      ((C.pricing && C.pricing.discounts) || []).forEach(function(d){ if(days >= d.days) pct = d.pct; });
      return { total: Math.round(gross * (1 - pct / 100) * 100) / 100, pct: pct, gross: gross };
    }
    function updateTotal(){
      if(!totalBox) return;
      var zones = selectedZones();
      if(!(C.pricing && C.pricing.enabled) || !zones.length){ totalBox.hidden = true; return; }
      var q = quote(zones, currentDays());
      if(q.total <= 0){ totalBox.hidden = true; return; }
      $('[data-total]', totalBox).textContent = money(q.total);
      $('[data-total-note]', totalBox).textContent = (q.pct ? T.discountNote.replace('%s', q.pct).replace('%%', '%') + '. ' : '') + T.perWeekNote;
      totalBox.hidden = false;
    }
    // Mirrors OStudy_Ads_Orders::size_problem() on the server.
    function sizeProblem(){
      if(!C.enforceSizes || !imgDims) return '';
      var zones = selectedZones(), recs = [], maxW = 0, ratios = [];
      zones.forEach(function(z){ if(C.sizes && C.sizes[z]) recs.push(C.sizes[z]); });
      if(!recs.length) return '';
      recs.forEach(function(r){ maxW = Math.max(maxW, r[0]); ratios.push(r[0] / r[1]); });
      var w = imgDims.w, h = imgDims.h, minW = Math.round(maxW * 0.6);
      if(w < minW) return T.sizeWarnPrefix + ' ' + w + ' × ' + h + ' px — min ' + minW + ' px wide (recommended ' + maxW + ' px).';
      if(Math.max.apply(null, ratios) / Math.min.apply(null, ratios) <= 1.1){
        var target = ratios.reduce(function(a, b){ return a + b; }, 0) / ratios.length, actual = w / h;
        if(actual < target * 0.7 || actual > target * 1.3) return T.sizeWarnPrefix + ' ' + w + ' × ' + h + ' px — use about ' + recs[0][0] + ' × ' + recs[0][1] + ' px.';
      }
      return '';
    }
    function showSizeHint(){
      if(!imgDims){ sizeHint.hidden = true; return; }
      var p = sizeProblem();
      sizeHint.textContent = p || (imgDims.w + ' × ' + imgDims.h + ' px · ' + imgDims.kb + ' KB');
      sizeHint.classList.toggle('is-warn', !!p);
      sizeHint.hidden = false;
    }
    function checkAvail(done){
      var zones = selectedZones(), start = form.elements.start_date.value;
      if(!C.maxSlots || !zones.length || !start){ availBox.hidden = true; availOk = true; if(done) done(true); return; }
      availBox.hidden = false; availBox.className = 'osao__avail'; availBox.textContent = T.checking;
      var fd = new FormData();
      fd.append('action', 'ostudy_ads_order_availability');
      zones.forEach(function(z){ fd.append('zones[]', z); });
      fd.append('start_date', start); fd.append('duration_days', currentDays());
      fetch(C.ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json().catch(function(){ return null; }); })
        .then(function(res){
          if(!res || !res.success){ availBox.hidden = true; availOk = true; if(done) done(true); return; }
          var full = res.data.zones.filter(function(z){ return z.full; }).map(function(z){ return z.label; });
          availOk = full.length === 0;
          availBox.className = 'osao__avail ' + (availOk ? 'is-ok' : 'is-bad');
          availBox.textContent = availOk ? T.availOk : T.fullFor.replace('%s', full.join(', '));
          if(done) done(availOk);
        })
        .catch(function(){ availBox.hidden = true; availOk = true; if(done) done(true); });
    }
    function scheduleAvail(){ clearTimeout(availTimer); availTimer = setTimeout(function(){ if(step === 2) checkAvail(); }, 350); }


    function setError(msg, el){
      err.textContent = msg || '';
      err.hidden = !msg;
      $$('.is-invalid', form).forEach(function(n){ n.classList.remove('is-invalid'); });
      if(el){ el.classList.add('is-invalid'); try{ el.focus({preventScroll:false}); }catch(e){} }
    }
    function goto(n){
      step = n;
      $$('.osao__step', form).forEach(function(s){ var on = parseInt(s.getAttribute('data-step'),10) === n; s.hidden = !on; s.classList.toggle('is-active', on); });
      $$('.osao__steps li', root).forEach(function(li){
        var d = parseInt(li.getAttribute('data-dot'),10);
        li.classList.toggle('is-active', d === n);
        li.classList.toggle('is-done', d < n);
      });
      btnPrev.hidden = n === 1;
      btnNext.hidden = n === total;
      btnSubmit.hidden = n !== total;
      setError('');
      updateTotal();
      if(n !== 2) availBox.hidden = true; else if(form.elements.start_date.value) checkAvail();
      var top = root.getBoundingClientRect().top;
      if(top < 0) root.scrollIntoView({behavior:'smooth', block:'start'});
    }

    /* format -> placements */
    function syncPlacements(){
      var type = (form.querySelector('input[name="ad_type"]:checked') || {}).value;
      var wrap = $('.osao__placements', form), empty = $('.osao__placements-empty', form);
      if(!type){ wrap.hidden = true; empty.hidden = false; return; }
      empty.hidden = true; wrap.hidden = false;
      $$('.osao__place', form).forEach(function(l){
        var match = l.getAttribute('data-type') === type;
        l.hidden = !match;
        if(!match){ var cb = l.querySelector('input'); if(cb) cb.checked = false; }
      });
    }
    $$('input[name="ad_type"]', form).forEach(function(r){ r.addEventListener('change', function(){ syncPlacements(); updateTotal(); showSizeHint(); }); });
    $$('input[name="placements[]"]', form).forEach(function(c){ c.addEventListener('change', function(){ updateTotal(); showSizeHint(); }); });
    form.elements.duration_days.addEventListener('change', function(){ updateTotal(); scheduleAvail(); });
    form.elements.start_date.addEventListener('change', scheduleAvail);

    /* image preview + checks */
    function clearImage(){
      fileInput.value = ''; preview.hidden = true; previewImg.removeAttribute('src'); drop.hidden = false; imgDims = null; showSizeHint();
    }
    fileInput.addEventListener('change', function(){
      var f = fileInput.files && fileInput.files[0];
      if(!f){ clearImage(); return; }
      if(!/^image\/(jpeg|png|webp|gif)$/.test(f.type)){ clearImage(); setError(T.badType, drop); return; }
      if(C.maxBytes && f.size > C.maxBytes){ clearImage(); setError(T.tooBig + ' (' + Math.round(C.maxBytes/1048576) + ' MB max)', drop); return; }
      setError('');
      var url = URL.createObjectURL(f);
      previewImg.onload = function(){
        imgDims = { w: previewImg.naturalWidth, h: previewImg.naturalHeight, kb: Math.max(1, Math.round(f.size/1024)) };
        showSizeHint();
      };
      previewImg.src = url;
      preview.hidden = false; drop.hidden = true;
    });
    var clearBtn = $('[data-clear-image]', form);
    if(clearBtn) clearBtn.addEventListener('click', function(){ clearImage(); });

    /* validation per step */
    function validate(n){
      if(n === 1){
        if(!form.querySelector('input[name="ad_type"]:checked')){ setError(T.pickFormat, $('.osao__formats', form)); return false; }
        if(!form.querySelector('input[name="placements[]"]:checked')){ setError(T.pickPlace, $('.osao__placements', form)); return false; }
      }
      if(n === 2){
        var title = form.elements.ad_title;
        if(!title.value.trim()){ setError(T.required, title); return false; }
        var url = form.elements.destination_url.value.trim();
        if(url && !/^https?:\/\/[^\s.]+\.[^\s]{2,}/i.test(url)){ setError(T.badUrl, form.elements.destination_url); return false; }
        var hasFile = fileInput.files && fileInput.files.length;
        if(!hasFile && !form.elements.need_design.checked){ setError(T.pickImage, drop.hidden ? preview : drop); return false; }
        if(!form.elements.start_date.value){ setError(T.required, form.elements.start_date); return false; }
        var sp = sizeProblem();
        if(sp){ setError(sp, drop.hidden ? preview : drop); showSizeHint(); return false; }
      }
      if(n === 3){
        if(!form.elements.contact_name.value.trim()){ setError(T.required, form.elements.contact_name); return false; }
        var em = form.elements.email.value.trim();
        if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)){ setError(T.badEmail, form.elements.email); return false; }
        if(!form.elements.terms.checked){ setError(T.terms, form.elements.terms); return false; }
      }
      return true;
    }

    btnNext.addEventListener('click', function(){
      if(!validate(step)) return;
      if(step === 2 && C.maxSlots){
        btnNext.disabled = true;
        checkAvail(function(ok){
          btnNext.disabled = false;
          if(ok) goto(3); else setError(availBox.textContent);
        });
        return;
      }
      goto(step + 1);
    });
    btnPrev.addEventListener('click', function(){ goto(step - 1); });
    form.addEventListener('keydown', function(e){
      if(e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && step < total){ e.preventDefault(); btnNext.click(); }
    });

    form.addEventListener('submit', function(e){
      e.preventDefault();
      if(!validate(1)){ goto(1); validate(1); return; }
      if(!validate(2)){ goto(2); validate(2); return; }
      if(!validate(3)) return;
      form.elements.elapsed.value = String(Date.now() - started);
      btnSubmit.disabled = true; btnSubmit.textContent = T.sending;
      setError('');
      fetch(C.ajaxUrl, { method:'POST', body:new FormData(form), credentials:'same-origin' })
        .then(function(r){ return r.json().catch(function(){ return null; }); })
        .then(function(res){
          if(res && res.success){ showSuccess(res.data); return; }
          var d = (res && res.data) || {};
          setError(d.message || T.network);
          btnSubmit.disabled = false; btnSubmit.textContent = T.send;
        })
        .catch(function(){
          setError(T.network);
          btnSubmit.disabled = false; btnSubmit.textContent = T.send;
        });
    });

    var success = $('.osao__success', root);
    function showSuccess(data){
      form.hidden = true;
      success.hidden = false;
      $('[data-code]', success).textContent = data.code;
      var amt = $('[data-amount]', success);
      if(amt){ amt.hidden = !data.amount; amt.textContent = data.amount ? T.amountLabel + ': ' + data.amount : ''; }
      var note = $('[data-mail-note]', success);
      if(note){
        note.textContent = data.email_sent ? (T.emailSent || '') : (T.emailFailed || '');
        note.classList.toggle('is-error', !data.email_sent);
        note.classList.toggle('is-success', !!data.email_sent);
        note.setAttribute('role', 'status');
      }
      success.setAttribute('data-code-value', data.code);
      var panelTop = root.getBoundingClientRect().top;
      if(panelTop < 0) root.scrollIntoView({behavior:'smooth', block:'start'});
      var cp = $('[data-copy-code]', success);
      cp.onclick = function(){
        var done = function(){ var old = T.copy; cp.textContent = T.copied; setTimeout(function(){ cp.textContent = old; }, 1500); };
        if(navigator.clipboard && window.isSecureContext){ navigator.clipboard.writeText(data.code).then(done); }
        else { var ta = document.createElement('textarea'); ta.value = data.code; document.body.appendChild(ta); ta.select(); try{ document.execCommand('copy'); done(); }catch(x){} document.body.removeChild(ta); }
      };
      var go = $('[data-go-track]', success);
      if(go) go.onclick = function(){
        var tf = $('.osao__trackform', root);
        if(tf){
          tf.elements.code.value = data.code;
          tf.elements.contact.value = form.elements.email.value;
        }
        showTab('track');
      };
    }
    goto(1);
  }

  /* ---------------- Tracker ---------------- */
  function initTrack(root){
    var tf = $('.osao__trackform', root);
    if(!tf) return;
    var out = $('.osao__result', root), err = $('.osao__error', tf), btn = $('button[type="submit"]', tf);
    function lookup(){
      err.hidden = true; out.innerHTML = '';
      if(!tf.elements.code.value.trim()){ err.textContent = T.required; err.hidden = false; tf.elements.code.focus(); return; }
      btn.disabled = true; btn.textContent = T.looking;
      var body = new URLSearchParams(new FormData(tf)).toString();
      fetch(C.ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:body, credentials:'same-origin' })
        .then(function(r){ return r.json().catch(function(){ return null; }); })
        .then(function(res){
          if(res && res.success){ out.innerHTML = res.data.html; out.scrollIntoView({behavior:'smooth', block:'nearest'}); }
          else { err.textContent = (res && res.data && res.data.message) || T.network; err.hidden = false; }
        })
        .catch(function(){ err.textContent = T.network; err.hidden = false; })
        .then(function(){ btn.disabled = false; btn.textContent = T.track; });
    }
    tf.addEventListener('submit', function(e){ e.preventDefault(); lookup(); });

    // Renew button inside tracker cards.
    root.addEventListener('click', function(e){
      var b = e.target.closest ? e.target.closest('.osao-renew') : null;
      if(!b) return;
      var wrap = b.closest('.osao-card__actions'), rerr = wrap.querySelector('.osao__error');
      rerr.hidden = true;
      b.disabled = true; b.textContent = T.renewing;
      var fd = new FormData();
      fd.append('action', 'ostudy_ads_order_renew'); fd.append('code', b.getAttribute('data-code'));
      fd.append('contact', tf.elements.contact.value || ''); fd.append('nonce', C.nonce || '');
      fetch(C.ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json().catch(function(){ return null; }); })
        .then(function(res){
          if(res && res.success){
            var card = b.closest('.osao-card');
            var tmp = document.createElement('div');
            tmp.innerHTML = '<div class="osao-flash">' + '</div>' + res.data.html;
            tmp.firstChild.textContent = res.data.message;
            card.parentNode.replaceChild(tmp.lastElementChild, card);
            var host = tf.nextElementSibling; // result container
            if(host && host.classList.contains('osao__result')) host.insertBefore(tmp.firstChild, host.firstChild);
            tf.elements.code.value = res.data.code;
            return;
          }
          rerr.textContent = (res && res.data && res.data.message) || T.network; rerr.hidden = false;
          b.disabled = false; b.textContent = T.renew;
        })
        .catch(function(){ rerr.textContent = T.network; rerr.hidden = false; b.disabled = false; b.textContent = T.renew; });
    });

    // Payment proof forms live inside server-rendered order cards, so use delegation.
    root.addEventListener('change', function(e){
      var inp = e.target;
      if(!inp.matches || !inp.matches('.osao-proof input[type="file"]')) return;
      var name = inp.closest('.osao-proof__file').querySelector('.osao-proof__name');
      name.textContent = inp.files && inp.files[0] ? inp.files[0].name : '';
    });
    root.addEventListener('submit', function(e){
      var f = e.target;
      if(!f.classList || !f.classList.contains('osao-proof')) return;
      e.preventDefault();
      var perr = f.querySelector('.osao__error'), pbtn = f.querySelector('button[type="submit"]');
      function fail(m){ perr.textContent = m; perr.hidden = false; }
      perr.hidden = true;
      var file = f.elements.proof.files && f.elements.proof.files[0];
      if(!file){ fail(T.proofNeed); return; }
      if(!/^(image\/(jpeg|png|webp|gif)|application\/pdf)$/.test(file.type)){ fail(T.proofType); return; }
      if(C.maxBytes && file.size > C.maxBytes){ fail(T.tooBig + ' (' + Math.round(C.maxBytes/1048576) + ' MB max)'); return; }
      var fd = new FormData(f);
      fd.append('contact', tf.elements.contact.value || '');
      fd.append('nonce', C.nonce || '');
      pbtn.disabled = true; pbtn.textContent = T.sending;
      fetch(C.ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json().catch(function(){ return null; }); })
        .then(function(res){
          if(res && res.success){
            var card = f.closest('.osao-card');
            var tmp = document.createElement('div'); tmp.innerHTML = res.data.html;
            card.parentNode.replaceChild(tmp.firstElementChild, card);
            return;
          }
          fail((res && res.data && res.data.message) || T.network);
          pbtn.disabled = false; pbtn.textContent = T.sendProof;
        })
        .catch(function(){ fail(T.network); pbtn.disabled = false; pbtn.textContent = T.sendProof; });
    });
    // Arrived from an e-mail link: number is prefilled, wait for the contact detail.
    if(tf.elements.code.value && !tf.elements.contact.value){ try{ tf.elements.contact.focus(); }catch(e){} }
  }

  function boot(){ $$('.osao').forEach(init); }
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
