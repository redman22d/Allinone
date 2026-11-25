jQuery(function($){
  function parseSize(str){
    var m=String(str||'').match(/(\d+)\s*[×x]\s*(\d+)/i);
    return m?{w:parseInt(m[1],10),h:parseInt(m[2],10)}:null;
  }
  function selectedTargets(){
    var a=[]; $('input[name="page_targets[]"]:checked').each(function(){a.push($(this).val());}); return a;
  }
  function recommendations(){
    var map={
      home_top:['1200 × 300 px','640 × 240 px'],course_search_top:['1200 × 250 px','640 × 220 px'],tutor_search_top:['1200 × 250 px','640 × 220 px'],instructor_profile_top:['1200 × 250 px','640 × 220 px'],course_page_top:['1200 × 250 px','640 × 220 px'],
      dashboard_sidebar:['300 × 250 px','336 × 280 px'],focus_mode_top:['970 × 120 px','640 × 160 px'],focus_mode_side:['300 × 600 px','Not recommended'],
      home_popup:['600 × 500 px','360 × 500 px'],course_search_popup:['600 × 500 px','360 × 500 px'],tutor_search_popup:['600 × 500 px','360 × 500 px'],instructor_profile_popup:['600 × 500 px','360 × 500 px'],course_page_popup:['600 × 500 px','360 × 500 px'],dashboard_popup:['600 × 500 px','360 × 500 px'],focus_mode_popup:['600 × 500 px','360 × 500 px']
    };
    return selectedTargets().map(function(k){return map[k]||null;}).filter(Boolean)[0]||['1200 × 300 px','640 × 240 px'];
  }
  function warn($row, a){
    var image=a||{}; var rec=recommendations(); var rd=parseSize(rec[0]);
    if(!image.width||!image.height||!rd) return;
    var actual=image.width/image.height, ideal=rd.w/rd.h, diff=Math.abs(actual-ideal)/ideal;
    var msg='';
    if(diff>0.15) msg='Aspect ratio differs from the recommended size. Cover may crop part of the image.';
    else if(image.width<rd.w || image.height<rd.h) msg='Image is smaller than recommended. It is allowed, but may look soft on large screens.';
    if(image.filesize && image.filesize>512000) msg += (msg?' ':'')+'File is over 500 KB; consider compression.';
    $row.find('.ostudy-media-warning').text(msg);
  }
  $(document).on('click','.ostudy-media-pick',function(e){
    e.preventDefault();
    var btn=$(this), row=btn.closest('td');
    var frame=wp.media({title:'Choose advertisement image',button:{text:'Use image'},multiple:false,library:{type:'image'}});
    frame.on('select',function(){
      var a=frame.state().get('selection').first().toJSON();
      row.find('.ostudy-media-id').val(a.id);
      row.find('.ostudy-media-url').val(a.url);
      var size=(a.width&&a.height)?a.width+' × '+a.height+' px':'';
      var bytes=a.filesizeInBytes||a.filesize||0; if(bytes) size += (size?' • ':'')+Math.round(bytes/1024)+' KB';
      row.find('.ostudy-media-meta').text(size);
      row.find('.ostudy-media-preview').html('<img src="'+a.url.replace(/"/g,'&quot;')+'" alt="">');
      a.filesize=bytes; warn(row,a);
    });
    frame.open();
  });
  $(document).on('change','input[name="page_targets[]"]',function(){ $('.ostudy-media-warning').text(''); });
});

$(document).on('click','.ostudy-copy-shortcode',function(e){
  e.preventDefault();
  var btn=$(this), text=btn.data('copy')||'';
  if(!text) return;
  function done(){ var old=btn.text(); btn.text('Copied!'); setTimeout(function(){btn.text(old);},1400); }
  if(navigator.clipboard && window.isSecureContext){ navigator.clipboard.writeText(text).then(done); }
  else { var ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy'); done();}catch(err){} document.body.removeChild(ta); }
});
