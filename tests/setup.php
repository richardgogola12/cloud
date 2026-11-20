<?php
/* Príprava testovacieho prostredia: config.php, databáza, používatelia */
$root = dirname(__DIR__) . '/server';
$storage = getenv('CLOUD_TEST_STORAGE') ?: '/tmp/cloud-storage';
@mkdir($storage, 0777, true);
file_put_contents("$root/config.php", "<?php return " . var_export([
    'app_name' => 'Cloud', 'app_url' => 'http://127.0.0.1:8080', 'timezone' => 'Europe/Bratislava', 'update_repo' => 'richardgogola12/cloud',
    'db_host' => '127.0.0.1', 'db_name' => 'cloud', 'db_user' => 'root', 'db_pass' => 'root', 'db_prefix' => 'cloud_',
    'storage_path' => $storage, 'session_name' => 'CLOUD_TEST', 'session_lifetime' => 86400, 'default_quota' => 200 * 1024 * 1024,
    'smtp_host' => '127.0.0.1', 'smtp_port' => 1, 'smtp_secure' => 'none', 'smtp_user' => '', 'smtp_pass' => '',
    'smtp_from' => 'noreply@test.sk', 'smtp_from_name' => 'Cloud',
], true) . ";\n");
$_SERVER['REQUEST_METHOD'] = 'GET';
require "$root/inc/core.php";
$sql = preg_replace('/^\s*--.*$/m', '', file_get_contents("$root/install/schema.sql"));
foreach (preg_split('/;\s*$/m', $sql) as $st) if (trim($st) !== '') db()->exec($st);
unset($GLOBALS['_settings']);
migrate();
setting_set('require_email_verify', '0');
setting_set('registration', 'open');
setting_set('max_upload_mb', '0');
$pw = password_hash('Heslo1234', PASSWORD_DEFAULT);
q("INSERT INTO cloud_users (name, email, password, role, quota, is_active, email_verified) VALUES ('Admin Test','admin@test.sk',?,'admin',0,1,1)", [$pw]);
q("INSERT INTO cloud_users (name, email, password, role, quota, is_active, email_verified) VALUES ('Beáta Testová','beata@test.sk',?,'user',104857600,1,1)", [$pw]);
echo "Setup OK, schema " . setting('schema_version') . "\n";
