<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\ShopRepository;

/**
 * Точки входа протокола установки приложения inSales (HTML + HTTP 200).
 */
final class InstallHandlers
{
    public static function install(Config $config, ShopRepository $shops): void
    {
        header('Content-Type: text/html; charset=utf-8');
        if ($config->insalesAppSecret === null || $config->insalesAppSecret === '') {
            http_response_code(500);
            echo '<p>Не задан INSALES_APP_SECRET в .env</p>';
            return;
        }
        $token = (string) ($_GET['token'] ?? '');
        $shop = trim((string) ($_GET['shop'] ?? ''));
        $insalesId = trim((string) ($_GET['insales_id'] ?? ''));
        if ($token === '' || $shop === '' || $insalesId === '') {
            http_response_code(400);
            echo '<p>Ожидаются параметры token, shop, insales_id</p>';
            return;
        }
        $apiPassword = InSalesApiPassword::compute($token, $config->insalesAppSecret);
        $shops->upsertOnInstall($insalesId, $shop, $apiPassword);

        $client = new InSalesClient();

        // Регистрируем webhook на создание и обновление заказов
        $webhookUrl = rtrim($config->publicBridgeUrl, '/') . '/insales/webhook/orders';
        foreach (['orders/create', 'orders/update'] as $topic) {
            try {
                $client->registerWebhook($shop, $config->insalesAppId ?? '', $apiPassword, $topic, $webhookUrl);
            } catch (\Throwable) {
                // Не блокируем установку если webhook уже существует или ошибка
            }
        }

        // Регистрируем виджет в карточке заказа
        try {
            $client->registerWidget(
                $shop,
                $config->insalesAppId ?? '',
                $apiPassword,
                self::buildWidgetCode($config->publicBridgeUrl, $insalesId),
            );
        } catch (\Throwable) {
            // Не блокируем установку если виджет уже существует
        }

        http_response_code(200);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Установка</title></head><body>';
        $q = http_build_query(['shop' => $shop, 'insales_id' => $insalesId]);
        echo '<p>Приложение установлено. Магазин: ' . htmlspecialchars($shop, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p><a href="/insales/app?' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '">Перейти к настройкам (терминал отгрузки)</a></p>';
        echo '</body></html>';
    }

    public static function uninstall(ShopRepository $shops): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $insalesId = trim((string) ($_GET['insales_id'] ?? ''));
        if ($insalesId === '') {
            http_response_code(400);
            echo '<p>Ожидается insales_id</p>';
            return;
        }
        $shops->markUninstalled($insalesId);
        http_response_code(200);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Удалено</title></head><body><p>Приложение отключено для магазина.</p></body></html>';
    }

