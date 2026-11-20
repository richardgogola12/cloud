<?php
/* E-mailový súhrn: čo pribudlo, čo s tebou zdieľali, koľko miesta ostáva, spomienky.
   Každý používateľ si nastaví, ako často (denne / týždenne / každé 2 týždne / mesačne), v ktorý deň a o koľkej. */

const DIGEST_FREQS = ['off' => 'Vypnutý', 'daily' => 'Každý deň', 'weekly' => 'Raz týždenne', 'biweekly' => 'Každé 2 týždne', 'monthly' => 'Raz mesačne'];
const DIGEST_DAYS = [1 => 'pondelok', 2 => 'utorok', 3 => 'streda', 4 => 'štvrtok', 5 => 'piatok', 6 => 'sobota', 7 => 'nedeľa'];
const DIGEST_PERIOD_DAYS = ['daily' => 1, 'weekly' => 7, 'biweekly' => 14, 'monthly' => 30];

/** Nastavenie súhrnu používateľa (staré true/false = týždenne v pondelok o 8:00 / vypnuté). */
function digest_cfg(array $u): array {
    $v = user_prefs($u)['digest'] ?? true;
    $c = ['freq' => 'weekly', 'dow' => 1, 'dom' => 1, 'hour' => 8];
    if ($v === false) $c['freq'] = 'off';
    elseif (is_array($v)) {
        if (isset(DIGEST_FREQS[$v['freq'] ?? ''])) $c['freq'] = $v['freq'];
        $c['dow'] = max(1, min(7, (int)($v['dow'] ?? 1)));
        $c['dom'] = max(1, min(28, (int)($v['dom'] ?? 1)));
        $c['hour'] = max(0, min(23, (int)($v['hour'] ?? 8)));
    }
    return $c;
}
function digest_enabled(array $u): bool { return digest_cfg($u)['freq'] !== 'off'; }

/** Posledný plánovaný čas odoslania, ktorý už nastal (unix čas), podľa nastavenia. */
function digest_slot(array $c, int $now): ?int {
    if ($c['freq'] === 'off') return null;
    $h = sprintf('%02d:00:00', $c['hour']);
    if ($c['freq'] === 'daily') {
        $t = strtotime(date('Y-m-d', $now) . ' ' . $h);
        return $t > $now ? strtotime('-1 day', $t) : $t;
    }
    if ($c['freq'] === 'monthly') {
        $t = strtotime(date('Y-m-', $now) . sprintf('%02d', $c['dom']) . ' ' . $h);
        return $t > $now ? strtotime('-1 month', $t) : $t;
    }
    $t = strtotime(date('Y-m-d', $now) . ' ' . $h) - ((int)date('N', $now) - $c['dow']) * 86400; // tento týždeň v zvolený deň
    $t = strtotime(date('Y-m-d', $t) . ' ' . $h);
    if ($t > $now) $t = strtotime('-7 days', $t);
    if ($c['freq'] === 'biweekly' && ((int)floor(($t - strtotime('2024-01-01 00:00:00')) / 604800)) % 2 !== 0) $t = strtotime('-7 days', $t); // párne týždne
    return $t;
}
/** Kedy príde najbližší súhrn (unix čas). */
function digest_next(array $c, ?int $now = null): ?int {
    $now ??= time();
    if ($c['freq'] === 'off') return null;
    $step = ['daily' => '+1 day', 'weekly' => '+7 days', 'biweekly' => '+14 days', 'monthly' => '+1 month'][$c['freq']];
    $t = digest_slot($c, $now);
    for ($i = 0; $i < 3 && $t <= $now; $i++) {
        $n = strtotime($step, $t);
        if ($c['freq'] === 'monthly') $n = strtotime(date('Y-m-', $n) . sprintf('%02d', $c['dom']) . ' ' . sprintf('%02d:00:00', $c['hour']));
        $t = $n;
    }
    return $t;
}
function digest_when_text(array $c): string {
    $h = $c['hour'] . ':00';
    return match ($c['freq']) {
        'off' => 'vypnutý',
        'daily' => 'každý deň o ' . $h,
        'weekly' => 'každý ' . DIGEST_DAYS[$c['dow']] . ' o ' . $h,
        'biweekly' => 'každý druhý ' . DIGEST_DAYS[$c['dow']] . ' o ' . $h,
        'monthly' => 'každý mesiac ' . $c['dom'] . '. dňa o ' . $h,
    };
}

/** Údaje súhrnu za posledných $days dní (null = nie je čo poslať). */
function digest_data(array $u, int $days = 7): ?array {
    $uid = (int)$u['id'];
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
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
    return ['days' => $days, 'uploaded' => (int)$up['n'], 'uploaded_size' => (int)$up['s'], 'by_others' => $byOthers, 'public' => $public, 'shared' => $sharedItems,
            'trash' => (int)$trash['n'], 'trash_size' => (int)$trash['s'], 'used' => $used, 'quota' => $quota, 'memories' => $mem];
}

