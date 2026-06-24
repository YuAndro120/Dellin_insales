<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\ShopRepository;
use ShippingBridge\Http\Response;

final class ModalHandler
{
  public static function handle(Config $config, ShopRepository $shops): void
  {
    $insalesId = trim((string) ($_GET['insales_id'] ?? ''));
    $orderId   = trim((string) ($_GET['order_id'] ?? ''));

    if ($insalesId === '' || $orderId === '') {
      http_response_code(400);
      echo 'Bad request';
      return;
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $bridgeUrl = rtrim($config->publicBridgeUrl, '/');

    echo self::renderHtml($bridgeUrl, $insalesId, $orderId);
  }

  private static function renderHtml(string $bridgeUrl, string $insalesId, string $orderId): string
  {
    $b = htmlspecialchars($bridgeUrl, ENT_QUOTES);
    $i = htmlspecialchars($insalesId, ENT_QUOTES);
    $o = htmlspecialchars($orderId, ENT_QUOTES);

    return <<<HTML
<style>
#dl-overlay{
  position:fixed;inset:0;z-index:99999;
  background:rgba(15,12,10,.55);
  backdrop-filter:blur(2px);
  display:flex;align-items:flex-start;justify-content:center;
  padding:24px 16px;overflow-y:auto;
  animation:dl-fade .2s ease both;
  font-family:-apple-system,'Helvetica Neue',Arial,sans-serif;
}
@keyframes dl-fade{from{opacity:0}to{opacity:1}}
#dl-modal{
  width:100%;max-width:540px;margin:auto;
  background:#fff;border:1px solid #e8e4df;border-radius:16px;overflow:hidden;
  box-shadow:0 4px 6px rgba(0,0,0,.04),0 16px 48px rgba(0,0,0,.12);
  animation:dl-rise .3s cubic-bezier(.22,1,.36,1) both;
}
@keyframes dl-rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
#dl-modal *{box-sizing:border-box;margin:0;padding:0;font-family:inherit}
.dl-hdr{
  padding:15px 20px;background:#1a1714;
  display:flex;align-items:center;justify-content:space-between;
}
.dl-hdr-l{display:flex;align-items:center;gap:10px}
.dl-hdr-ico{
  width:30px;height:30px;border-radius:7px;background:#f5501e;
  display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;
}
.dl-hdr-t{font-size:13px;font-weight:500;color:#fff}
.dl-hdr-s{font-size:11px;color:rgba(255,255,255,.35);margin-top:1px;font-variant-numeric:tabular-nums}
.dl-hdr-x{
  width:26px;height:26px;border-radius:6px;flex-shrink:0;cursor:pointer;
  background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);
  color:rgba(255,255,255,.5);font-size:16px;line-height:1;
  display:flex;align-items:center;justify-content:center;transition:all .15s;
}
.dl-hdr-x:hover{background:rgba(255,255,255,.15);color:#fff}
.dl-scroll{overflow-y:visible;scrollbar-width:thin;scrollbar-color:#d4cfc9 transparent}
.dl-scroll::-webkit-scrollbar{width:4px}
.dl-scroll::-webkit-scrollbar-thumb{background:#d4cfc9;border-radius:4px}
.dl-sec{padding:15px 20px;border-bottom:1px solid #e8e4df}
.dl-sec:last-child{border-bottom:0}
.dl-sec-hdr{display:flex;align-items:center;gap:7px;margin-bottom:11px}
.dl-sec-dot{width:5px;height:5px;border-radius:50%;background:#f5501e;flex-shrink:0}
.dl-sec-dot.m{background:#bfb9b2}
.dl-sec-ttl{font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.1em;color:#9e978f}
.dl-sec-sub{font-size:9px;color:#9e978f;font-weight:400;text-transform:none;letter-spacing:0;margin-left:2px}
.dl-sec-line{flex:1;height:1px;background:#e8e4df}
.dl-g2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.dl-gfull{grid-column:1/-1}
.dl-f label{font-size:10px;color:#9e978f;letter-spacing:.04em;text-transform:uppercase;display:block;margin-bottom:5px}
.dl-fval{font-size:12px;color:#1a1714;padding:8px 11px;background:#f9f8f7;border:1px solid #e8e4df;border-radius:8px;line-height:1.4}
.dl-fval.mono{font-variant-numeric:tabular-nums;color:#b45309;font-size:12px}
.dl-fe{padding:10px 12px;background:#fff;border:1px solid #e8e4df;border-radius:8px;transition:border .15s,box-shadow .15s;cursor:text}
.dl-fe:focus-within{border-color:#f5501e;box-shadow:0 0 0 3px #fff0eb}
.dl-fe label{font-size:10px;color:#9e978f;letter-spacing:.04em;text-transform:uppercase;display:block;margin-bottom:4px}
.dl-fe input,.dl-fe select{width:100%;border:0;outline:0;font-size:13px;color:#1a1714;background:transparent;padding:0;-webkit-appearance:none;font-family:inherit}
.dl-fe select option{background:#fff}
.dl-dims{display:flex;gap:6px;align-items:stretch}
.dl-dims .dl-fe{flex:1}
.dl-dims-x{color:#9e978f;font-size:12px;display:flex;align-items:center;padding-top:14px;font-variant-numeric:tabular-nums}
.dl-vol{display:flex;align-items:center;justify-content:flex-end;margin-top:6px;gap:4px}
.dl-vol-l{font-size:10px;color:#9e978f;text-transform:uppercase;letter-spacing:.06em}
.dl-vol-v{font-size:12px;color:#6b655e;font-variant-numeric:tabular-nums}
.dl-bdg{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:500;font-variant-numeric:tabular-nums}
.dl-bdg-org{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
.dl-bdg-grn{background:#dcfce7;color:#15803d;border:1px solid #bbf7d0}
.dl-pkg-row{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
.dl-pkg{padding:10px 11px;border-radius:8px;border:1px solid #e8e4df;background:#f9f8f7;cursor:pointer;transition:all .15s}
.dl-pkg:hover{border-color:#bfb9b2;background:#f2f0ed}
.dl-pkg.on{border-color:#f5501e;background:#fff0eb}
.dl-pkg-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:3px}
.dl-pkg-n{font-size:11px;color:#6b655e;line-height:1.3}
.dl-pkg.on .dl-pkg-n{color:#e04418}
.dl-pkg-p{font-size:10px;color:#9e978f;font-variant-numeric:tabular-nums}
.dl-pkg.on .dl-pkg-p{color:#b45309}
.dl-pkg-chk{width:13px;height:13px;border-radius:50%;border:1.5px solid #d4cfc9;background:#fff;flex-shrink:0;margin-left:4px;margin-top:1px;display:flex;align-items:center;justify-content:center;transition:all .15s}
.dl-pkg.on .dl-pkg-chk{border-color:#f5501e;background:#f5501e}
.dl-pkg.on .dl-pkg-chk::after{content:'';width:5px;height:5px;border-radius:50%;background:#fff}
.dl-ftr{padding:13px 20px;background:#f9f8f7;border-top:1px solid #e8e4df;display:flex;align-items:center;justify-content:space-between;gap:10px}
.dl-ftr-meta{flex:1;min-width:0}
.dl-ftr-l{font-size:10px;color:#9e978f;text-transform:uppercase;letter-spacing:.06em}
.dl-ftr-id{font-size:11px;color:#6b655e;margin-top:2px;font-variant-numeric:tabular-nums;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dl-btns{display:flex;gap:8px;flex-shrink:0}
.dl-btn-cancel{padding:8px 14px;border-radius:8px;background:transparent;border:1px solid #d4cfc9;color:#6b655e;font-size:12px;cursor:pointer;font-family:inherit;transition:all .15s}
.dl-btn-cancel:hover{border-color:#6b655e;color:#1a1714;background:#f2f0ed}
.dl-btn-go{padding:8px 18px;border-radius:8px;background:#f5501e;border:0;color:#fff;font-size:12px;font-weight:500;cursor:pointer;font-family:inherit;transition:all .15s;display:flex;align-items:center;gap:5px}
.dl-btn-go:hover{background:#e04418}
.dl-btn-go:disabled{background:#d4cfc9;color:#9e978f;cursor:not-allowed}
.dl-loader{padding:40px 20px;text-align:center;color:#9e978f;font-size:13px}
.dl-err{padding:20px;color:#c00;font-size:13px}
.dl-ok{padding:20px;text-align:center}
.dl-ok-ico{font-size:32px;margin-bottom:8px}
.dl-ok-t{font-size:15px;font-weight:500;color:#1a1714;margin-bottom:4px}
.dl-ok-s{font-size:12px;color:#6b655e;font-variant-numeric:tabular-nums}
</style>

<div id="dl-overlay">
<div id="dl-modal">
  <div class="dl-hdr">
    <div class="dl-hdr-l">
      <div class="dl-hdr-ico">🚚</div>
      <div>
        <div class="dl-hdr-t">Оформление заявки — Деловые Линии</div>
        <div class="dl-hdr-s" id="dl-order-label">Загрузка…</div>
      </div>
    </div>
    <div class="dl-hdr-x" id="dl-close">×</div>
  </div>
  <div class="dl-scroll" id="dl-body">
    <div class="dl-loader">Загружаем данные заказа…</div>
  </div>
  <div class="dl-ftr" id="dl-ftr" style="display:none">
    <div class="dl-ftr-meta">
      <div class="dl-ftr-l">Заказ inSales</div>
      <div class="dl-ftr-id" id="dl-ftr-id">—</div>
    </div>
    <div class="dl-btns">
      <button class="dl-btn-cancel" id="dl-btn-cancel">Отмена</button>
      <button class="dl-btn-go" id="dl-btn-go">Оформить →</button>
    </div>
  </div>
</div>
</div>

<script>
(function(){
  var B='{$b}',I='{$i}',O='{$o}';
  var pData=null;
  var pkgUids={'none':null,'box':'0x947845D9BDC69EFA49630D8C080C4FBE','bag':'0x8d3b8f2a1c4e9f7b2a3d5e6f7a8b9c0d','crate':'0x9f2a1b3c4d5e6f7a8b9c0d1e2f3a4b5c','bubble':'0xab1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e','stretch':'0xbc2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f'};
  var pkgSelected='none';
  var typeLabel={'person':'Физлицо','ip':'ИП','company':'Юрлицо'};

  function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
  function fetchJ(url,body){return fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(function(r){return r.json();});}

function close(){
  window.parent.postMessage({dlAction:'close'},'*');
}
  document.getElementById('dl-close').addEventListener('click',close);
  document.getElementById('dl-btn-cancel').addEventListener('click',close);
  document.getElementById('dl-overlay').addEventListener('click',function(e){if(e.target===this)close();});

  function calcVol(){
    var l=parseFloat(document.getElementById('dl-iL').value)||0;
    var w=parseFloat(document.getElementById('dl-iWi').value)||0;
    var h=parseFloat(document.getElementById('dl-iH').value)||0;
    var v=document.getElementById('dl-vol');
    if(v)v.textContent=(l*w*h).toFixed(4)+' м³';
  }

  function selPkg(el,id){
    document.querySelectorAll('.dl-pkg').forEach(function(p){p.classList.remove('on');});
    el.classList.add('on');
    pkgSelected=id;
  }
  window.__dlSelPkg=selPkg;

  function loadDerivalDates(){
    var sel=document.getElementById('dl-iDerivalDate');
    if(!sel)return;
    fetch(B+'/insales/derival/dates?insales_id='+I,{headers:{Accept:'application/json'}})
      .then(function(r){return r.json();})
      .then(function(j){
        if(!j.ok||!j.dates||!j.dates.length){
          sel.innerHTML='<option value="">Нет доступных дат</option>';
          return;
        }
        sel.innerHTML='';
        j.dates.slice(0,14).forEach(function(d){
          var opt=document.createElement('option');
          opt.value=d;
          opt.textContent=new Date(d).toLocaleDateString('ru-RU',{day:'2-digit',month:'2-digit',year:'numeric',weekday:'short'});
          sel.appendChild(opt);
        });
        loadDerivalTimeInterval(sel.value);
      })
      .catch(function(){sel.innerHTML='<option value="">Ошибка загрузки</option>';});
  }

  function loadDerivalTimeInterval(date){
    var timeSel=document.getElementById('dl-iDerivalTime');
    if(!timeSel)return;
    if(!date){timeSel.innerHTML='<option value="">— выберите дату —</option>';return;}
    timeSel.innerHTML='<option value="">Загрузка…</option>';
    fetch(B+'/insales/derival/time_interval?insales_id='+I+'&date='+date,{headers:{Accept:'application/json'}})
      .then(function(r){return r.json();})
      .then(function(j){
        if(!j.ok||!j.interval){timeSel.innerHTML='<option value="">Недоступно</option>';return;}
        var from=j.interval.interval_from||'09:00:00';
        var to=j.interval.interval_to||'18:00:00';
        var fromH=parseInt(from.split(':')[0],10);
        var toH=parseInt(to.split(':')[0],10);
        timeSel.innerHTML='';
        for(var h=fromH;h<toH;h+=2){
          var startH=h, endH=Math.min(h+2,toH);
          var opt=document.createElement('option');
          opt.value=String(startH).padStart(2,'0')+':00-'+String(endH).padStart(2,'0')+':00';
          opt.textContent=opt.value;
          timeSel.appendChild(opt);
        }
        if(timeSel.children.length===0){timeSel.innerHTML='<option value="">Недоступно</option>';}
        // Подставляем дефолтное время из настроек
        var defTime=pData&&pData.delivery&&pData.delivery.derival_time_from?pData.delivery.derival_time_from:'';
        if(defTime){
          for(var i=0;i<timeSel.options.length;i++){
            if(timeSel.options[i].value.indexOf(defTime.substring(0,5))===0){
              timeSel.selectedIndex=i;break;
            }
          }
        }
      })
      .catch(function(){timeSel.innerHTML='<option value="">Ошибка</option>';});
  }

  function renderBody(d){
    var s=d.sender,r=d.receiver,c=d.cargo,del=d.delivery;
    var addr=[r.city,r.street,r.house?'д. '+r.house:'',r.flat?'кв. '+r.flat:''].filter(Boolean).join(', ')||'Терминал (ПВЗ)';
    var html='';

    html+='<div class="dl-sec"><div class="dl-sec-hdr"><div class="dl-sec-dot"></div><div class="dl-sec-ttl">Отправитель</div><div class="dl-sec-line"></div></div>';
    html+='<div class="dl-g2">';
    html+='<div class="dl-f"><label>Организация</label><div class="dl-fval">'+esc(s.name)+'</div></div>';
    html+='<div class="dl-f"><label>Тип</label><div style="padding-top:4px"><span class="dl-bdg dl-bdg-org">'+esc(typeLabel[s.type]||s.type)+'</span></div></div>';
    html+='<div class="dl-f"><label>Контактное лицо</label><div class="dl-fval">'+esc(s.contact_name)+'</div></div>';
    html+='<div class="dl-f"><label>Телефон</label><div class="dl-fval mono">'+esc(s.contact_phone)+'</div></div>';
    if(s.terminal_id){html+='<div class="dl-f dl-gfull"><label>Терминал отгрузки</label><div class="dl-fval">Терминал #'+esc(String(s.terminal_id))+'</div></div>';}
    html+='</div></div>';

    html+='<div class="dl-sec"><div class="dl-sec-hdr"><div class="dl-sec-dot"></div><div class="dl-sec-ttl">Получатель</div><div class="dl-sec-line"></div></div>';
    html+='<div class="dl-g2">';
    html+='<div class="dl-f"><label>Имя</label><div class="dl-fval">'+esc(r.name||'—')+'</div></div>';
    html+='<div class="dl-f"><label>Телефон</label><div class="dl-fval mono">'+esc(r.phone||'—')+'</div></div>';
    html+='<div class="dl-f dl-gfull"><label>Адрес</label><div class="dl-fval">'+esc(addr)+'</div></div>';
    html+='<div class="dl-f"><label>Дата отгрузки</label><div class="dl-fval mono">'+esc(del.produce_date)+'</div></div>';
    if(del.interval){html+='<div class="dl-f"><label>Интервал</label><div style="padding-top:4px"><span class="dl-bdg dl-bdg-grn">'+esc(del.interval)+'</span></div></div>';}
    html+='</div></div>';

    if(del.derival_variant==='address'){
      html+='<div class="dl-sec"><div class="dl-sec-hdr"><div class="dl-sec-dot"></div><div class="dl-sec-ttl">Забор груза от адреса</div><div class="dl-sec-line"></div></div>';
      html+='<div class="dl-g2">';
      html+='<div class="dl-fe"><label>Дата забора</label><select id="dl-iDerivalDate"><option value="">Загрузка…</option></select></div>';
      html+='<div class="dl-fe"><label>Время приезда</label><select id="dl-iDerivalTime"><option value="">— выберите дату —</option></select></div>';
      html+='</div></div>';
    }

    html+='<div class="dl-sec"><div class="dl-sec-hdr"><div class="dl-sec-dot"></div><div class="dl-sec-ttl">Груз</div><div class="dl-sec-line"></div></div>';
    html+='<div class="dl-g2">';
    html+='<div class="dl-fe"><label>Вес, кг</label><input type="number" id="dl-iW" value="'+esc(String(c.weight))+'" step="0.001" min="0.01"></div>';
    html+='<div class="dl-fe"><label>Объявл. стоимость, ₽</label><input type="number" id="dl-iS" value="'+esc(String(c.stated_value))+'" step="1" min="0"></div>';
    html+='<div class="dl-gfull"><div class="dl-dims">';
    html+='<div class="dl-fe"><label>Длина, м</label><input type="number" id="dl-iL" value="'+esc(String(c.length))+'" step="0.01" min="0.01"></div>';
    html+='<div class="dl-dims-x">×</div>';
    html+='<div class="dl-fe"><label>Ширина, м</label><input type="number" id="dl-iWi" value="'+esc(String(c.width))+'" step="0.01" min="0.01"></div>';
    html+='<div class="dl-dims-x">×</div>';
    html+='<div class="dl-fe"><label>Высота, м</label><input type="number" id="dl-iH" value="'+esc(String(c.height))+'" step="0.01" min="0.01"></div>';
    html+='</div><div class="dl-vol"><span class="dl-vol-l">Объём</span><span class="dl-vol-v" id="dl-vol">'+((c.length*c.width*c.height).toFixed(4))+' м³</span></div></div>';
    html+='</div></div>';

    html+='<div class="dl-sec"><div class="dl-sec-hdr"><div class="dl-sec-dot m"></div><div class="dl-sec-ttl">Упаковка <span class="dl-sec-sub">· необязательно</span></div><div class="dl-sec-line"></div></div>';
    html+='<div class="dl-pkg-row">';
    var pkgs=[['none','Без упаковки','—'],['box','Картонная коробка','от 110 ₽'],['bag','Мешок','от 65 ₽'],['crate','Деревянная обрешётка','от 1 200 ₽'],['bubble','Пузырчатая плёнка','от 85 ₽'],['stretch','Стрейч-плёнка','от 55 ₽']];
    pkgs.forEach(function(p){
      var on=p[0]==='none'?' on':'';
      html+='<div class="dl-pkg'+on+'" onclick="__dlSelPkg(this,\''+p[0]+'\')"><div class="dl-pkg-top"><span class="dl-pkg-n">'+p[1]+'</span><span class="dl-pkg-chk"></span></div><div class="dl-pkg-p">'+p[2]+'</div></div>';
    });
    html+='</div></div>';

    html+='<div class="dl-sec"><div class="dl-sec-hdr"><div class="dl-sec-dot m"></div><div class="dl-sec-ttl">Плательщик</div><div class="dl-sec-line"></div></div>';
    html+='<div class="dl-fe"><label>Кто оплачивает перевозку</label><select id="dl-iPayer"><option value="sender">Отправитель</option><option value="receiver">Получатель</option></select></div></div>';

    document.getElementById('dl-body').innerHTML=html;
    document.getElementById('dl-ftr').style.display='flex';
    document.getElementById('dl-order-label').textContent='Заказ #'+d.order_number+' · '+O;
    document.getElementById('dl-ftr-id').textContent='#'+d.order_number+' · ID '+O;

    ['dl-iL','dl-iWi','dl-iH'].forEach(function(id){
      var el=document.getElementById(id);
      if(el)el.addEventListener('input',calcVol);
    });

    if(del.derival_variant==='address'){
      loadDerivalDates();
      var derivalDateSel=document.getElementById('dl-iDerivalDate');
      if(derivalDateSel){
        derivalDateSel.addEventListener('change',function(){
          loadDerivalTimeInterval(this.value);
        });
      }
    }
  }
function sendResize(){
  var h=document.getElementById('dl-modal').scrollHeight+20;
  window.parent.postMessage({dlAction:'resize',height:h},'*');
}
  fetchJ(B+'/insales/orders/preview',{insales_id:I,insales_order_id:O})
    .then(function(d){
      if(!d.ok){document.getElementById('dl-body').innerHTML='<div class="dl-err">Ошибка: '+esc(d.error)+'</div>';return;}
      pData=d;
      renderBody(d);
    })
    .catch(function(e){document.getElementById('dl-body').innerHTML='<div class="dl-err">Ошибка загрузки: '+esc(e.message)+'</div>';});

  document.getElementById('dl-btn-go').addEventListener('click',function(){
    var btn=this;
    btn.disabled=true;btn.textContent='Оформляем…';
    var derivalDateEl=document.getElementById('dl-iDerivalDate');
    var derivalTimeEl=document.getElementById('dl-iDerivalTime');
    var body={
      insales_id:I,insales_order_id:O,
      weight:parseFloat(document.getElementById('dl-iW').value)||undefined,
      stated_value:parseFloat(document.getElementById('dl-iS').value)||undefined,
      length:parseFloat(document.getElementById('dl-iL').value)||undefined,
      width:parseFloat(document.getElementById('dl-iWi').value)||undefined,
      height:parseFloat(document.getElementById('dl-iH').value)||undefined,
      package_uid:pkgUids[pkgSelected]||null,
      primary_payer:document.getElementById('dl-iPayer').value,
      derival_date: derivalDateEl ? (derivalDateEl.value||undefined) : undefined,
      derival_time: derivalTimeEl ? (derivalTimeEl.value||undefined) : undefined,
    };
    fetchJ(B+'/insales/orders/submit',body)
      .then(function(d){
if(d.ok){
  document.getElementById('dl-body').innerHTML='<div class="dl-ok"><div class="dl-ok-ico">✅</div><div class="dl-ok-t">Заявка #'+esc(String(d.request_id))+' оформлена</div><div class="dl-ok-s">Штрихкод: '+esc(d.barcode||'—')+'</div></div>';
  document.getElementById('dl-ftr').style.display='none';
  sendResize();
  setTimeout(function(){
    window.parent.postMessage({dlAction:'success',requestId:d.request_id},'*');
  },2000);
} else {
          btn.disabled=false;btn.textContent='Оформить →';
          var err=document.getElementById('dl-body').querySelector('.dl-err');
          if(!err){var e=document.createElement('div');e.className='dl-err';document.getElementById('dl-body').prepend(e);err=e;}
          err.textContent='Ошибка: '+d.error;
        }
      })
      .catch(function(e){
        btn.disabled=false;btn.textContent='Оформить →';
      });
  });
})();
</script>
HTML;
  }
}