  private static function buildWidgetCode(string $bridgeUrl, string $insalesId): string
  {
    $url = rtrim($bridgeUrl, '/');
    return <<<HTML
<html><head><meta charset="utf-8">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:13px;color:#1a1a1a;background:#fff;padding:10px}
.btn-primary{display:block;width:100%;padding:9px;background:#f60;color:#fff;border:0;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:background .2s}
.btn-primary:hover{background:#e55a00}
.btn-primary:disabled{background:#aaa;cursor:default}
.btn-secondary{padding:8px 16px;background:#fff;color:#333;border:1px solid #ddd;border-radius:6px;font-size:13px;cursor:pointer}
.btn-green{background:#2e7d32;color:#fff;border:0;border-radius:6px;padding:9px 20px;font-size:13px;font-weight:600;cursor:pointer}
.ok{color:#2e7d32;margin-top:6px;font-size:12px}
.err{color:#c00;margin-top:6px;font-size:12px}

/* Overlay */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9998;justify-content:center;align-items:flex-start;padding:20px;overflow-y:auto}
.overlay.active{display:flex}

/* Modal */
.modal{background:#fff;border-radius:12px;width:100%;max-width:560px;margin:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden}
.modal-header{background:#1a1a1a;color:#fff;padding:14px 18px;display:flex;justify-content:space-between;align-items:center}
.modal-header h2{font-size:15px;font-weight:600}
.modal-close{background:0;border:0;color:#fff;font-size:20px;cursor:pointer;line-height:1;opacity:.7}
.modal-close:hover{opacity:1}
.modal-body{padding:0}

/* Sections */
.section{border-bottom:1px solid #f0f0f0;padding:14px 18px}
.section:last-child{border-bottom:0}
.section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#999;margin-bottom:10px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:6px}
.row.full{grid-template-columns:1fr}
.field label{font-size:11px;color:#888;margin-bottom:2px;display:block}
.field span{font-size:13px;color:#1a1a1a;font-weight:500}
.field input,select{width:100%;padding:6px 8px;border:1px solid #ddd;border-radius:5px;font-size:13px;background:#fafafa}
.field input:focus,.field select:focus{outline:0;border-color:#f60;background:#fff}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;background:#fff3e0;color:#e65100}

/* Footer */
.modal-footer{padding:14px 18px;border-top:1px solid #f0f0f0;display:flex;gap:8px;justify-content:flex-end;align-items:center}
.total-price{font-size:16px;font-weight:700;color:#f60;margin-right:auto}

/* Labels form */
.label-form{margin-top:10px;padding:10px;background:#f9f9f9;border-radius:6px;display:none}
.label-form.active{display:block}
</style>
</head><body>

<button class="btn-primary" id="btnOpen">📦 Оформить в Деловые Линии</button>
<div id="statusMain"></div>

<!-- Overlay + Modal -->
<div class="overlay" id="overlay">
<div class="modal">
  <div class="modal-header">
    <h2>Оформление заявки — Деловые Линии</h2>
    <button class="modal-close" id="btnClose">×</button>
  </div>
  <div class="modal-body" id="modalBody">
    <div class="section"><p style="color:#888;text-align:center;padding:20px">Загрузка данных…</p></div>
  </div>
  <div class="modal-footer" id="modalFooter" style="display:none">
    <span class="total-price" id="totalPrice"></span>
    <button class="btn-secondary" id="btnCancel">Отмена</button>
    <button class="btn-green" id="btnConfirm">Оформить заявку →</button>
  </div>
</div>
</div>

<script>
var insalesId='{$insalesId}';
var bridgeUrl='{$url}';
var currentOrderId=null;
var previewData=null;

function \$(id){return document.getElementById(id);}
function fetchJ(u,b){return fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)}).then(function(r){return r.json();});}

// Открыть модальное окно
\$('btnOpen').addEventListener('click',function(){
  var orderId=window.order_info?window.order_info.id:null;
  if(!orderId){
    \$('statusMain').innerHTML='<p class="err">Не удалось получить ID заказа</p>';
    return;
  }
  currentOrderId=String(orderId);
  \$('overlay').classList.add('active');
  loadPreview();
});

// Закрыть
function closeModal(){
  \$('overlay').classList.remove('active');
  \$('modalFooter').style.display='none';
}
\$('btnClose').addEventListener('click',closeModal);
\$('btnCancel').addEventListener('click',closeModal);
\$('overlay').addEventListener('click',function(e){if(e.target===this)closeModal();});

// Загрузить превью
function loadPreview(){
  \$('modalBody').innerHTML='<div class="section"><p style="color:#888;text-align:center;padding:20px">Загрузка данных…</p></div>';
  \$('modalFooter').style.display='none';
  fetchJ(bridgeUrl+'/insales/orders/preview',{insales_id:insalesId,insales_order_id:currentOrderId})
  .then(function(d){
    if(!d.ok){
      \$('modalBody').innerHTML='<div class="section"><p class="err">'+d.error+'</p></div>';
      return;
    }
    previewData=d;
    renderModal(d);
    \$('modalFooter').style.display='flex';
  })
  .catch(function(e){
    \$('modalBody').innerHTML='<div class="section"><p class="err">Ошибка: '+e.message+'</p></div>';
  });
}

function renderModal(d){
  var s=d.sender,r=d.receiver,c=d.cargo,del=d.delivery;
  var typeLabel={'person':'Физлицо','ip':'ИП','company':'Юрлицо'};
  var html='';

  // Отправитель
  html+='<div class="section">';
  html+='<div class="section-title">Отправитель</div>';
  html+='<div class="row">';
  html+='<div class="field"><label>Название / ФИО</label><span>'+esc(s.name)+'</span></div>';
  html+='<div class="field"><label>Тип</label><span class="badge">'+esc(typeLabel[s.type]||s.type)+'</span></div>';
  html+='</div>';
  html+='<div class="row">';
  html+='<div class="field"><label>Контактное лицо</label><span>'+esc(s.contact_name)+'</span></div>';
  html+='<div class="field"><label>Телефон</label><span>'+esc(s.contact_phone)+'</span></div>';
  html+='</div>';
  html+='</div>';

  // Получатель
  html+='<div class="section">';
  html+='<div class="section-title">Получатель</div>';
  html+='<div class="row">';
  html+='<div class="field"><label>Имя</label><span>'+esc(r.name||'—')+'</span></div>';
  html+='<div class="field"><label>Телефон</label><span>'+esc(r.phone||'—')+'</span></div>';
  html+='</div>';
  html+='<div class="row full"><div class="field"><label>Адрес</label><span>'+esc([r.city,r.street,r.house,r.flat?'кв.'+r.flat:''].filter(Boolean).join(', ')||'Терминал (ПВЗ)')+'</span></div></div>';
  html+='</div>';

  // Груз
  html+='<div class="section">';
  html+='<div class="section-title">Груз</div>';
  html+='<div class="row">';
  html+='<div class="field"><label>Вес, кг</label><input type="number" id="fWeight" step="0.001" min="0.01" value="'+c.weight+'"></div>';
  html+='<div class="field"><label>Объявл. стоимость, ₽</label><input type="number" id="fStated" step="0.01" min="0" value="'+c.stated_value+'"></div>';
  html+='</div>';
  html+='<div class="row">';
  html+='<div class="field"><label>Длина × Ширина × Высота, м</label>';
  html+='<div style="display:flex;gap:4px;align-items:center">';
  html+='<input type="number" id="fLen" step="0.01" min="0.01" value="'+c.length+'" style="width:60px">';
  html+='<span style="color:#aaa">×</span>';
  html+='<input type="number" id="fWid" step="0.01" min="0.01" value="'+c.width+'" style="width:60px">';
  html+='<span style="color:#aaa">×</span>';
  html+='<input type="number" id="fHei" step="0.01" min="0.01" value="'+c.height+'" style="width:60px">';
  html+='</div></div>';
  html+='</div>';
  html+='</div>';

  // Доставка
  html+='<div class="section">';
  html+='<div class="section-title">Доставка</div>';
  html+='<div class="row">';
  html+='<div class="field"><label>Дата отгрузки</label><span>'+esc(del.produce_date)+'</span></div>';
  if(del.interval){
    html+='<div class="field"><label>Интервал</label><span class="badge">'+esc(del.interval)+'</span></div>';
  }
  html+='</div>';
  html+='</div>';

  // Этикетка (после оформления)
  html+='<div class="section" id="labelSection" style="display:none">';
  html+='<div class="section-title">Этикетка</div>';
  html+='<div class="row">';
  html+='<div class="field"><label>Артикул грузоместа</label><input type="text" id="cargoPlace" maxlength="30" placeholder="Необязательно"></div>';
  html+='<div class="field"><label>Формат</label><select id="labelFormat"><option value="80x50">80×50 мм</option><option value="a4">A4</option></select></div>';
  html+='</div>';
  html+='<button class="btn-green" onclick="submitLabels()" style="margin-top:8px;width:100%">Сформировать этикетку</button>';
  html+='<div id="labelStatus" style="margin-top:6px"></div>';
  html+='</div>';

  \$('modalBody').innerHTML=html;
}

function esc(s){
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Подтвердить и оформить
\$('btnConfirm').addEventListener('click',function(){
  var btn=this;
  btn.disabled=true;
  btn.textContent='Оформляем…';
  fetchJ(bridgeUrl+'/insales/orders/submit',{
    insales_id:insalesId,
    insales_order_id:currentOrderId,
    weight:parseFloat(\$('fWeight').value)||undefined,
    stated_value:parseFloat(\$('fStated').value)||undefined,
    length:parseFloat(\$('fLen').value)||undefined,
    width:parseFloat(\$('fWid').value)||undefined,
    height:parseFloat(\$('fHei').value)||undefined,
  })
  .then(function(d){
    if(d.ok){
      \$('btnConfirm').style.display='none';
      \$('btnCancel').textContent='Закрыть';
      \$('totalPrice').innerHTML='✓ Заявка #'+d.request_id;
      \$('totalPrice').style.color='#2e7d32';
      \$('statusMain').innerHTML='<p class="ok">✓ Заявка #'+d.request_id+' оформлена</p>';
      \$('labelSection').style.display='block';
    } else {
      btn.disabled=false;
      btn.textContent='Оформить заявку →';
      \$('totalPrice').innerHTML='<span style="color:#c00">'+esc(d.error)+'</span>';
    }
  })
  .catch(function(e){
    btn.disabled=false;
    btn.textContent='Оформить заявку →';
    \$('totalPrice').innerHTML='<span style="color:#c00">Ошибка сети</span>';
  });
});

// Этикетки
function submitLabels(){
  var cp=\$('cargoPlace').value.trim();
  var fmt=\$('labelFormat').value;
  var st=\$('labelStatus');
  st.innerHTML='<p style="color:#888;font-size:12px">Формируем…</p>';
  fetchJ(bridgeUrl+'/insales/orders/labels',{
    insales_id:insalesId,
    insales_order_id:currentOrderId,
    action:'submit',
    cargo_place:cp||null,
    format:fmt
  }).then(function(d){
    if(!d.ok)throw new Error(d.error||'Ошибка');
    st.innerHTML='<p style="color:#888;font-size:12px">Ожидаем готовности…</p>';
    pollLabels(0);
  }).catch(function(e){
    st.innerHTML='<p class="err">'+esc(e.message)+'</p>';
  });
}

function pollLabels(n){
  if(n>10){
    \$('labelStatus').innerHTML='<p class="err">Не готово. Попробуйте позже.</p>';
    return;
  }
  setTimeout(function(){
    fetchJ(bridgeUrl+'/insales/orders/labels',{
      insales_id:insalesId,
      insales_order_id:currentOrderId,
      action:'get'
    }).then(function(d){
      if(d.ok&&d.ready&&d.files&&d.files.length){
        var h='<p class="ok">Этикетка готова:</p>';
        d.files.forEach(function(f){h+='<p><a href="'+f+'" target="_blank" style="color:#f60">Скачать ↓</a></p>';});
        \$('labelStatus').innerHTML=h;
      } else pollLabels(n+1);
    }).catch(function(){pollLabels(n+1);});
  },3000);
}
</script>
</body></html>
HTML;
  }
}