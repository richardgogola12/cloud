<?php
declare(strict_types=1);
/* ------------------------------------------------------------------
 *  Automatické migrácie databázy (spúšťajú sa samé pri prvej požiadavke)
 * ------------------------------------------------------------------ */
const SCHEMA_VERSION = 18;

function col_exists(string $table, string $col): bool {
    return (bool)q('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $col])->fetchColumn();
}
function idx_exists(string $table, string $idx): bool {
    return (bool)q('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$table, $idx])->fetchColumn();
}
function add_col(string $table, string $col, string $def): void {
    if (!col_exists($table, $col)) db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
}

function migrate(): void {
    $v = (int)setting('schema_version', '1');
    $lockFile = rtrim(cfg('storage_path'), '/\\') . '/migrate.lock';
    $fp = @fopen($lockFile, 'c');
    if ($fp && !flock($fp, LOCK_EX | LOCK_NB)) return; // migrácia už beží
    try {
        if ($v < 2) migrate_v2();
        if ($v < 3) migrate_v3();
        if ($v < 4) migrate_v4();
        if ($v < 5) migrate_v5();
        if ($v < 6) migrate_v6();
        if ($v < 7) migrate_v7();
        if ($v < 8) migrate_v8();
        if ($v < 9) migrate_v9();
        if ($v < 10) migrate_v10();
        if ($v < 11) migrate_v11();
        if ($v < 12) migrate_v12();
        if ($v < 13) migrate_v13();
        if ($v < 14) migrate_v14();
        if ($v < 15) migrate_v15();
        if ($v < 16) migrate_v16();
        if ($v < 17) migrate_v17();
        if ($v < 18) migrate_v18();
        setting_set('schema_version', (string)SCHEMA_VERSION);
    } finally {
        if ($fp) { flock($fp, LOCK_UN); fclose($fp); }
    }
}

function migrate_v2(): void {
    $p = P;
    // kôš, dátum fotky
    add_col("{$p}files", 'deleted_at', 'DATETIME NULL');
    add_col("{$p}files", 'trash_root', 'TINYINT(1) NOT NULL DEFAULT 0');
    add_col("{$p}files", 'taken_at', 'DATETIME NULL');
    add_col("{$p}folders", 'deleted_at', 'DATETIME NULL');
    add_col("{$p}folders", 'trash_root', 'TINYINT(1) NOT NULL DEFAULT 0');
    if (!idx_exists("{$p}files", 'idx_cloud_files_deleted')) db()->exec("CREATE INDEX idx_cloud_files_deleted ON {$p}files (user_id, deleted_at)");
    if (!idx_exists("{$p}folders", 'idx_cloud_folders_deleted')) db()->exec("CREATE INDEX idx_cloud_folders_deleted ON {$p}folders (user_id, deleted_at)");
    // overenie e-mailu
    add_col("{$p}users", 'email_verified', 'TINYINT(1) NOT NULL DEFAULT 1');
    add_col("{$p}users", 'pending_email', 'VARCHAR(190) NULL');

    $tables = [
        "CREATE TABLE IF NOT EXISTS {$p}file_versions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            file_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            stored_name VARCHAR(64) NOT NULL,
            size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mime VARCHAR(150) NOT NULL DEFAULT 'application/octet-stream',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (file_id),
            CONSTRAINT fk_cloud_versions_file FOREIGN KEY (file_id) REFERENCES {$p}files(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS {$p}shares (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            item_type ENUM('f','d') NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            token CHAR(32) NOT NULL UNIQUE,
            password VARCHAR(255) NULL,
            expires_at DATETIME NULL,
            downloads INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (item_type, item_id),
            CONSTRAINT fk_cloud_shares_user FOREIGN KEY (user_id) REFERENCES {$p}users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS {$p}user_shares (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_id INT UNSIGNED NOT NULL,
            item_type ENUM('f','d') NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            target_id INT UNSIGNED NOT NULL,
            permission ENUM('view','edit') NOT NULL DEFAULT 'view',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cloud_ushare (item_type, item_id, target_id),
            INDEX (target_id),
            CONSTRAINT fk_cloud_ushare_owner  FOREIGN KEY (owner_id)  REFERENCES {$p}users(id) ON DELETE CASCADE,
            CONSTRAINT fk_cloud_ushare_target FOREIGN KEY (target_id) REFERENCES {$p}users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS {$p}sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            skey CHAR(64) NOT NULL,
            ua VARCHAR(255) NULL,
            ip VARCHAR(45) NULL,
            method VARCHAR(30) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            INDEX (user_id),
            CONSTRAINT fk_cloud_sessions_user FOREIGN KEY (user_id) REFERENCES {$p}users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS {$p}devices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            device_hash CHAR(64) NOT NULL,
            ua VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cloud_device (user_id, device_hash),
            CONSTRAINT fk_cloud_devices_user FOREIGN KEY (user_id) REFERENCES {$p}users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS {$p}tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(20) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            data VARCHAR(190) NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_cloud_tokens_user FOREIGN KEY (user_id) REFERENCES {$p}users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS {$p}uploads (
            id CHAR(32) PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            owner_id INT UNSIGNED NOT NULL,
            folder_id INT UNSIGNED NULL,
            relpath VARCHAR(1000) NULL,
            name VARCHAR(255) NOT NULL,
            size BIGINT UNSIGNED NOT NULL,
            received BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_cloud_uploads_user FOREIGN KEY (user_id) REFERENCES {$p}users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS {$p}usage_daily (
            day DATE PRIMARY KEY,
            bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            files INT UNSIGNED NOT NULL DEFAULT 0,
            users INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($tables as $sql) db()->exec($sql);

    foreach (['require_email_verify' => '1', 'trash_days' => '30', 'max_versions' => '10', 'notify_new_device' => '1'] as $k => $v) {
        if (setting($k) === null) setting_set($k, $v);
    }
}

/* v3: prístupové tokeny pre mobilnú aplikáciu */
function migrate_v3(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS " . P . "app_tokens (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL DEFAULT 'Android',
        ip VARCHAR(45) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used DATETIME NULL,
        revoked TINYINT(1) NOT NULL DEFAULT 0,
        INDEX (user_id),
        CONSTRAINT fk_cloud_app_tokens_user FOREIGN KEY (user_id) REFERENCES " . P . "users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Doplní do existujúceho .htaccess odovzdávanie hlavičky Authorization (aktualizácia .htaccess neprepisuje). */
function htaccess_dav_patch(): void {
    $f = APP_ROOT . '/.htaccess';
    if (!is_file($f) || !is_writable($f)) return;
    $cur = (string)@file_get_contents($f);
    if (str_contains($cur, 'CLOUD-DAV')) return;
    $block = "\n# >>> CLOUD-DAV (sieťový disk WebDAV – PHP musí dostať hlavičku Authorization aj pri PHP-FPM)\n"
        . "<IfModule mod_setenvif.c>\n    SetEnvIfNoCase Authorization \"(.+)\" HTTP_AUTHORIZATION=\$1\n</IfModule>\n# <<< CLOUD-DAV\n";
    @file_put_contents($f, rtrim($cur, "\n") . "\n" . $block);
}

/* v4: šifrovanie súborov, kontrolné súčty, upozornenia */
function migrate_v4(): void {
    $p = P;
    add_col("{$p}files", 'enc_key', 'VARCHAR(120) NULL');
    add_col("{$p}files", 'sha256', 'CHAR(64) NULL');
    add_col("{$p}file_versions", 'enc_key', 'VARCHAR(120) NULL');
    add_col("{$p}file_versions", 'sha256', 'CHAR(64) NULL');
    db()->exec("CREATE TABLE IF NOT EXISTS {$p}notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        type VARCHAR(30) NOT NULL,
        title VARCHAR(200) NOT NULL,
        body VARCHAR(500) NULL,
        link VARCHAR(255) NULL,
        read_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id, id),
        CONSTRAINT fk_cloud_notif_user FOREIGN KEY (user_id) REFERENCES {$p}users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (setting('encrypt_files') === null) setting_set('encrypt_files', '1');
}

/* v5: indexy pre tisícky súborov (Nedávne, Fotky, kôš, údržba) */
function migrate_v5(): void {
    $p = P;
    $idx = [
        ["{$p}files", 'idx_cloud_files_updated', '(user_id, updated_at)'],
        ["{$p}files", 'idx_cloud_files_mime', '(user_id, mime(20))'],
        ["{$p}files", 'idx_cloud_files_trash', '(user_id, trash_root, deleted_at)'],
        ["{$p}folders", 'idx_cloud_folders_trash', '(user_id, trash_root, deleted_at)'],
        ["{$p}uploads", 'idx_cloud_uploads_updated', '(updated_at)'],
        ["{$p}notifications", 'idx_cloud_notif_unread', '(user_id, read_at)'],
    ];
    foreach ($idx as [$t, $name, $cols]) if (!idx_exists($t, $name)) db()->exec("CREATE INDEX $name ON $t $cols");
}

/* v6: štítky (zvlášť pre zložky a súbory) a farby položiek */
function migrate_v6(): void {
    $p = P;
    add_col("{$p}files", 'color', 'VARCHAR(7) NULL');
    add_col("{$p}folders", 'color', 'VARCHAR(7) NULL');
    db()->exec("CREATE TABLE IF NOT EXISTS {$p}tags (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        name VARCHAR(50) NOT NULL,
        color VARCHAR(7) NOT NULL DEFAULT '#1a73e8',
        scope ENUM('d','f') NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cloud_tag (user_id, scope, name),
        CONSTRAINT fk_cloud_tags_user FOREIGN KEY (user_id) REFERENCES {$p}users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS {$p}item_tags (
        tag_id INT UNSIGNED NOT NULL,
        item_type ENUM('d','f') NOT NULL,
        item_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (tag_id, item_type, item_id),
        INDEX idx_cloud_item_tags_item (item_type, item_id),
        CONSTRAINT fk_cloud_item_tags_tag FOREIGN KEY (tag_id) REFERENCES {$p}tags(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function migrate_v7(): void {
    // profilová fotka (zašifrovaná, kľúč zabalený hlavným kľúčom)
    add_col(P . 'users', 'avatar_key', 'TEXT NULL');
    add_col(P . 'users', 'avatar_v', 'INT NOT NULL DEFAULT 0');
}

function migrate_v8(): void {
    // verejný odkaz: view = zobraziť/stiahnuť, upload = iba nahrať, both = oboje
    add_col(P . 'shares', 'mode', "VARCHAR(10) NOT NULL DEFAULT 'view'");
    add_col(P . 'shares', 'uploads', 'INT UNSIGNED NOT NULL DEFAULT 0');
}

function migrate_v9(): void {
    // heslá pre sieťový disk (WebDAV) – samostatné pre každé zariadenie, dajú sa zrušiť
    $p = P;
    db()->exec("CREATE TABLE IF NOT EXISTS {$p}dav_passwords (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used DATETIME NULL,
        INDEX (user_id),
        CONSTRAINT fk_cloud_dav_user FOREIGN KEY (user_id) REFERENCES {$p}users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    htaccess_dav_patch();
}

/* v10: indexy pre rýchlosť (Fotky, duplicity, hľadanie podľa názvu, zdieľané mnou, sieťový disk) */
function migrate_v10(): void {
    $p = P;
    $idx = [
        ["{$p}files", 'idx_cloud_files_sha', '(user_id, sha256(16))'],
        ["{$p}files", 'idx_cloud_files_taken', '(user_id, deleted_at, taken_at)'],
        ["{$p}files", 'idx_cloud_files_fname', '(user_id, folder_id, name(64))'],
        ["{$p}folders", 'idx_cloud_folders_name', '(user_id, parent_id, name(64))'],
        ["{$p}user_shares", 'idx_cloud_ushares_owner', '(owner_id)'],
        ["{$p}shares", 'idx_cloud_shares_user', '(user_id)'],
    ];
    foreach ($idx as [$t, $name, $cols]) {
        try { if (!idx_exists($t, $name)) db()->exec("CREATE INDEX $name ON $t $cols"); } catch (Throwable $e) { error_log('[cloud] index ' . $name . ': ' . $e->getMessage()); }
    }
    htaccess_perf_patch();
}

/** Kompresia a dlhá cache pre súbory stránky (CSS, JS, písma, ikony) – doplní sa do existujúceho .htaccess. */
function htaccess_perf_patch(): void {
    $f = APP_ROOT . '/.htaccess';
    if (!is_file($f) || !is_writable($f)) return;
    $cur = (string)@file_get_contents($f);
    if (str_contains($cur, 'CLOUD-PERF')) return;
    @file_put_contents($f, rtrim($cur, "\n") . "\n" . HTACCESS_PERF);
}
const HTACCESS_PERF = <<<'HT'

# >>> CLOUD-PERF (rýchlosť: kompresia a cache súborov stránky)
<IfModule mod_deflate.c>
<IfModule mod_filter.c>
    AddOutputFilterByType DEFLATE text/html text/css text/plain application/javascript text/javascript application/json image/svg+xml application/manifest+json
</IfModule>
</IfModule>
<IfModule mod_headers.c>
    <FilesMatch "\.(css|js)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
    <FilesMatch "\.(woff2?|png|svg|ico|webp|jpg)$">
        Header set Cache-Control "public, max-age=2592000"
    </FilesMatch>
    <Files "sw.js">
        Header set Cache-Control "no-cache"
    </Files>
</IfModule>
# <<< CLOUD-PERF

HT;

/* v11: osobné nastavenia zobrazenia (stĺpce v zozname…) */
function migrate_v11(): void {
    add_col(P . 'users', 'prefs', 'TEXT NULL');
}

/* v12: voliteľný index obsahu dokumentov pre vyhľadávanie (fulltext) */
function migrate_v12(): void {
    $p = P;
    db()->exec("CREATE TABLE IF NOT EXISTS {$p}file_text (
        file_id INT UNSIGNED NOT NULL PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        body MEDIUMTEXT NOT NULL,
        indexed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        FULLTEXT KEY ft_body (body),
        CONSTRAINT fk_{$p}ft_file FOREIGN KEY (file_id) REFERENCES {$p}files(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* v13: webový terminál bol odstránený – zmaže jeho súbory, ktoré po aktualizácii ostali na serveri */
function migrate_v13(): void {
    foreach (['term.php', 'inc/term_helper.py', 'assets/js/admin-term.js', 'assets/vendor/xterm/xterm.js', 'assets/vendor/xterm/xterm.css', 'assets/vendor/xterm/addon-fit.js'] as $f) @unlink(APP_ROOT . '/' . $f);
    @rmdir(APP_ROOT . '/assets/vendor/xterm');
    $rm = function (string $d) use (&$rm) {
        foreach (array_diff((array)@scandir($d), ['.', '..', false]) as $f) { $p = "$d/$f"; is_dir($p) && !is_link($p) ? $rm($p) : @unlink($p); }
        @rmdir($d);
    };
    if (is_dir(data_path('term'))) { foreach (glob(data_path('term') . '/*/') ?: [] as $d) @touch($d . 'close'); $rm(data_path('term')); }
    try { q('DELETE FROM ' . P . "settings WHERE k IN ('term_last')"); } catch (Throwable $e) {}
}

/* v14: zložky zamknuté heslom */
function migrate_v14(): void {
    $p = P;
    $has = db()->query("SHOW COLUMNS FROM {$p}folders LIKE 'lock_hash'")->fetch();
    if (!$has) db()->exec("ALTER TABLE {$p}folders ADD lock_hash VARCHAR(255) NULL DEFAULT NULL");
}

/* v15: kedy pýtať heslo zamknutej zložky znova */
function migrate_v15(): void {
    $p = P;
    $has = db()->query("SHOW COLUMNS FROM {$p}folders LIKE 'lock_mode'")->fetch();
    if (!$has) db()->exec("ALTER TABLE {$p}folders ADD lock_mode VARCHAR(10) NOT NULL DEFAULT 'close'");
}

/* v16: aktivita (história zmien), albumy fotiek, poloha fotiek */
function migrate_v16(): void {
    $p = P;
    db()->exec("CREATE TABLE IF NOT EXISTS {$p}activity (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner_id INT UNSIGNED NOT NULL,
        actor_id INT UNSIGNED NULL,
        action VARCHAR(24) NOT NULL,
        item_type CHAR(1) NOT NULL,
        item_id INT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        folder_id INT UNSIGNED NULL,
        extra TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_act_owner (owner_id, created_at),
        INDEX idx_act_item (item_type, item_id),
        INDEX idx_act_folder (folder_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS {$p}albums (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        cover_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS {$p}album_items (
        album_id INT UNSIGNED NOT NULL,
        file_id INT UNSIGNED NOT NULL,
        added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (album_id, file_id),
        INDEX (file_id),
        CONSTRAINT fk_{$p}ai_album FOREIGN KEY (album_id) REFERENCES {$p}albums(id) ON DELETE CASCADE,
        CONSTRAINT fk_{$p}ai_file FOREIGN KEY (file_id) REFERENCES {$p}files(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    add_col("{$p}files", 'lat', 'DECIMAL(9,6) NULL');
    add_col("{$p}files", 'lng', 'DECIMAL(9,6) NULL');
    add_col("{$p}files", 'meta_done', 'TINYINT NOT NULL DEFAULT 0');
    if (!idx_exists("{$p}files", 'idx_cloud_files_geo')) db()->exec("ALTER TABLE {$p}files ADD INDEX idx_cloud_files_geo (user_id, lat)");
}

/* v17: mapa fotiek zrušená – zmaže uloženú polohu fotiek a knižnicu mapy (aktualizácia súbory sama nemaže) */
function migrate_v17(): void {
    try { q('UPDATE ' . P . 'files SET lat = NULL, lng = NULL WHERE lat IS NOT NULL OR lng IS NOT NULL'); } catch (Throwable $e) {}
    foreach (['leaflet.js', 'leaflet.css', 'leaflet.markercluster.js', 'MarkerCluster.css'] as $f) @unlink(APP_ROOT . '/assets/vendor/leaflet/' . $f);
    foreach (glob(APP_ROOT . '/assets/vendor/leaflet/images/*') ?: [] as $f) @unlink($f);
    @rmdir(APP_ROOT . '/assets/vendor/leaflet/images');
    @rmdir(APP_ROOT . '/assets/vendor/leaflet');
}

/** Ochrana úložiska pre IIS (Windows) – rovnaký význam ako storage/.htaccess pre Apache. */
function storage_web_config(): void {
    $f = rtrim((string)cfg('storage_path'), '/\\') . '/web.config';
    if (is_file($f) || !is_dir(dirname($f))) return;
    @file_put_contents($f, '<?xml version="1.0" encoding="UTF-8"?>' . "\n<configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted=\"false\" />"
        . '<hiddenSegments><add segment="files" /><add segment="thumbs" /><add segment="tmp" /><add segment="sessions" /><add segment="backups" /></hiddenSegments>'
        . "</requestFiltering></security></system.webServer></configuration>\n");
}

/* v18: beh aj na Windows (IIS) – ochrana úložiska aj cez web.config */
function migrate_v18(): void {
    storage_web_config();
    $sp = rtrim((string)cfg('storage_path'), '/\\');
    if (is_dir($sp) && !is_file($sp . '/.htaccess')) @file_put_contents($sp . '/.htaccess', "Require all denied\nDeny from all\n");
}
