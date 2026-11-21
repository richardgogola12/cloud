<?php
declare(strict_types=1);
/* ------------------------------------------------------------------
 *  Limit hostingu (upozornenie pri 80 % a 95 %) a mesačný report pre
 *  administrátorov. Spúšťa sa najviac raz za 10 minút (admin_tick).
 * ------------------------------------------------------------------ */

/** Koľko miesta Cloud zaberá na hostingu: súbory + verzie + databáza. */
function hosting_usage(): array {
    $files = (int)q('SELECT COALESCE(SUM(size),0) FROM ' . P . 'files')->fetchColumn();
    $vers = (int)q('SELECT COALESCE(SUM(size),0) FROM ' . P . 'file_versions')->fetchColumn();
    $dbs = 0;
    try { $dbs = (int)q('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE ?', [str_replace('_', '\\_', P) . '%'])->fetchColumn(); } catch (Throwable $e) {}
    $limit = (int)round((float)setting('hosting_limit_gb', '0') * 1073741824);
    $used = $files + $vers + $dbs;
    return ['files' => $files, 'versions' => $vers, 'db' => $dbs, 'used' => $used, 'limit' => $limit, 'pct' => $limit > 0 ? $used / $limit : null];
}
function hosting_check(): void {
    $h = hosting_usage();
    if ($h['pct'] === null) { setting_set('hosting_warned', '0'); return; }
    $level = $h['pct'] >= 0.95 ? 95 : ($h['pct'] >= 0.8 ? 80 : 0);
    $warned = (int)setting('hosting_warned', '0');
    if ($level > $warned) {
        setting_set('hosting_warned', (string)$level);
        $msg = 'Cloud zaberá ' . format_bytes($h['used']) . ' z ' . format_bytes($h['limit']) . ' (' . round($h['pct'] * 100) . ' %) – súbory ' . format_bytes($h['files'])
            . ', verzie ' . format_bytes($h['versions']) . ', databáza ' . format_bytes($h['db']) . '.';
        notify_admins('quota', $level >= 95 ? 'Hosting je takmer plný' : 'Na hostingu dochádza miesto', $msg, 'admin.php');
        foreach (q("SELECT email FROM " . P . "users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $em) {
            send_mail($em, ($level >= 95 ? 'Hosting je takmer plný' : 'Na hostingu dochádza miesto') . ' – ' . cfg('app_name'), 'Miesto na hostingu: ' . round($h['pct'] * 100) . ' %',
                '<p>' . e($msg) . '</p><p>Keď sa limit hostingu minie, nahrávanie prestane fungovať. Uvoľni miesto (kôš, staré verzie, duplicity), skráť dobu uchovania verzií alebo zvýš balík hostingu.</p>',
                'Otvoriť administráciu', public_url() . '/admin.php');
        }
    } elseif ($level < $warned && $h['pct'] < ($warned / 100) - 0.05) {
        setting_set('hosting_warned', (string)$level); // klesol pod hranicu – pri ďalšom raste znova upozorní
    }
}

/** Neúspešné prihlásenia za mesiac (počítadlo, lebo záznamy pokusov sa po dni mažú). */
function stat_failed_login(): void {
    try { $k = 'stat_failed_' . date('Y-m'); setting_set($k, (string)((int)setting($k, '0') + 1)); } catch (Throwable $e) {}
}

/** Mesačný report – údaje za predchádzajúci mesiac. */
function admin_report_data(?string $month = null): array {
    $month ??= date('Y-m', strtotime('first day of last month'));
    $from = $month . '-01 00:00:00'; $to = date('Y-m-d 00:00:00', strtotime($from . ' +1 month'));
    $U = P . 'users'; $F = P . 'files';
    $first = q('SELECT bytes FROM ' . P . 'usage_daily WHERE day >= ? ORDER BY day LIMIT 1', [substr($from, 0, 10)])->fetchColumn();
    $last = q('SELECT bytes FROM ' . P . 'usage_daily WHERE day < ? ORDER BY day DESC LIMIT 1', [substr($to, 0, 10)])->fetchColumn();
    return [
        'month' => $month,
        'hosting' => hosting_usage(),
        'growth' => ($first !== false && $last !== false) ? (int)$last - (int)$first : null,
        'users' => (int)q("SELECT COUNT(*) FROM $U")->fetchColumn(),
        'new_users' => (int)q("SELECT COUNT(*) FROM $U WHERE created_at >= ? AND created_at < ?", [$from, $to])->fetchColumn(),
        'active_users' => (int)q("SELECT COUNT(*) FROM $U WHERE last_login >= ? AND last_login < ?", [$from, $to])->fetchColumn(),
        'uploads' => (int)q("SELECT COUNT(*) FROM $F WHERE created_at >= ? AND created_at < ?", [$from, $to])->fetchColumn(),
        'top' => q("SELECT u.name, u.email, COALESCE(SUM(f.size),0) b FROM $U u LEFT JOIN $F f ON f.user_id = u.id GROUP BY u.id ORDER BY b DESC LIMIT 5")->fetchAll(),
        'failed_logins' => (int)setting('stat_failed_' . $month, '0'),
        'new_devices' => (int)q('SELECT COUNT(*) FROM ' . P . "notifications WHERE type = 'device' AND created_at >= ? AND created_at < ?", [$from, $to])->fetchColumn(),
        'problems' => q('SELECT title, COUNT(*) c FROM ' . P . "notifications WHERE type IN ('integrity','disk','quota') AND created_at >= ? AND created_at < ? GROUP BY title ORDER BY c DESC LIMIT 8", [$from, $to])->fetchAll(),
        'backup' => function_exists('bk_status') ? bk_status() + ['enabled' => setting('backup_enabled', '0') === '1'] : [],
        'tfa_admins' => (int)q("SELECT COUNT(*) FROM $U WHERE role = 'admin' AND totp_secret IS NOT NULL")->fetchColumn(),
        'admins' => (int)q("SELECT COUNT(*) FROM $U WHERE role = 'admin'")->fetchColumn(),
    ];
}
function admin_report_html(array $d): string {
    $months = ['01' => 'január', '02' => 'február', '03' => 'marec', '04' => 'apríl', '05' => 'máj', '06' => 'jún', '07' => 'júl', '08' => 'august', '09' => 'september', '10' => 'október', '11' => 'november', '12' => 'december'];
    $h = $d['hosting'];
    $row = fn($k, $v) => '<tr><td style="padding:4px 12px 4px 0;color:#5f6368">' . $k . '</td><td style="padding:4px 0"><b>' . $v . '</b></td></tr>';
    $html = '<p>Prehľad servera za <b>' . $months[substr($d['month'], 5)] . ' ' . substr($d['month'], 0, 4) . '</b>.</p><table style="border-collapse:collapse;font-size:14px">';
    $html .= $row('Obsadené miesto', format_bytes($h['used']) . ($h['limit'] ? ' z ' . format_bytes($h['limit']) . ' (' . round($h['pct'] * 100) . ' %)' : ''));
    $html .= $row('Z toho súbory / verzie / databáza', format_bytes($h['files']) . ' / ' . format_bytes($h['versions']) . ' / ' . format_bytes($h['db']));
    if ($d['growth'] !== null) $html .= $row('Nárast za mesiac', ($d['growth'] >= 0 ? '+' : '−') . format_bytes(abs($d['growth'])));
    $html .= $row('Používatelia', $d['users'] . ' (nových ' . $d['new_users'] . ', prihlásených ' . $d['active_users'] . ')');
    $html .= $row('Nahraté súbory', (string)$d['uploads']);
    $html .= $row('Neúspešné prihlásenia', (string)$d['failed_logins']);
    $html .= $row('Prihlásenia z nových zariadení', (string)$d['new_devices']);
    $html .= $row('Administrátori s dvojstupňovým overením', $d['tfa_admins'] . ' z ' . $d['admins']);
    $b = $d['backup'];
    $html .= $row('Záloha', empty($b['enabled']) ? '<span style="color:#d93025">vypnutá</span>' : (!empty($b['error']) ? '<span style="color:#d93025">chyba: ' . e((string)$b['error']) . '</span>' : 'posledná ' . (!empty($b['last_ok']) ? date('j. n. Y G:i', strtotime($b['last_ok'])) : '–')));
    $html .= '</table>';
    if ($d['top']) {
        $html .= '<p style="margin-top:16px"><b>Najviac miesta</b></p><ol style="margin:0;padding-left:20px">';
        foreach ($d['top'] as $t) $html .= '<li>' . e($t['name']) . ' – ' . format_bytes((int)$t['b']) . '</li>';
        $html .= '</ol>';
    }
    if ($d['problems']) {
        $html .= '<p style="margin-top:16px"><b>Upozornenia za mesiac</b></p><ul style="margin:0;padding-left:20px">';
        foreach ($d['problems'] as $p) $html .= '<li>' . e($p['title']) . ($p['c'] > 1 ? ' (' . (int)$p['c'] . '×)' : '') . '</li>';
        $html .= '</ul>';
    } else $html .= '<p style="margin-top:16px;color:#188038">Za mesiac žiadne problémy. ✔</p>';
    return $html;
}
function send_admin_report(bool $force = false): int {
    if (!$force && setting('admin_report', '1') !== '1') return 0;
    $d = admin_report_data();
    $html = admin_report_html($d); $n = 0;
    foreach (q("SELECT email FROM " . P . "users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $em) {
        if (send_mail($em, 'Mesačný report – ' . cfg('app_name'), 'Mesačný report servera', $html, 'Otvoriť administráciu', public_url() . '/admin.php')) $n++;
    }
    return $n;
}
/** Volá sa najviac raz za 10 minút. */
function admin_tick(): void {
    if ((int)setting('hosting_checked', '0') < time() - 3600) { setting_set('hosting_checked', (string)time()); hosting_check(); }
    if (setting('admin_report', '1') === '1' && setting('admin_report_month', '') !== date('Y-m') && (int)date('G') >= 7) {
        setting_set('admin_report_month', date('Y-m'));
        if (setting('admin_report_since', '') === '') { setting_set('admin_report_since', date('Y-m')); return; } // prvý report až o mesiac (po inštalácii)
        send_admin_report();
    }
}
