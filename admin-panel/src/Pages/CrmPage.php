<?php

declare(strict_types=1);

namespace AdminPanel\Pages;

use AdminPanel\Layout;
use AdminPanel\Repository;

final class CrmPage
{
  private const COLUMNS = [
    'new'       => ['label' => 'Новые',     'color' => '#5fb4ff'],
    'contacted' => ['label' => 'Связались', 'color' => '#f5a623'],
    'demo'      => ['label' => 'Демо',      'color' => '#a78bfa'],
    'converted' => ['label' => 'Клиент',    'color' => '#3dd68c'],
    'rejected'  => ['label' => 'Отказ',     'color' => '#6b7280'],
  ];

  public static function handle(Repository $repo, string $method): void
  {
    $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    // AJAX: смена статуса
    if ($method === 'POST' && ($_POST['action'] ?? '') === 'move') {
      header('Content-Type: application/json; charset=utf-8');
      $id     = (int) ($_POST['id'] ?? 0);
      $status = trim((string) ($_POST['status'] ?? ''));
      if ($id > 0 && isset(self::COLUMNS[$status])) {
        $repo->updateLeadStatus($id, $status);
        echo json_encode(['ok' => true]);
      } else {
        http_response_code(422);
        echo json_encode(['ok' => false]);
      }
      return;
    }

    // AJAX: добавить комментарий
    if ($method === 'POST' && ($_POST['action'] ?? '') === 'comment') {
      header('Content-Type: application/json; charset=utf-8');
      $leadId  = (int) ($_POST['lead_id'] ?? 0);
      $comment = trim((string) ($_POST['comment'] ?? ''));
      if ($leadId > 0 && $comment !== '') {
        $repo->addLeadComment($leadId, $comment);
        echo json_encode(['ok' => true, 'comment' => $comment, 'date' => date('d.m.Y H:i')]);
      } else {
        http_response_code(422);
        echo json_encode(['ok' => false]);
      }
      return;
    }

    $leads = $repo->leadsList();
    $byStatus = array_fill_keys(array_keys(self::COLUMNS), []);
    foreach ($leads as $lead) {
      $s = $lead['crm_status'] ?? 'new';
      if (!isset($byStatus[$s])) $s = 'new';
      $byStatus[$s][] = $lead;
    }

    Layout::head('CRM');
    $unread = $repo->unreadAlertsCount();
    Layout::sidebar('crm', $_SESSION['admin_email'] ?? '', $unread);

    $planLabels = ['calc_only' => 'Старт', 'full' => 'Полный', 'automation' => 'Авт.'];

    echo '<div class="pg-title">CRM — лиды</div>';
    echo '<div class="pg-sub">Заявки с лендинга. Перетащите карточку или кликните для просмотра.</div>';

    // Канбан — без overflow-x, колонки растягиваются
    echo '<div style="margin-top:20px;display:grid;grid-template-columns:repeat(5,1fr);gap:12px;align-items:start">';

    foreach (self::COLUMNS as $statusKey => $col) {
      $color = $col['color'];
      $label = $col['label'];
      $count = count($byStatus[$statusKey]);

      echo '<div class="kanban-col" data-status="' . $h($statusKey) . '">';
      echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">';
      echo '<span style="width:8px;height:8px;border-radius:50%;background:' . $color . ';flex-shrink:0"></span>';
      echo '<span style="font-size:12px;font-weight:600;color:var(--ink2);text-transform:uppercase;letter-spacing:.05em">' . $h($label) . '</span>';
      echo '<span class="col-count" style="font-size:11px;color:var(--ink3);background:var(--bg3);border-radius:10px;padding:1px 7px;font-family:var(--mono)">' . $count . '</span>';
      echo '</div>';
      echo '<div class="kanban-drop" data-status="' . $h($statusKey) . '" style="min-height:80px;display:flex;flex-direction:column;gap:8px">';

      foreach ($byStatus[$statusKey] as $lead) {
        $id        = (int) $lead['id'];
        $email     = $h((string) ($lead['email'] ?? ''));
        $inn       = $h((string) ($lead['inn'] ?? ''));
        $company   = $h((string) ($lead['company_name'] ?? ''));
        $plan      = (string) ($lead['plan'] ?? '');
        $createdAt = (string) ($lead['created_at'] ?? '');
        $dateStr   = $createdAt ? date('d.m.y', strtotime($createdAt)) : '—';
        $planLabel = $h($planLabels[$plan] ?? $plan);

        echo '<div class="kanban-card" draggable="true" data-id="' . $id . '" data-status="' . $h($statusKey) . '" onclick="openLead(' . $id . ')">';
        if ($company !== '') {
          echo '<div style="font-size:13px;font-weight:600;color:var(--ink);margin-bottom:4px">' . $company . '</div>';
        }
        echo '<div style="font-size:12px;color:var(--accent);margin-bottom:4px">' . $email . '</div>';
        if ($inn !== '') {
          echo '<div style="font-size:11px;color:var(--ink3);font-family:var(--mono)">ИНН: ' . $inn . '</div>';
        }
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px">';
        if ($planLabel !== '') {
          echo '<span style="font-size:10px;font-weight:600;padding:2px 7px;border-radius:10px;background:var(--accent-dim);color:var(--accent)">' . $planLabel . '</span>';
        }
        echo '<span style="font-size:10px;color:var(--ink3);font-family:var(--mono)">' . $dateStr . '</span>';
        echo '</div>';
        echo '</div>';
      }

      echo '</div></div>';
    }

    echo '</div>';

    // Данные лидов для JS (детальная панель)
    $leadsJson = json_encode(array_map(function ($lead) use ($planLabels) {
      $comments = [];
      return [
        'id'           => (int) $lead['id'],
        'email'        => (string) ($lead['email'] ?? ''),
        'inn'          => (string) ($lead['inn'] ?? ''),
        'company_name' => (string) ($lead['company_name'] ?? ''),
        'plan'         => $planLabels[$lead['plan'] ?? ''] ?? ($lead['plan'] ?? ''),
        'crm_status'   => (string) ($lead['crm_status'] ?? 'new'),
        'created_at'   => (string) ($lead['created_at'] ?? ''),
      ];
    }, $leads), JSON_UNESCAPED_UNICODE);

    echo <<<HTML
<!-- Детальная панель -->
<div id="lead-panel" style="display:none;position:fixed;top:0;right:0;bottom:0;width:380px;background:var(--bg2);border-left:1px solid var(--line);z-index:200;overflow-y:auto;padding:24px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
    <div style="font-size:15px;font-weight:600" id="panel-company">—</div>
    <button onclick="closeLead()" style="background:none;border:none;color:var(--ink3);cursor:pointer;font-size:18px;padding:4px">✕</button>
  </div>
  <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:20px">
    <div style="display:flex;gap:8px">
      <span style="font-size:11px;color:var(--ink3);width:80px;flex-shrink:0;padding-top:2px">Email</span>
      <span id="panel-email" style="font-size:13px;color:var(--accent);word-break:break-all">—</span>
    </div>
    <div style="display:flex;gap:8px">
      <span style="font-size:11px;color:var(--ink3);width:80px;flex-shrink:0;padding-top:2px">ИНН</span>
      <span id="panel-inn" style="font-size:13px;font-family:var(--mono)">—</span>
    </div>
    <div style="display:flex;gap:8px">
      <span style="font-size:11px;color:var(--ink3);width:80px;flex-shrink:0;padding-top:2px">Тариф</span>
      <span id="panel-plan" style="font-size:13px">—</span>
    </div>
    <div style="display:flex;gap:8px">
      <span style="font-size:11px;color:var(--ink3);width:80px;flex-shrink:0;padding-top:2px">Дата</span>
      <span id="panel-date" style="font-size:13px;font-family:var(--mono)">—</span>
    </div>
    <div style="display:flex;gap:8px">
      <span style="font-size:11px;color:var(--ink3);width:80px;flex-shrink:0;padding-top:2px">Статус</span>
      <span id="panel-status" style="font-size:13px">—</span>
    </div>
  </div>

  <div style="height:1px;background:var(--line);margin-bottom:20px"></div>

  <div style="font-size:12px;font-weight:600;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">Комментарии</div>
  <div id="panel-comments" style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px"></div>
  <div style="display:flex;gap:8px">
    <textarea id="panel-comment-input" placeholder="Добавить комментарий…" rows="2"
      style="flex:1;padding:8px 10px;background:var(--bg3);border:1px solid var(--line);border-radius:var(--r);color:var(--ink);font-size:13px;font-family:var(--sans);outline:none;resize:none"></textarea>
    <button onclick="addComment()" style="padding:8px 12px;background:var(--accent-dim);color:var(--accent);border:1px solid var(--accent-dim);border-radius:var(--r);cursor:pointer;font-size:12px;font-weight:600;white-space:nowrap;align-self:flex-end">Добавить</button>
  </div>
</div>
<div id="lead-overlay" onclick="closeLead()" style="display:none;position:fixed;inset:0;z-index:199;background:rgba(0,0,0,.4)"></div>

<style>
.kanban-card{
  background:var(--bg2);border:1px solid var(--line);border-radius:var(--r2);
  padding:12px 14px;cursor:pointer;transition:box-shadow .15s,opacity .15s;user-select:none
}
.kanban-card:hover{border-color:var(--line2);box-shadow:0 2px 12px rgba(0,0,0,.3)}
.kanban-card.dragging{opacity:.4;cursor:grabbing}
.kanban-drop{border-radius:var(--r2);padding:4px;transition:background .15s}
.kanban-drop.drag-over{background:var(--bg3);outline:2px dashed var(--line2)}
#panel-comment-input:focus{border-color:var(--accent)}
</style>

<script>
var LEADS = {$leadsJson};
var currentLeadId = null;
var comments = {};

function openLead(id) {
  var lead = LEADS.find(function(l){ return l.id === id; });
  if (!lead) return;
  currentLeadId = id;
  document.getElementById('panel-company').textContent = lead.company_name || lead.email;
  document.getElementById('panel-email').textContent = lead.email || '—';
  document.getElementById('panel-inn').textContent = lead.inn || '—';
  document.getElementById('panel-plan').textContent = lead.plan || '—';
  document.getElementById('panel-date').textContent = lead.created_at ? lead.created_at.substring(0,10) : '—';
  document.getElementById('panel-status').textContent = lead.crm_status || '—';

  // Загрузить комментарии
  loadComments(id);

  document.getElementById('lead-panel').style.display = 'block';
  document.getElementById('lead-overlay').style.display = 'block';
}

function closeLead() {
  document.getElementById('lead-panel').style.display = 'none';
  document.getElementById('lead-overlay').style.display = 'none';
  currentLeadId = null;
}

function loadComments(leadId) {
  var container = document.getElementById('panel-comments');
  container.innerHTML = '<span style="font-size:12px;color:var(--ink3)">Загрузка…</span>';
  fetch('/crm?comments=1&lead_id=' + leadId)
    .then(function(r){ return r.json(); })
    .then(function(data){
      renderComments(data.comments || []);
    })
    .catch(function(){ container.innerHTML = ''; });
}

function renderComments(list) {
  var container = document.getElementById('panel-comments');
  if (!list.length) { container.innerHTML = '<span style="font-size:12px;color:var(--ink3)">Комментариев пока нет</span>'; return; }
  container.innerHTML = list.map(function(c){
    return '<div style="background:var(--bg3);border-radius:var(--r);padding:10px 12px">'
      + '<div style="font-size:13px;color:var(--ink);line-height:1.5">' + escHtml(c.comment) + '</div>'
      + '<div style="font-size:10px;color:var(--ink3);font-family:var(--mono);margin-top:4px">' + escHtml(c.created_at) + '</div>'
      + '</div>';
  }).join('');
}

function addComment() {
  var text = document.getElementById('panel-comment-input').value.trim();
  if (!text || !currentLeadId) return;
  var btn = document.querySelector('#lead-panel button[onclick="addComment()"]');
  btn.disabled = true;
  var fd = new FormData();
  fd.append('action', 'comment');
  fd.append('lead_id', currentLeadId);
  fd.append('comment', text);
  fetch('/crm', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (data.ok) {
        document.getElementById('panel-comment-input').value = '';
        loadComments(currentLeadId);
      }
      btn.disabled = false;
    })
    .catch(function(){ btn.disabled = false; });
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Drag-and-drop
var dragging = null;
document.querySelectorAll('.kanban-card').forEach(function(card){
  card.addEventListener('dragstart', function(e){
    dragging = card;
    card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.stopPropagation();
  });
  card.addEventListener('dragend', function(){
    card.classList.remove('dragging');
    dragging = null;
  });
});

document.querySelectorAll('.kanban-drop').forEach(function(drop){
  drop.addEventListener('dragover', function(e){
    e.preventDefault();
    drop.classList.add('drag-over');
  });
  drop.addEventListener('dragleave', function(){
    drop.classList.remove('drag-over');
  });
  drop.addEventListener('drop', function(e){
    e.preventDefault();
    drop.classList.remove('drag-over');
    if (!dragging) return;
    var newStatus = drop.getAttribute('data-status');
    var oldStatus = dragging.getAttribute('data-status');
    if (newStatus === oldStatus) return;
    var id = parseInt(dragging.getAttribute('data-id'));
    drop.appendChild(dragging);
    dragging.setAttribute('data-status', newStatus);
    updateCount(oldStatus);
    updateCount(newStatus);
    var lead = LEADS.find(function(l){ return l.id === id; });
    if (lead) lead.crm_status = newStatus;
    var fd = new FormData();
    fd.append('action', 'move');
    fd.append('id', id);
    fd.append('status', newStatus);
    fetch('/crm', { method: 'POST', body: fd });
  });
});

function updateCount(status) {
  var col = document.querySelector('.kanban-col[data-status="' + status + '"]');
  if (!col) return;
  col.querySelector('.col-count').textContent = col.querySelectorAll('.kanban-card').length;
}
</script>
HTML;

    Layout::footer();
  }
}
