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

// Fix profile_id=0 -> 1 (admin) so login path resolves the profile correctly
$ok1 = $m->query("UPDATE login_authentication SET profile_id=1 WHERE username='os4ed'");
echo "\nprofile_id fix: " . ($ok1 ? "OK ({$m->affected_rows} rows updated)" : "FAILED: " . $m->error) . "\n";

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

// Fix school_years and staff_school_relationship start dates so today falls within the active year
$m->query("UPDATE school_years SET start_date='2026-01-01' WHERE school_id=1 AND syear=2026");
echo "\nschool_years start_date fix: {$m->affected_rows} rows\n";
$m->query("UPDATE staff_school_relationship SET start_date='2026-01-01' WHERE staff_id=1 AND syear=2026");
echo "staff_school_rel start_date fix: {$m->affected_rows} rows\n";

// Confirm active school year query now returns a result
$active = $m->query("SELECT MAX(SYEAR) AS SYEAR FROM school_years WHERE CURDATE() BETWEEN start_date AND end_date AND school_id=1")->fetch_assoc();
echo "Active syear (CURDATE between start/end): " . ($active['SYEAR'] ?? '(none - login will have no school)') . "\n";

$m->close();
echo "\n</pre>\n";
