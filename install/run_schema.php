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

// Seed required data if app table is empty.
// SqlForClientSchoolInc.php assigns all seed SQL to $text — include it and run it.
// This is normally executed by the interactive PHP installer which we bypass.
// Use user_profiles as sentinel — app may be manually populated but user data absent.
$app_rows = (int) $m->query("SELECT COUNT(*) AS c FROM `user_profiles`")->fetch_assoc()['c'];
if ($app_rows === 0) {
    echo "  Seeding initial data...\n";
    chdir("$app/install");
    // SqlForClientSchoolInc.php reads DB credentials from $_SESSION at line 218.
    // There is no web session in the CLI entrypoint, so populate it manually.
    $_SESSION['server']              = getenv('DB_HOST');
    $_SESSION['port']                = getenv('DB_PORT');
    $_SESSION['db']                  = getenv('DB_NAME');
    $_SESSION['username']            = getenv('DB_USER');
    $_SESSION['password']            = getenv('DB_PASS');
    $_SESSION['syear']               = date('Y');
    $_SESSION['sname']               = 'Default School';
    // Use Jan 1 of the current year so today always falls within the active school year
    // regardless of when the container first boots.
    $_SESSION['user_school_beg_date'] = date('Y') . '-01-01';
    $_SESSION['user_school_end_date'] = (date('Y') + 1) . '-06-30';
    $text = '';
    require "$app/install/SqlForClientSchoolInc.php";
    run_multi_str($m, $text);
    // SqlForClientSchoolInc.php stores build as YYYYMMDDREV but UpgradeInc.php
    // parses it as MMDDYYYYREV (month=0-1, day=2-3, year=4-7). Fix it so the
    // computed date falls after the 05/28/2009 cutoff and no upgrade redirect fires.
    $m->query("UPDATE `app` SET `value`='02062026001' WHERE `name`='build'");
    // profile_id=0 in login_authentication has no matching user_profiles row (IDs start at 1).
    // The login code resolves the profile via user_profiles WHERE ID=profile_id, so 0 never
    // matches 'admin' and the session is never set. Fix it to profile_id=1 (admin).
    $m->query("UPDATE `login_authentication` SET `profile_id`=1 WHERE `username`='os4ed'");
    // system_preference_misc is never seeded by any installer file but must
    // have exactly one row — openSIS reads it on every page for maintenance
    // mode, failed-login limits, and activity-day checks.
    $m->query("INSERT IGNORE INTO `system_preference_misc`
        (fail_count, activity_days, system_maintenance_switch) VALUES (3, 30, NULL)");
    echo "  ✓ Initial data seeded\n";
} else {
    echo "  ✓ Seed data already present\n";
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

function run_multi_str(mysqli $m, string $sql): void {
    $sql = preg_replace('/^\s*--\S[^\n]*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $segments = preg_split('/^DELIMITER\s+(\S+)\s*$/mi', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
    $delimiter = ';';
    foreach ($segments as $segment) {
        $segment = trim($segment);
        if ($segment === '') continue;
        if (preg_match('/^\S+$/', $segment) && strlen($segment) <= 10 && !str_contains($segment, ' ')) {
            $delimiter = $segment;
            continue;
        }
        $stmts = array_filter(array_map('trim', explode($delimiter, $segment)));
        foreach ($stmts as $stmt) {
            if ($stmt === '') continue;
            try {
                $m->query($stmt);
            } catch (\mysqli_sql_exception $e) {
                if ($e->getCode() === 1050 || $e->getCode() === 1062) continue;
                echo "ERROR seeding (errno {$e->getCode()}): " . $e->getMessage() . "\n";
                exit(1);
            }
        }
    }
}

function run_multi(mysqli $m, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false) { echo "ERROR: Cannot read $path\n"; exit(1); }

    // Strip --WORD comments (no space after --) that MySQL rejects as syntax errors.
    // Allow optional leading whitespace before the -- so lines like " --ALTER" are caught.
    $sql = preg_replace('/^\s*--\S[^\n]*$/m', '', $sql);

    // Strip /* ... */ block comments (may contain semicolons that would
    // produce invalid fragments when splitting on ';').
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    // Extract and separately execute DELIMITER $$ blocks (client-only command
    // that mysqli::query() cannot handle). Split the file on DELIMITER directives,
    // execute plain sections on ';', and $$ sections statement-by-statement.
    $segments = preg_split('/^DELIMITER\s+(\S+)\s*$/mi', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
    $delimiter = ';';
    foreach ($segments as $segment) {
        $segment = trim($segment);
        if ($segment === '') continue;
        // A captured delimiter token — switch the active delimiter and skip.
        if (preg_match('/^\S+$/', $segment) && strlen($segment) <= 10 && !str_contains($segment, ' ')) {
            $delimiter = $segment;
            continue;
        }
        if ($delimiter === ';') {
            $stmts = array_filter(array_map('trim', explode(';', $segment)));
        } else {
            // $$ or other non-semicolon delimiter: split on it, each piece is a full statement.
            $stmts = array_filter(array_map('trim', explode($delimiter, $segment)));
        }
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
