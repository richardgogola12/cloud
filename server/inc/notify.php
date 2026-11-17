<?php
declare(strict_types=1);
/* ------------------------------------------------------------------
 *  Upozornenia (zvonček na webe + notifikácie v Android aplikácii)
 * ------------------------------------------------------------------ */

/** Vytvorí upozornenie pre používateľa. $link je relatívna adresa v aplikácii (napr. "index.php#shared"). */
function notify(int $uid, string $type, string $title, string $body = '', ?string $link = null): void {
    try {
        q('INSERT INTO ' . P . 'notifications (user_id, type, title, body, link) VALUES (?,?,?,?,?)',
          [$uid, mb_substr($type, 0, 30), mb_substr($title, 0, 200), mb_substr($body, 0, 500), $link]);
        // ponechaj posledných 200 upozornení na používateľa
        q('DELETE FROM ' . P . 'notifications WHERE user_id = ? AND id < (SELECT m FROM (SELECT MIN(id) m FROM (SELECT id FROM ' . P . 'notifications WHERE user_id = ? ORDER BY id DESC LIMIT 200) x) y)', [$uid, $uid]);
    } catch (Throwable $e) { error_log('[cloud] notify: ' . $e->getMessage()); }
}

function notify_admins(string $type, string $title, string $body = '', ?string $link = null): void {
    try {
        foreach (q('SELECT id FROM ' . P . "users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $id) notify((int)$id, $type, $title, $body, $link);
    } catch (Throwable $e) {}
}

/** Ikony podľa typu (Bootstrap Icons) */
function notify_icon(string $type): string {
    return [
        'share' => 'bi-people-fill', 'quota' => 'bi-hdd-fill', 'device' => 'bi-shield-lock-fill', 'trash' => 'bi-trash3-fill',
        'register' => 'bi-person-plus-fill', 'update' => 'bi-cloud-arrow-down-fill', 'integrity' => 'bi-exclamation-triangle-fill',
        'version' => 'bi-clock-history', 'info' => 'bi-info-circle-fill',
    ][$type] ?? 'bi-bell-fill';
}

function notifications_list(int $uid, int $limit = 30, int $afterId = 0): array {
    $rows = q('SELECT * FROM ' . P . 'notifications WHERE user_id = ? AND id > ? ORDER BY id DESC LIMIT ' . (int)$limit, [$uid, $afterId])->fetchAll();
    return array_map(fn($r) => ['id' => (int)$r['id'], 'type' => $r['type'], 'icon' => notify_icon($r['type']), 'title' => $r['title'], 'body' => (string)$r['body'],
        'link' => $r['link'], 'read' => $r['read_at'] !== null, 'date' => $r['created_at']], $rows);
}
function notifications_unread(int $uid): int {
    try { return (int)q('SELECT COUNT(*) FROM ' . P . 'notifications WHERE user_id = ? AND read_at IS NULL', [$uid])->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
