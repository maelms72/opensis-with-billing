<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<pre>\n";

// Connect directly from env vars — avoids pulling in DatabaseInc.php which
// includes RedirectRootInc.php which calls session_start() + redirects.
$host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: '';
$port = (int)(getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: 3306);
$name = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'railway';
$user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: '';
$pass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '';

echo "Connecting to $host:$port db=$name user=$user\n";
$m = new mysqli($host, $user, $pass, $name, $port);
if ($m->connect_errno) { echo "DB FAILED: " . $m->connect_error; exit; }
echo "Connected OK\n\n";

// Key table row counts
foreach (['app','login_authentication','system_preference_misc','school_years','user_profiles','login_message'] as $t) {
    $c = $m->query("SELECT COUNT(*) AS c FROM `$t`")->fetch_assoc()['c'];
    echo "$t: $c rows\n";
}

$build = $m->query("SELECT value FROM app WHERE name='build' LIMIT 1")->fetch_assoc();
echo "app.build: " . ($build['value'] ?? '(null)') . "\n";

// Fix profile_id=0 -> 1 (admin) in BOTH tables so la.PROFILE_ID=s.PROFILE_ID matches.
// User() in UserFnc.php joins login_authentication and staff on PROFILE_ID — they must be equal.
$ok1 = $m->query("UPDATE login_authentication SET profile_id=1 WHERE username='os4ed'");
echo "\nlogin_authentication profile_id fix: " . ($ok1 ? "OK ({$m->affected_rows} rows)" : "FAILED: " . $m->error) . "\n";
$ok1b = $m->query("UPDATE staff SET profile_id=1 WHERE staff_id=1");
echo "staff profile_id fix: " . ($ok1b ? "OK ({$m->affected_rows} rows)" : "FAILED: " . $m->error) . "\n";
// (profile_id fix output moved up)

// Reset password
$newpass = 'Admin1234!';
$newhash = password_hash($newpass, PASSWORD_DEFAULT);
$ok2 = $m->query("UPDATE login_authentication SET password='$newhash' WHERE username='os4ed'");
echo "Password reset: " . ($ok2 ? "OK ({$m->affected_rows} rows updated)" : "FAILED: " . $m->error) . "\n";
echo "=> Login with  username=os4ed  password=$newpass\n";

// Verify
$row = $m->query("SELECT profile_id, password FROM login_authentication WHERE username='os4ed'")->fetch_assoc();
echo "profile_id now: " . ($row['profile_id'] ?? '(null)') . "\n";
echo "password_verify: " . (password_verify($newpass, $row['password'] ?? '') ? 'PASS' : 'FAIL') . "\n";

// Staff join check (what the login code does)
$sr  = $m->query("SELECT staff_id, current_school_id, profile_id FROM staff WHERE staff_id=1")->fetch_assoc();
echo "\nstaff row: " . ($sr ? "id={$sr['staff_id']} school={$sr['current_school_id']} profile_id={$sr['profile_id']}" : "MISSING") . "\n";
$ssr = $m->query("SELECT syear, school_id, start_date, end_date FROM staff_school_relationship WHERE staff_id=1")->fetch_assoc();
echo "staff_school_rel: " . ($ssr ? "syear={$ssr['syear']} school={$ssr['school_id']} {$ssr['start_date']}..{$ssr['end_date']}" : "MISSING") . "\n";
$sy  = $m->query("SELECT syear, school_id, start_date, end_date FROM school_years WHERE school_id=1 LIMIT 1")->fetch_assoc();
echo "school_years: " . ($sy ? "syear={$sy['syear']} school={$sy['school_id']} {$sy['start_date']}..{$sy['end_date']}" : "MISSING") . "\n";

