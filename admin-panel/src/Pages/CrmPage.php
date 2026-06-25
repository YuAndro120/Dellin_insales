<?php

declare(strict_types=1);

namespace AdminPanel\Pages;

use AdminPanel\Layout;
use AdminPanel\Repository;

final class CrmPage
{
    private const COLUMNS = [
        'new'        => ['label' => 'Новые',       'color' => '#5fb4ff'],
        'contacted'  => ['label' => 'Связались',   'color' => '#f5a623'],
        'demo'       => ['label' => 'Демо',        'color' => '#a78bfa'],
        'converted'  => ['label' => 'Клиент',      'color' => '#3dd68c'],
        'rejected'   => ['label' => 'Отказ',       'color' => '#6b7280'],
    ];

    public static function handle(Repository $repo, string $method): void
    {
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

        $leads = $repo->leadsList();

        // Группируем по статусу
        $byStatus = array_fill_keys(array_keys(self::COLUMNS), []);
        foreach ($leads as $lead) {
            $s = $lead['crm_status'] ?? 'new';
            if (!isset($byStatus[$s])) $s = 'new';
            $byStatus[$s][] = $lead;
        }

        $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        Layout::head('CRM');
        $unread = $repo->unreadAlertsCount();
        Layout::sidebar('crm', $_SESSION['admin_email'] ?? '', $unread);

        echo '<div class="pg-title">CRM — лиды</div>';
        echo '<div class="pg-sub">Заявки с лендинга. Перетащите карточку для смены статуса.</div>';
        echo '<div style="margin-top:20px;display:flex;gap:12px;align-items:flex-start;overflow-x:auto;padding-bottom:16px">';

        foreach (self::COLUMNS as $statusKey => $col) {
            $color = $col['color'];
            $label = $col['label'];
            $count = count($byStatus[$statusKey]);

            echo '<div class="kanban-col" data-status="' . $h($statusKey) . '" style="min-width:220px;flex-shrink:0">';
            echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">';
            echo '<span style="width:8px;height:8px;border-radius:50%;background:' . $color . ';flex-shrink:0"></span>';
            echo '<span style="font-size:12px;font-weight:600;color:var(--ink2);text-transform:uppercase;letter-spacing:.05em">' . $h($label) . '</span>';
            echo '<span style="font-size:11px;color:var(--ink3);background:var(--bg3);border-radius:10px;padding:1px 7px;font-family:var(--mono)">' . $count . '</span>';
            echo '</div>';
            echo '<div class="kanban-drop" data-status="' . $h($statusKey) . '" style="min-height:80px;display:flex;flex-direction:column;gap:8px">';

            foreach ($byStatus[$statusKey] as $lead) {
                $id          = (int) $lead['id'];
                $email       = $h((string) ($lead['email'] ?? ''));
                $inn         = $h((string) ($lead['inn'] ?? ''));
                $company     = $h((string) ($lead['company_name'] ?? ''));
                $plan        = $h((string) ($lead['plan'] ?? ''));
                $createdAt   = $h((string) ($lead['created_at'] ?? ''));
                $dateStr     = $createdAt ? date('d.m.y', strtotime($createdAt)) : '—';

                $planLabels  = ['calc_only' => 'Старт', 'full' => 'Полный', 'automation' => 'Авт.'];
                $planLabel   = $planLabels[$plan] ?? $plan;

                echo '<div class="kanban-card" draggable="true" data-id="' . $id . '" data-status="' . $h($statusKey) . '">';
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

        // CSS + JS для drag-and-drop
        echo <<<HTML
<style>
.kanban-card{
  background:var(--bg2);border:1px solid var(--line);border-radius:var(--r2);
  padding:12px 14px;cursor:grab;transition:box-shadow .15s,opacity .15s;user-select:none
}
.kanban-card:hover{border-color:var(--line2);box-shadow:0 2px 12px rgba(0,0,0,.3)}
.kanban-card.dragging{opacity:.4;cursor:grabbing}
.kanban-drop{border-radius:var(--r2);padding:4px;transition:background .15s}
.kanban-drop.drag-over{background:var(--bg3);outline:2px dashed var(--line2)}
</style>
<script>
(function(){
  var dragging = null;

  document.querySelectorAll('.kanban-card').forEach(function(card){
    card.addEventListener('dragstart', function(e){
      dragging = card;
      card.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    card.addEventListener('dragend', function(){
      card.classList.remove('dragging');
      dragging = null;
    });
  });

  document.querySelectorAll('.kanban-drop').forEach(function(drop){
    drop.addEventListener('dragover', function(e){
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
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
      var id = dragging.getAttribute('data-id');

      // Оптимистично перемещаем карточку
      drop.appendChild(dragging);
      dragging.setAttribute('data-status', newStatus);

      // Обновляем счётчики
      updateCount(oldStatus);
      updateCount(newStatus);

      // Сохраняем на сервер
      var fd = new FormData();
      fd.append('action', 'move');
      fd.append('id', id);
      fd.append('status', newStatus);
      fetch('/crm', { method: 'POST', body: fd })
        .catch(function(){ console.error('CRM save failed'); });
    });
  });

  function updateCount(status) {
    var col = document.querySelector('.kanban-col[data-status="' + status + '"]');
    if (!col) return;
    var n = col.querySelectorAll('.kanban-card').length;
    var badge = col.querySelector('.nav-badge, span[style*="border-radius:10px"]');
    if (badge) badge.textContent = n;
  }
})();
</script>
HTML;

        Layout::footer();
    }
}
