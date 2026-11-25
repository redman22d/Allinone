(function(){
  'use strict';

  function cookie(name, value, maxAge){
    var base=name+'='+encodeURIComponent(value)+';path=/;SameSite=Lax';
    document.cookie = (typeof maxAge==='number' ? base+';max-age='+maxAge : base);
  }
  function track(el, type){
    if(!window.OStudyAds) return;
    var data=new URLSearchParams();
    data.append('action','ostudy_ads_track');
    data.append('nonce',OStudyAds.nonce);
    data.append('ad_id',el.getAttribute('data-ad-id'));
    data.append('type',type);
    data.append('placement',el.getAttribute('data-placement')||'');
    data.append('tracking_token',el.getAttribute('data-track-token')||'');
    fetch(OStudyAds.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:data.toString(),credentials:'same-origin',keepalive:true}).catch(function(){});
  }
  function markSeen(el){
    var freq=el.getAttribute('data-frequency'), id=el.getAttribute('data-ad-id'), placement=el.getAttribute('data-placement')||'global';
    // Must exactly match the PHP cookie name in frequency_allows()
    // (ostudy_ads_seen_{id}_{zone}) or the server-side frequency check can never
    // see this cookie and the session/cooldown cap silently never engages.
    var key='ostudy_ads_seen_'+id+'_'+placement;
    if(freq==='session') cookie(key,'1');
    if(freq==='cooldown') cookie(key,String(Date.now()/1000|0),Math.max(1,parseInt(el.getAttribute('data-cooldown')||24,10))*3600);
  }
  function firstMatch(selector){
    if(!selector) return null;
    try { return document.querySelector(selector); } catch(e) { return null; }
  }
  function markMounted(el){
    el.classList.add('is-mounted');
    el.removeAttribute('aria-hidden');
  }
  function moveBefore(el,target){
    if(!target || !target.parentNode) return false;
    target.parentNode.insertBefore(el,target);
    markMounted(el);
    return true;
  }
  function positionProfileSide(el, item){
    var target=firstMatch(item.selector || '.tutor-user-public-profile .tutor-user-profile-sidebar');
    if(!target) return false;
    try {
      el.classList.add('ostudy-ad--profile-sidebar');
      target.appendChild(el);
      markMounted(el);
      return true;
    } catch(e) { return false; }
  }
  function positionProfileTop(el, item){
    var target=firstMatch(item.selector || '.tutor-user-public-profile .photo-area + .tutor-container');
    if(!target) return false;
    try {
      // Keep the profile banner inside Tutor's second profile container, but before
      // the float-based sidebar/content columns. This avoids turning the banner into
      // a sibling of the whole profile and prevents the empty sidebar from shifting.
      target.insertBefore(el,target.firstElementChild || null);
      el.classList.add('ostudy-ad--profile-top');
      markMounted(el);
      return true;
    } catch(e) { return false; }
  }
  function mountElement(el, config){
    var placement=el.getAttribute('data-placement')||'';
    var item=config && config[placement];
    if(!item || !item.selector) return false;
    if(item.method==='profile_side' || placement==='instructor_profile_side') return positionProfileSide(el,item);
    if(item.method==='profile_top' || placement==='instructor_profile_top') return positionProfileTop(el,item);

    var target=firstMatch(item.selector);
    if(!target || target===el || el.contains(target)) return false;
    try {
      if(item.method==='append') {
        target.appendChild(el);
        markMounted(el);
      } else {
        moveBefore(el,target);
      }
      return true;
    } catch(e) { return false; }
  }
  function mountAll(){
    if(!window.OStudyAds || !OStudyAds.placements) return;
    document.querySelectorAll('#ostudy-ads-staging .ostudy-ad').forEach(function(el){
      if(el.classList.contains('ostudy-ad-popup')){
        // The staging container is display:none, which would also hide a fixed-position
        // popup inside it. Move popups to <body> so they can actually open.
        document.body.appendChild(el);
        el.classList.add('is-mounted');
        return;
      }
      mountElement(el,OStudyAds.placements);
    });
  }
  function bindImageFallbacks(){
    document.querySelectorAll('.ostudy-ad img').forEach(function(img){
      var original=img.getAttribute('data-original-src');
      var current=img.getAttribute('src') || '';
      // TranslatePress or another DOM transformer must never be allowed to replace
      // the actual media URL with translated text/attributes.
      if(original && (!current || current.indexOf('http') !== 0 || /translate|translation/i.test(current))){
        img.setAttribute('src', original);
      }
      if(img.dataset.ostudyBound) return;
      img.dataset.ostudyBound='1';
      img.addEventListener('error',function(){
        var fallback=img.getAttribute('data-fallback-src') || img.getAttribute('data-original-src');
        if(fallback && img.getAttribute('src') !== fallback){
          img.setAttribute('src',fallback);
          img.removeAttribute('data-fallback-src');
        }
      });
    });
    document.querySelectorAll('.ostudy-ad source').forEach(function(source){
      var original=source.getAttribute('data-original-src') || source.getAttribute('data-fallback-src');
      if(original && !source.dataset.ostudyBound){
        source.dataset.ostudyBound='1';
        source.addEventListener('error',function(){ source.setAttribute('srcset',original); });
      }
    });
  }

  function trackVisibleAds(){
    document.querySelectorAll('.ostudy-ad.is-mounted').forEach(function(el){
      if(el.dataset.ostudyTracked) return;
      if(!el.classList.contains('ostudy-ad--zone-focus_mode_side') && !el.classList.contains('ostudy-ad--zone-dashboard_sidebar') && !el.classList.contains('ostudy-ad--zone-focus_mode_top') && !el.classList.contains('ostudy-ad--zone-home_top') && !el.classList.contains('ostudy-ad--zone-course_search_top') && !el.classList.contains('ostudy-ad--zone-tutor_search_top') && !el.classList.contains('ostudy-ad--zone-instructor_profile_top') && !el.classList.contains('ostudy-ad--zone-instructor_profile_side') && !el.classList.contains('ostudy-ad--zone-course_page_top')) return;
      el.dataset.ostudyTracked='1';
      track(el,'impression');
      markSeen(el);
      el.querySelectorAll('a:not(.ostudy-ad__advertise)').forEach(function(a){a.addEventListener('click',function(){track(el,'click');});});
    });
  }
  function setupPopups(){
    document.querySelectorAll('.ostudy-ad-popup').forEach(function(popup){
      if(popup.dataset.ostudyPopupBound) return;
      popup.dataset.ostudyPopupBound='1';
      track(popup,'impression');
      markSeen(popup);
      if(window.innerWidth<768 && window.OStudyAds && !OStudyAds.popupMobile) return;
      var delay=parseFloat(popup.getAttribute('data-delay')||'12')*1000;
      var closeDelay=Math.max(0,parseFloat(popup.getAttribute('data-close-delay')||'5'))*1000;
      var scroll=parseInt(popup.getAttribute('data-scroll')||'0',10), opened=false, canClose=(closeDelay===0), lockTimer=null;
      var closeButtons=popup.querySelectorAll('.ostudy-ad-popup__close');
      var closeTargets=popup.querySelectorAll('.ostudy-ad-popup__close,.ostudy-ad-popup__backdrop');
      function updateCloseLabel(){
        closeButtons.forEach(function(btn){
          btn.disabled=!canClose;
          btn.setAttribute('aria-disabled',canClose?'false':'true');
          if(!canClose){
            var seconds=Math.max(1,Math.ceil(closeDelay/1000));
            btn.textContent='× '+seconds;
          } else { btn.textContent='×'; }
        });
      }
      function startCloseLock(){
        canClose=(closeDelay===0);
        updateCloseLabel();
        if(canClose) return;
        var start=Date.now(), remaining=closeDelay;
        lockTimer=window.setInterval(function(){
          remaining=closeDelay-(Date.now()-start);
          if(remaining<=0){ canClose=true; window.clearInterval(lockTimer); lockTimer=null; updateCloseLabel(); }
          else { updateCloseLabel(); }
        },250);
      }
      function open(){if(opened)return; opened=true; popup.setAttribute('aria-hidden','false'); popup.classList.add('is-open'); startCloseLock();}
      function close(){if(!canClose)return; popup.setAttribute('aria-hidden','true'); popup.classList.remove('is-open');}
      closeTargets.forEach(function(b){b.addEventListener('click',close);});
      document.addEventListener('keydown',function(e){if(e.key==='Escape'&&popup.classList.contains('is-open')) close();});
      popup.querySelectorAll('a:not(.ostudy-ad__advertise)').forEach(function(a){a.addEventListener('click',function(){track(popup,'click');});});
      if(scroll>0){
        var f=function(){var denom=document.documentElement.scrollHeight-window.innerHeight;var pct=denom>0?(window.scrollY/denom)*100:0;if(pct>=scroll){open();window.removeEventListener('scroll',f);}};
        window.addEventListener('scroll',f,{passive:true});
      } else window.setTimeout(open,Math.max(0,delay));
    });
  }
  function setup(){
    mountAll();
    bindImageFallbacks();
    trackVisibleAds();
    setupPopups();
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',setup); else setup();
  // A small delayed retry handles themes/plugins that finish constructing their
  // Tutor wrappers after DOMContentLoaded, without falling back to an unsafe body/main insertion.
  window.setTimeout(function(){ mountAll(); bindImageFallbacks(); trackVisibleAds(); },250);
  window.setTimeout(function(){ mountAll(); bindImageFallbacks(); trackVisibleAds(); },1000);
})();
