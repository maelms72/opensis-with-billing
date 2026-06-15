<?php
/**
 * Runs openSIS core schema + billing schema on first boot.
 * Called by docker-entrypoint.sh via: php /var/www/html/install/run_schema.php
 */

$host = getenv('DB_HOST');
$port = (int) getenv('DB_PORT') ?: 3306;
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');
$name = getenv('DB_NAME');
$app  = getenv('APP_DIR') ?: '/var/www/html';

$m = new mysqli($host, $user, $pass, $name, $port);
if ($m->connect_errno) {
    echo "ERROR: " . $m->connect_error . "\n";
    exit(1);
}

// Complete install = both api_info (first table) and login_authentication (late table) exist.
// If api_info exists but login_authentication doesn't, the schema is partial —
// re-run it; per-statement 1050 suppression handles already-existing tables safely.
$has_api   = (int) $m->query("SELECT COUNT(*) AS c FROM information_schema.tables
    WHERE table_schema='$name' AND table_name='api_info'")->fetch_assoc()['c'];
$has_login = (int) $m->query("SELECT COUNT(*) AS c FROM information_schema.tables
    WHERE table_schema='$name' AND table_name='login_authentication'")->fetch_assoc()['c'];
$has_core  = ($has_api && $has_login) ? 1 : 0;

if ($has_core === 0) {
    echo "  Core schema " . ($has_api ? "partial" : "missing") . " — installing...\n";
    run_multi($m, "$app/install/OpensisSchemaMysqlInc.sql");
    echo "  ✓ Core schema done\n";
    run_delimited($m, "$app/install/OpensisProcsMysqlInc.sql");
    echo "  ✓ Procs done\n";
    run_delimited($m, "$app/install/OpensisTriggerMysqlInc.sql");
    echo "  ✓ Triggers done\n";
} else {
    echo "  ✓ Core schema already present\n";
}

$res = $m->query("SELECT COUNT(*) AS c FROM information_schema.tables
    WHERE table_schema='$name' AND table_name='billing_fee_types'");
$has_billing = (int) $res->fetch_assoc()['c'];

if ($has_billing === 0) {
    echo "  Installing billing schema...\n";
    run_multi($m, "$app/install/billing_schema.sql");
    echo "  ✓ Billing schema done\n";
} else {
    echo "  ✓ Billing schema already present\n";
}

$m->close();
exit(0);

// ---------------------------------------------------------------------------

function run_multi(mysqli $m, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false) { echo "ERROR: Cannot read $path\n"; exit(1); }

    // Strip --WORD comments (no space after --) that MySQL rejects as syntax errors.
    $sql = preg_replace('/^--\S[^\n]*$/m', '', $sql);

    // Split and run one statement at a time so a 1050 (table already exists)
    // on one statement doesn't abort the rest of the file.
    // PHP 8.2 throws mysqli_sql_exception on query errors, so use try/catch
    // rather than return-value checks — otherwise the 1050 skip never runs.
    $stmts = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($stmts as $stmt) {
        if ($stmt === '') continue;
        try {
            $m->query($stmt);
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1050) continue; // table already exists — safe to skip
            echo "ERROR in " . basename($path) . " (errno {$e->getCode()}): " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

function run_delimited(mysqli $m, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false) { echo "ERROR: Cannot read $path\n"; exit(1); }

    // Strip DELIMITER directives; split on $$ and execute each block
    $sql = preg_replace('/^DELIMITER\s+.*$/mi', '', $sql);
    foreach (array_filter(array_map('trim', explode('$$', $sql))) as $stmt) {
        if (!$m->query($stmt)) {
            echo "ERROR in " . basename($path) . ": " . $m->error . "\n";
            exit(1);
        }
    }
}
