(function(){
  'use strict';
  var root = document.querySelector('.tcsp-sp');
  var form = root && root.querySelector('.tcsp-sp-wizard');
  if(!form) return;

  var T = {};
  try { T = JSON.parse(root.getAttribute('data-i18n') || '{}'); } catch(e){}
  var today = root.getAttribute('data-today');
  var currency = root.getAttribute('data-currency') || '';
  var $ = function(s, c){ return (c || form).querySelector(s); };
  var $$ = function(s, c){ return Array.prototype.slice.call((c || form).querySelectorAll(s)); };

  var segBtns = $$('.tcsp-sp-seg__btn');
  var groups = $$('.tcsp-sp-group');
  var cta = $('.tcsp-sp-cta');
  var type = '';

  function money(v){
    var n = Number(v || 0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    return currency ? n + ' ' + currency : n;
  }
  function parseDate(s){ var p = String(s).split('-'); return new Date(+p[0], +p[1]-1, +p[2]); }
  function addDays(d, n){ var x = new Date(d.getTime()); x.setDate(x.getDate() + n); return x; }
  function fmt(d){ return d.toLocaleDateString(document.documentElement.lang || undefined, {day:'numeric', month:'short', year:'numeric'}); }

  function termText(days, units){
    days = +days; units = +units;
    var total = days * units, w;
    if(days === 30) w = units + ' ' + (units === 1 ? T.month : T.months);
    else if(days === 7) w = units + ' ' + (units === 1 ? T.week : T.weeks);
    else if(days === 365) w = units + ' ' + (units === 1 ? T.year : T.years);
    else if(days === 1) w = units + ' ' + (units === 1 ? T.day : T.days);
    else w = total + ' ' + T.days;
    return {short: w, long: w + ' · ' + total + ' ' + T.daysTotal};
  }
  function discountFor(plan, u){
    return u >= 12 ? +plan.dataset.d12 : (u >= 6 ? +plan.dataset.d6 : (u >= 3 ? +plan.dataset.d3 : 0));
  }

  function checked(name){ return form.querySelector('input[name="' + name + '"]:checked:not(:disabled)'); }

  function setType(t){
    type = t;
    segBtns.forEach(function(b){ var on = b.getAttribute('data-type') === t; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    groups.forEach(function(g){
      var on = g.getAttribute('data-type') === t;
      g.hidden = !on;
      $$('input', g).forEach(function(i){
        if(on){ if(!i.hasAttribute('data-blocked')) i.disabled = i.closest('.is-blocked') ? true : false; }
        else { i.checked = false; i.disabled = true; }
      });
    });
    // Tutor profile has a single target: preselect it.
    if(t === 'tutor'){
      var only = $('.tcsp-sp-group[data-type="tutor"] input[name="target"]:not(:disabled)');
      if(only) only.checked = true;
    }
    update();
  }

  function update(){
    var target = checked('target');
    var plan = checked('tier');
    var planLabel = plan ? plan.closest('.tcsp-sp-plan') : null;
    var unitsInput = checked('units');
    var u = unitsInput ? +unitsInput.value : 1;

    // visual selected states
    $$('.tcsp-sp-target').forEach(function(l){ var i = l.querySelector('input'); l.classList.toggle('is-checked', !!(i && i.checked)); });
    $$('.tcsp-sp-plan').forEach(function(l){ var i = l.querySelector('input'); l.classList.toggle('is-checked', !!(i && i.checked)); });
    $$('.tcsp-sp-unit').forEach(function(l){ var i = l.querySelector('input'); l.classList.toggle('is-checked', !!(i && i.checked)); });

    // "Your current plan" tag on plan cards for the chosen target
    $$('.tcsp-sp-plan').forEach(function(l){
      var tag = l.querySelector('.tcsp-sp-plan__tag');
      var cur = !!(target && target.dataset.tier && l.getAttribute('data-type') === target.dataset.type && l.getAttribute('data-key') === target.dataset.tier);
      if(tag) tag.hidden = !cur;
    });

    // Unit buttons reflect the chosen plan's duration and discounts
    $$('.tcsp-sp-unit').forEach(function(l){
      var uu = +l.querySelector('input').value;
      var main = l.querySelector('[data-unit-main]'), badge = l.querySelector('[data-unit-badge]');
      if(planLabel){
        main.textContent = termText(planLabel.dataset.duration, uu).short;
        var d = discountFor(planLabel, uu);
        badge.hidden = !d; badge.textContent = d ? '-' + (Math.round(d * 10) / 10) + '%' : '';
      } else { main.textContent = uu + ' ×'; badge.hidden = true; }
    });

    $$('.tcsp-sp-step').forEach(function(s){
      var n = s.getAttribute('data-step');
      s.classList.toggle('is-complete', (n === '1' && !!target) || (n === '2' && !!plan) || (n === '3' && !!plan));
    });

    form.elements.target_type.value = target ? target.dataset.type : '';
    form.elements.target_id.value = target ? target.dataset.id : '';

    var empty = $('[data-empty]'), body = $('[data-body]');
    var ready = !!(target && plan);
    empty.hidden = ready; body.hidden = !ready;
    cta.disabled = !ready;
    if(!ready){
      cta.textContent = T.ctaContinue;
      empty.textContent = !target ? T.chooseTarget : T.choosePlan;
      return;
    }

    var price = +planLabel.dataset.price, days = +planLabel.dataset.duration;
    var disc = discountFor(planLabel, u);
    var gross = price * u;
    var total = Math.round(gross * (1 - disc / 100) * 100) / 100;
    var term = termText(days, u);

    $('[data-s-target]').textContent = target.dataset.title;
    $('[data-s-plan]').textContent = planLabel.dataset.label;
    $('[data-s-dot]').style.background = getComputedStyle(planLabel).getPropertyValue('--plan-color') || '#4f46e5';
    $('[data-s-desc]').textContent = planLabel.dataset.desc || '';
    $('[data-s-duration]').textContent = term.long;
    $('[data-s-unit]').textContent = money(price);
    var dr = $('[data-s-disc-row]');
    dr.hidden = !disc;
    if(disc) $('[data-s-disc]').textContent = '-' + (Math.round(disc * 10) / 10) + '% (−' + money(gross - total) + ')';
    $('[data-s-total]').textContent = money(total);

    // When will it run? (estimate — the server applies the final dates after payment)
    var n = days * u, when = '', label = T.ctaContinue;
    var curTier = target.dataset.tier, curEnd = target.dataset.end;
    if(curTier && curEnd && curEnd >= today){
      var endD = parseDate(curEnd);
      if(curTier === planLabel.dataset.key){
        when = T.startExtend.replace('%s', fmt(addDays(endD, n))); label = T.ctaExtend;
      } else {
        when = T.startChange.replace('%1$s', fmt(endD)).replace('%2$s', fmt(addDays(endD, n))); label = T.ctaChange;
      }
    } else {
      when = T.startNew.replace('%s', fmt(addDays(parseDate(today), n)));
    }
    $('[data-s-when]').textContent = when;
    cta.textContent = label;
  }

  segBtns.forEach(function(b){ b.addEventListener('click', function(){ setType(b.getAttribute('data-type')); }); });
  form.addEventListener('change', update);

  // Course search
  var search = $('.tcsp-sp-search');
  if(search){
    var none = $('.tcsp-sp-noresults');
    search.addEventListener('input', function(){
      var q = search.value.trim().toLowerCase(), shown = 0;
      $$('.tcsp-sp-group[data-type="course"] .tcsp-sp-target').forEach(function(l){
        var ok = !q || (l.getAttribute('data-title') || '').indexOf(q) !== -1;
        l.hidden = !ok; if(ok) shown++;
      });
      none.hidden = shown !== 0; none.textContent = shown ? '' : T.noResults;
    });
  }

  form.addEventListener('submit', function(e){
    if(cta.disabled){ e.preventDefault(); return; }
    cta.disabled = true; cta.textContent = T.working;
  });

  // Deep link support: ?tcsp_course=ID preselects a course; otherwise start on courses (or tutor if none).
  var hasCourses = !!$('.tcsp-sp-group[data-type="course"] input[name="target"]');
  setType(hasCourses ? 'course' : 'tutor');
  // Deep links: ?tcsp_target=course:12|tutor:5 [&tcsp_plan=gold&tcsp_units=3] (also legacy ?tcsp_course=12).
  var qs = new URLSearchParams(location.search);
  var want = qs.get('tcsp_target') || (qs.get('tcsp_course') ? 'course:' + qs.get('tcsp_course') : '');
  if(/^(course|tutor):\d+$/.test(want)){
    var pre = $('input[name="target"][value="' + want + '"]:not(:disabled)');
    if(pre){
      setType(pre.getAttribute('data-type'));
      pre.checked = true; update();
      var planKey = qs.get('tcsp_plan');
      if(planKey){
        var pl = $('.tcsp-sp-group[data-type="' + pre.getAttribute('data-type') + '"] input[name="tier"][value="' + planKey.replace(/[^a-z0-9_-]/gi, '') + '"]:not(:disabled)');
        if(pl){ pl.checked = true; }
      }
      var u = parseInt(qs.get('tcsp_units'), 10);
      if(u){ var ui = $('input[name="units"][value="' + u + '"]'); if(ui) ui.checked = true; }
      update();
      pre.closest('.tcsp-sp-target').scrollIntoView({block:'center'});
    }
  }
})();