function digest_html(array $u, array $d): string {
    $row = fn(string $icon, string $html) => '<tr><td style="padding:8px 10px 8px 0;font-size:20px;vertical-align:top">' . $icon . '</td><td style="padding:8px 0;color:#333">' . $html . '</td></tr>';
    $days = (int)($d['days'] ?? 7);
    $per = match (true) { $days <= 1 => 'za posledný deň', $days <= 7 => 'za posledný týždeň', $days <= 14 => 'za posledné 2 týždne', default => 'za posledný mesiac' };
    $h = '<p>Ahoj ' . e($u['name']) . ', tu je prehľad tvojho disku ' . $per . '.</p><table style="width:100%;border-collapse:collapse">';
    if ($d['uploaded']) $h .= $row('📤', '<b>' . $d['uploaded'] . '</b> ' . ($d['uploaded'] === 1 ? 'nový súbor' : ($d['uploaded'] < 5 ? 'nové súbory' : 'nových súborov')) . ' (' . format_bytes($d['uploaded_size']) . ')');
    foreach ($d['by_others'] as $o) $h .= $row('👥', '<b>' . e($o['name']) . '</b> pridal(a) alebo upravil(a) ' . (int)$o['n'] . '× súbory v tvojich zložkách');
    if ($d['public']) $h .= $row('🔗', 'Cez verejný odkaz ti niekto nahral <b>' . $d['public'] . '</b> ' . ($d['public'] === 1 ? 'súbor' : 'súborov'));
    if ($d['shared']) {
        $list = implode('', array_map(fn($s) => '<li>' . ($s['type'] === 'd' ? '📁 ' : '📄 ') . '<b>' . e($s['name']) . '</b> – ' . e($s['owner']) . ($s['perm'] === 'edit' ? ' (môžeš upravovať)' : '') . '</li>', $d['shared']));
        $h .= $row('🤝', 'S tebou zdieľali:<ul style="margin:6px 0 0;padding-left:18px">' . $list . '</ul>');
    }
    if ($d['memories']) $h .= $row('🕰️', 'Teraz máš <b>' . $d['memories'] . '</b> ' . ($d['memories'] < 5 ? 'fotky' : 'fotiek') . ' z rovnakých dní v minulých rokoch – pozri sekciu Fotky.');
    $pct = $d['quota'] ? min(100, round($d['used'] / $d['quota'] * 100)) : null;
    $h .= $row('💾', 'Využívaš <b>' . format_bytes($d['used']) . '</b>' . ($pct !== null ? ' z ' . format_bytes($d['quota']) . ' (' . $pct . ' %)' : '')
        . ($pct !== null ? '<div style="background:#e8eaed;border-radius:6px;height:8px;margin-top:6px"><div style="background:' . ($pct > 90 ? '#d93025' : ($pct > 80 ? '#f9ab00' : '#1a73e8')) . ';width:' . $pct . '%;height:8px;border-radius:6px"></div></div>' : ''));
    if ($d['trash'] && $d['trash_size'] > 50 * 1048576) $h .= $row('🗑️', 'V koši je ' . $d['trash'] . ' položiek (' . format_bytes($d['trash_size']) . ') – vysypaním uvoľníš miesto.');
    $h .= '</table><p style="font-size:12px;color:#777;margin-top:24px">Kedy a ako často súhrn chodí (alebo ho vypnúť) nastavíš v aplikácii: Môj účet → E-mailový súhrn.</p>';
    return $h;
}

/** Pošle súhrny všetkým, ktorým podľa ich nastavenia nastal čas (spúšťa sa najviac raz za 10 minút pri návšteve webu). */
function send_due_digests(?int $now = null): int {
    $now ??= time();
    $n = 0;
    foreach (q('SELECT * FROM ' . P . 'users WHERE is_active = 1')->fetchAll() as $u) {
        $c = digest_cfg($u);
        if ($c['freq'] === 'off' || empty($u['email'])) continue;
        $prefs = user_prefs($u);
        $last = (int)($prefs['digest_last'] ?? 0);
        $slot = digest_slot($c, $now);
        if (!$last) { $prefs['digest_last'] = $now; q('UPDATE ' . P . 'users SET prefs = ? WHERE id = ?', [json_encode($prefs, JSON_UNESCAPED_UNICODE), $u['id']]); continue; } // prvý až v najbližší termín
        if ($slot === null || $last >= $slot) continue;
        $prefs['digest_last'] = $now; // zapíše sa aj keď nie je čo poslať – ďalší pokus až v ďalší termín
        q('UPDATE ' . P . 'users SET prefs = ? WHERE id = ?', [json_encode($prefs, JSON_UNESCAPED_UNICODE), $u['id']]);
        try {
            $d = digest_data($u, DIGEST_PERIOD_DAYS[$c['freq']]);
            if (!$d) continue;
            if (send_mail($u['email'], 'Prehľad – ' . cfg('app_name'), 'Tvoj prehľad v ' . cfg('app_name'), digest_html($u, $d), 'Otvoriť disk', public_url() . '/index.php')) $n++;
        } catch (Throwable $e) {}
    }
    return $n;
}
function send_weekly_digests(bool $force = false): int { return send_due_digests(); }
