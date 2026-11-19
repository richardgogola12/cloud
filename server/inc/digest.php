<?php
/* Týždenný e-mailový súhrn: čo pribudlo, čo s tebou zdieľali, koľko miesta ostáva, spomienky. */

function digest_enabled(array $u): bool { return (user_prefs($u)['digest'] ?? true) !== false; }

/** Údaje súhrnu za posledných 7 dní (null = nie je čo poslať). */
function digest_data(array $u): ?array {
    $uid = (int)$u['id'];
    $since = date('Y-m-d H:i:s', time() - 7 * 86400);
    $P = P;
    $up = q("SELECT COUNT(*) n, COALESCE(SUM(size),0) s FROM {$P}files WHERE user_id = ? AND deleted_at IS NULL AND created_at >= ?", [$uid, $since])->fetch();
    $byOthers = q("SELECT u.name, COUNT(*) n FROM {$P}activity a JOIN {$P}users u ON u.id = a.actor_id
                   WHERE a.owner_id = ? AND a.actor_id <> ? AND a.action IN ('upload','update','edit','copy') AND a.created_at >= ? GROUP BY u.name ORDER BY n DESC LIMIT 5", [$uid, $uid, $since])->fetchAll();
    $public = (int)q("SELECT COUNT(*) FROM {$P}activity WHERE owner_id = ? AND actor_id = 0 AND action = 'upload' AND created_at >= ?", [$uid, $since])->fetchColumn();
    $shared = q("SELECT s.item_type, s.item_id, s.permission, u.name owner FROM {$P}user_shares s JOIN {$P}users u ON u.id = s.owner_id
                 WHERE s.target_id = ? AND s.created_at >= ? ORDER BY s.id DESC LIMIT 10", [$uid, $since])->fetchAll();
    $sharedItems = [];
    foreach ($shared as $s) {
        $n = q('SELECT name FROM ' . P . ($s['item_type'] === 'd' ? 'folders' : 'files') . ' WHERE id = ? AND deleted_at IS NULL', [$s['item_id']])->fetchColumn();
        if ($n) $sharedItems[] = ['name' => $n, 'type' => $s['item_type'], 'owner' => $s['owner'], 'perm' => $s['permission']];
    }
    $trash = q("SELECT COUNT(*) n, COALESCE(SUM(size),0) s FROM {$P}files WHERE user_id = ? AND deleted_at IS NOT NULL", [$uid])->fetch();
    $used = used_space($uid);
    $quota = (int)$u['quota'];
    $mem = 0;
    try { foreach (photo_memories($uid, date('Y-m-d', time() + 3 * 86400)) as $g) $mem += count($g['items']); } catch (Throwable $e) {}
    if (!(int)$up['n'] && !$byOthers && !$public && !$sharedItems && !$mem && !($quota && $used / $quota > 0.8)) return null;
    return ['uploaded' => (int)$up['n'], 'uploaded_size' => (int)$up['s'], 'by_others' => $byOthers, 'public' => $public, 'shared' => $sharedItems,
            'trash' => (int)$trash['n'], 'trash_size' => (int)$trash['s'], 'used' => $used, 'quota' => $quota, 'memories' => $mem];
}

function digest_html(array $u, array $d): string {
    $row = fn(string $icon, string $html) => '<tr><td style="padding:8px 10px 8px 0;font-size:20px;vertical-align:top">' . $icon . '</td><td style="padding:8px 0;color:#333">' . $html . '</td></tr>';
    $h = '<p>Ahoj ' . e($u['name']) . ', tu je prehľad tvojho disku za posledný týždeň.</p><table style="width:100%;border-collapse:collapse">';
    if ($d['uploaded']) $h .= $row('📤', '<b>' . $d['uploaded'] . '</b> ' . ($d['uploaded'] === 1 ? 'nový súbor' : ($d['uploaded'] < 5 ? 'nové súbory' : 'nových súborov')) . ' (' . format_bytes($d['uploaded_size']) . ')');
    foreach ($d['by_others'] as $o) $h .= $row('👥', '<b>' . e($o['name']) . '</b> pridal(a) alebo upravil(a) ' . (int)$o['n'] . '× súbory v tvojich zložkách');
    if ($d['public']) $h .= $row('🔗', 'Cez verejný odkaz ti niekto nahral <b>' . $d['public'] . '</b> ' . ($d['public'] === 1 ? 'súbor' : 'súborov'));
    if ($d['shared']) {
        $list = implode('', array_map(fn($s) => '<li>' . ($s['type'] === 'd' ? '📁 ' : '📄 ') . '<b>' . e($s['name']) . '</b> – ' . e($s['owner']) . ($s['perm'] === 'edit' ? ' (môžeš upravovať)' : '') . '</li>', $d['shared']));
        $h .= $row('🤝', 'S tebou zdieľali:<ul style="margin:6px 0 0;padding-left:18px">' . $list . '</ul>');
    }
    if ($d['memories']) $h .= $row('🕰️', 'Tento týždeň máš <b>' . $d['memories'] . '</b> ' . ($d['memories'] < 5 ? 'fotky' : 'fotiek') . ' z rovnakých dní v minulých rokoch – pozri sekciu Fotky.');
    $pct = $d['quota'] ? min(100, round($d['used'] / $d['quota'] * 100)) : null;
    $h .= $row('💾', 'Využívaš <b>' . format_bytes($d['used']) . '</b>' . ($pct !== null ? ' z ' . format_bytes($d['quota']) . ' (' . $pct . ' %)' : '')
        . ($pct !== null ? '<div style="background:#e8eaed;border-radius:6px;height:8px;margin-top:6px"><div style="background:' . ($pct > 90 ? '#d93025' : ($pct > 80 ? '#f9ab00' : '#1a73e8')) . ';width:' . $pct . '%;height:8px;border-radius:6px"></div></div>' : ''));
    if ($d['trash'] && $d['trash_size'] > 50 * 1048576) $h .= $row('🗑️', 'V koši je ' . $d['trash'] . ' položiek (' . format_bytes($d['trash_size']) . ') – vysypaním uvoľníš miesto.');
    $h .= '</table><p style="font-size:12px;color:#777;margin-top:24px">Súhrn si môžeš vypnúť v aplikácii: Môj účet → Týždenný súhrn.</p>';
    return $h;
}

/** Pošle súhrny (raz týždenne, v pondelok; ak v pondelok nikto nebol online, hneď nasledujúci deň). */
function send_weekly_digests(bool $force = false): int {
    if (!$force && setting('digest_last_run', '') === '') { setting_set('digest_last_run', (string)(time() - 5 * 86400)); return 0; } // prvý súhrn až v pondelok
    if (!$force && date('N') !== '1' && (int)setting('digest_last_run', '0') > time() - 8 * 86400) return 0;
    if (!$force && (int)setting('digest_last_run', '0') > time() - 6 * 86400) return 0;
    setting_set('digest_last_run', (string)time());
    $n = 0;
    foreach (q('SELECT * FROM ' . P . 'users WHERE is_active = 1')->fetchAll() as $u) {
        if (!digest_enabled($u) || empty($u['email'])) continue;
        try {
            $d = digest_data($u);
            if (!$d) continue;
            if (send_mail($u['email'], 'Týždenný prehľad – ' . cfg('app_name'), 'Tvoj týždeň v ' . cfg('app_name'), digest_html($u, $d), 'Otvoriť disk', public_url() . '/index.php')) $n++;
        } catch (Throwable $e) {}
    }
    return $n;
}
