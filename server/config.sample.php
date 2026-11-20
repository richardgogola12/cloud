<?php
/**
 * Cloud – konfigurácia (skopíruj ako config.php a uprav)
 * Uprav podľa svojho servera. SMTP nastavenia sa dajú meniť aj v admin rozhraní (uložia sa do DB a majú prednosť).
 */
return [
    // --- Aplikácia ---
    'app_name'   => 'Cloud',
    'app_url'    => 'https://cloud.example.sk',   // bez lomky na konci; použije sa v e-mailoch a pre WebAuthn
    'timezone'   => 'Europe/Bratislava',
    'update_repo' => 'richardgogola12/cloud',     // GitHub repozitár s aktualizáciami (servera aj Android aplikácie)

    // --- Databáza ---
    'db_host'    => 'localhost',
    'db_name'    => 'cloud',
    'db_user'    => 'cloud',
    'db_pass'    => 'zmen-ma',
    'db_prefix'  => 'cloud_',

    // --- Úložisko ---
    // Ideálne mimo webrootu, napr. '/var/www/cloud-data' alebo na Windows 'D:\\cloud-data'.
    // Predvolene ./storage (chránené .htaccess pre Apache a web.config pre IIS).
    'storage_path' => __DIR__ . '/storage',

    // --- Programy (nepovinné) ---
    // Cloud si ffmpeg a pdftotext nájde sám (Linux: /usr/bin…, Windows: PATH, C:\\ffmpeg\\bin, C:\\poppler\\Library\\bin…).
    // Ak sú inde, zadaj priečinky oddelené bodkočiarkou, napr. 'C:\\nastroje\\ffmpeg\\bin;C:\\nastroje\\poppler\\bin'.
    // 'tools_path' => '',

    // --- Session (izolácia od ostatných webov na serveri) ---
    'session_name' => 'CLOUD_SID_7f3a',            // unikátny názov cookie
    'session_lifetime' => 60 * 60 * 24 * 14,       // 14 dní (pri "Zapamätať si ma")

    // --- Predvolená kvóta pre nových používateľov (bajty) ---
    'default_quota' => 5 * 1024 * 1024 * 1024,     // 5 GB

    // --- SMTP (predvolené, dajú sa prepísať v admine) ---
    'smtp_host'   => 'smtp.example.sk',
    'smtp_port'   => 587,
    'smtp_secure' => 'tls',                         // 'tls' (STARTTLS), 'ssl' alebo ''
    'smtp_user'   => 'noreply@example.sk',
    'smtp_pass'   => '',
    'smtp_from'   => 'noreply@example.sk',
    'smtp_from_name' => 'Cloud',
];
