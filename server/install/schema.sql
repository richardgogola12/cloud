-- Cloud – databázová schéma (MySQL 5.7+/MariaDB 10.3+), všetky tabuľky s prefixom cloud_
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cloud_users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password      VARCHAR(255) NOT NULL,
  role          ENUM('user','admin') NOT NULL DEFAULT 'user',
  quota         BIGINT UNSIGNED NOT NULL DEFAULT 5368709120,  -- 0 = bez obmedzenia
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  quota_warned  TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login    DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cloud_folders (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  parent_id   INT UNSIGNED NULL,
  name        VARCHAR(255) NOT NULL,
  starred     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (user_id, parent_id),
  CONSTRAINT fk_cloud_folders_user   FOREIGN KEY (user_id)   REFERENCES cloud_users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_cloud_folders_parent FOREIGN KEY (parent_id) REFERENCES cloud_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cloud_files (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  folder_id    INT UNSIGNED NULL,
  name         VARCHAR(255) NOT NULL,
  stored_name  VARCHAR(64)  NOT NULL,
  size         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  mime         VARCHAR(150) NOT NULL DEFAULT 'application/octet-stream',
  starred      TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (user_id, folder_id),
  INDEX (user_id, name),
  CONSTRAINT fk_cloud_files_user   FOREIGN KEY (user_id)   REFERENCES cloud_users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_cloud_files_folder FOREIGN KEY (folder_id) REFERENCES cloud_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cloud_password_resets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL UNIQUE,
  expires_at  DATETIME NOT NULL,
  used        TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cloud_resets_user FOREIGN KEY (user_id) REFERENCES cloud_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cloud_webauthn (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  credential_id  VARCHAR(512) NOT NULL,
  public_key     TEXT NOT NULL,
  sign_count     INT UNSIGNED NOT NULL DEFAULT 0,
  name           VARCHAR(100) NOT NULL DEFAULT 'Zariadenie',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used      DATETIME NULL,
  UNIQUE KEY uq_cloud_webauthn_cred (credential_id(255)),
  CONSTRAINT fk_cloud_webauthn_user FOREIGN KEY (user_id) REFERENCES cloud_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cloud_settings (
  k  VARCHAR(64) PRIMARY KEY,
  v  TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cloud_login_attempts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip          VARCHAR(45) NOT NULL,
  email       VARCHAR(190) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cloud_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(50) NOT NULL,
  detail      VARCHAR(500) NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (created_at),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cloud_settings (k, v) VALUES
  ('registration', 'open'),          -- open | approval | closed
  ('default_quota', '5368709120'),
  ('max_upload_mb', '512'),
  ('notify_admin_register', '1');
