<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<pre>\n";

$db_inc = '/var/www/html/DatabaseInc.php';
include $db_inc;

$m = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
if ($m->connect_errno) { echo "DB FAILED: " . $m->connect_error; exit; }
echo "DB: connected\n\n";

// Show which core tables exist
$tables = ['app','login_authentication','school_years','staff','system_preference_misc','billing_fee_types'];
echo "Table presence:\n";
foreach ($tables as $t) {
    $r = $m->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema='" . DB_NAME . "' AND table_name='$t'")->fetch_assoc();
    echo "  " . ($r['c'] ? '✓' : '✗') . " $t\n";
}

// If app table exists, show version info
echo "\n";
if ($m->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema='".DB_NAME."' AND table_name='app'")->fetch_assoc()['c']) {
    $rows = $m->query("SELECT name, value FROM app");
    echo "app table contents:\n";
    while ($row = $rows->fetch_assoc()) echo "  {$row['name']} = {$row['value']}\n";
} else {
    echo "app table MISSING — core schema not installed\n";
}

// Try including UpgradeInc.php with errors on to see where it dies
echo "\nTesting UpgradeInc.php include:\n";
// Simulate what it checks
$DatabaseServer = DB_HOST;
if ($DatabaseServer == '') {
    echo "  Would redirect to install/index.php (DatabaseServer empty)\n";
} else {
    echo "  DatabaseServer is set: $DatabaseServer\n";
    $r = $m->query("SELECT value FROM app WHERE name='build'");
    if (!$r) {
        echo "  FAILED querying app.build: " . $m->error . "\n";
    } else {
        $build = $r->fetch_assoc();
        echo "  app.build = " . $build['value'] . "\n";
    }
}

// Try the full include chain with errors forced on
echo "\nTesting LoginInc.php include chain:\n";
ob_start();
try {
    // Test just the lang file
    if (file_exists('/var/www/html/lang/lang_en.php')) {
        echo "  lang/lang_en.php: exists\n";
    } else {
        echo "  lang/lang_en.php: MISSING\n";
    }
    if (file_exists('/var/www/html/lang/supportedLanguages.php')) {
        echo "  lang/supportedLanguages.php: exists\n";
    } else {
        echo "  lang/supportedLanguages.php: MISSING\n";
    }
} catch (Throwable $e) {
    echo "  Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}
$out = ob_get_clean();
echo $out;

$m->close();
echo "</pre>\n";