// The exact query the admin login uses
$jr = $m->query("SELECT PROFILE,STAFF_ID,CURRENT_SCHOOL_ID,FIRST_NAME,LAST_NAME,s.PROFILE_ID,IS_DISABLE,MAX(ssr.SYEAR) AS SYEAR
    FROM staff s INNER JOIN staff_school_relationship ssr USING(staff_id),school_years sy
    WHERE sy.school_id=s.current_school_id AND sy.syear=ssr.syear AND s.STAFF_ID=1");
$jr_row = $jr ? $jr->fetch_assoc() : null;
echo "login_RET query: " . ($jr_row ? "OK syear={$jr_row['SYEAR']} name={$jr_row['FIRST_NAME']} {$jr_row['LAST_NAME']}" : "NO ROWS (login will fail)") . "\n";

// Check and fix profile_exceptions — needed for the navigation menu
$pe_count = $m->query("SELECT COUNT(*) AS c FROM profile_exceptions WHERE profile_id=0")->fetch_assoc()['c'];
echo "\nprofile_exceptions (profile_id=0): $pe_count rows\n";
if ((int)$pe_count < 10) {
    echo "Too few — inserting full permission set...\n";
    $pe_sql = file_get_contents('/var/www/html/install/SqlForClientSchoolInc.php');
    // Extract just the big profile_exceptions INSERT from $text
    if (preg_match("/INSERT INTO `profile_exceptions`[^;]+;/s", $pe_sql, $m2)) {
        $insert = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $m2[0]);
        $ok = $m->query($insert);
        echo "profile_exceptions insert: " . ($ok ? "OK ({$m->affected_rows} rows inserted)" : "FAILED: " . $m->error) . "\n";
    } else {
        echo "Could not extract INSERT from SqlForClientSchoolInc.php\n";
    }
}

// Seed billing module entries in profile_exceptions (admin profile_id=1)
// Delete old wrong-path entries, insert correct ones
$m->query("DELETE FROM profile_exceptions WHERE profile_id=1 AND modname LIKE '%illing/pages/%'");
$billing_mods = ['Billing/pages/Dashboard.php','Billing/pages/FeeTypes.php','Billing/pages/Invoices.php','Billing/pages/Settings.php'];
foreach ($billing_mods as $bmod) {
    $m->query("INSERT IGNORE INTO profile_exceptions (profile_id, modname, can_use, can_edit) VALUES (1,'$bmod','Y','Y')");
}
$bc = $m->query("SELECT COUNT(*) AS c FROM profile_exceptions WHERE profile_id=1 AND modname LIKE '%illing/pages/%'")->fetch_assoc()['c'];
echo "\nbilling profile_exceptions (profile_id=1): $bc rows\n";

// Check and install billing schema tables
$has_settings = (int)$m->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema='$name' AND table_name='billing_settings'")->fetch_assoc()['c'];
echo "\nbilling_settings table: " . ($has_settings ? "EXISTS" : "MISSING") . "\n";
if (!$has_settings) {
    echo "Installing billing schema...\n";
    $sql = file_get_contents('/var/www/html/install/billing_schema.sql');
    $sql = preg_replace('/^\s*--[^\n]*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '' || preg_match('/^SET\s+FOREIGN_KEY_CHECKS/i', $stmt)) continue;
        $ok = $m->query($stmt);
        if (!$ok) echo "  SQL error: " . $m->error . "\n";
    }
    echo "Billing schema installed.\n";
} else {
    echo "billing_fee_types: " . (int)$m->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema='$name' AND table_name='billing_fee_types'")->fetch_assoc()['c'] . " (exists)\n";
}

// Fix school_years and staff_school_relationship start dates so today falls within the active year
$m->query("UPDATE school_years SET start_date='2026-01-01' WHERE school_id=1 AND syear=2026");
echo "\nschool_years start_date fix: {$m->affected_rows} rows\n";
$m->query("UPDATE staff_school_relationship SET start_date='2026-01-01' WHERE staff_id=1 AND syear=2026");
echo "staff_school_rel start_date fix: {$m->affected_rows} rows\n";

// Confirm active school year query now returns a result
$active = $m->query("SELECT MAX(SYEAR) AS SYEAR FROM school_years WHERE CURDATE() BETWEEN start_date AND end_date AND school_id=1")->fetch_assoc();
echo "Active syear (CURDATE between start/end): " . ($active['SYEAR'] ?? '(none - login will have no school)') . "\n";

// Show grade scale tables
echo "\n--- report_card_grade_scales ---\n";
$r = $m->query("SELECT ID, TITLE, GP_SCALE, SORT_ORDER FROM report_card_grade_scales ORDER BY ID");
while ($row = $r->fetch_assoc()) echo "  Scale ID={$row['ID']} title={$row['TITLE']} gp={$row['GP_SCALE']} sort={$row['SORT_ORDER']}\n";

echo "\n--- report_card_grades (rows within scales) ---\n";
$r = $m->query("SELECT ID, GRADE_SCALE_ID, TITLE, GPA_VALUE, SORT_ORDER FROM report_card_grades ORDER BY GRADE_SCALE_ID, SORT_ORDER");
while ($row = $r->fetch_assoc()) echo "  Row ID={$row['ID']} scale={$row['GRADE_SCALE_ID']} title={$row['TITLE']} gpa={$row['GPA_VALUE']} sort={$row['SORT_ORDER']}\n";

$m->close();
echo "\n</pre>\n";
