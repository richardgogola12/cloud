<?php
declare(strict_types=1);
/* ------------------------------------------------------------------
 *  Automatické migrácie databázy (spúšťajú sa samé pri prvej požiadavke)
 * ------------------------------------------------------------------ */
const SCHEMA_VERSION = 3;

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
    $lockFile = rtrim(cfg('storage_path'), '/') . '/migrate.lock';
    $fp = @fopen($lockFile, 'c');
    if ($fp && !flock($fp, LOCK_EX | LOCK_NB)) return; // migrácia už beží
    try {
        if ($v < 2) migrate_v2();
        if ($v < 3) migrate_v3();
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
