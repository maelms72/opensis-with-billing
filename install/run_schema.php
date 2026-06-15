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

// Use login_authentication as sentinel — it's absent on partial/fresh installs
$res = $m->query("SELECT COUNT(*) AS c FROM information_schema.tables
    WHERE table_schema='$name' AND table_name='login_authentication'");
$has_core = (int) $res->fetch_assoc()['c'];

if ($has_core === 0) {
    echo "  Core schema missing — installing...\n";
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

    if (!$m->multi_query($sql)) {
        echo "ERROR in " . basename($path) . ": " . $m->error . "\n";
        exit(1);
    }
    // Drain all result sets so connection is ready for the next call
    do {
        if ($r = $m->store_result()) $r->free();
    } while ($m->more_results() && $m->next_result());
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
