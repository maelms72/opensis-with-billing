<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<pre>\n";

include '/var/www/html/DatabaseInc.php';
$m = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
if ($m->connect_errno) { echo "DB FAILED: " . $m->connect_error; exit; }

// Key table row counts
foreach (['app','login_authentication','system_preference_misc','school_years','user_profiles','login_message'] as $t) {
    $c = $m->query("SELECT COUNT(*) AS c FROM `$t`")->fetch_assoc()['c'];
    echo "$t: $c rows\n";
}

// Duplicate app rows?
$build = $m->query("SELECT value FROM app WHERE name='build' LIMIT 1")->fetch_assoc();
echo "app.build (first): " . ($build['value'] ?? '(null)') . "\n";

// Check system_preference_misc columns
$cols = $m->query("SHOW COLUMNS FROM system_preference_misc");
echo "\nsystem_preference_misc columns:\n";
while ($r = $cols->fetch_assoc()) echo "  " . $r['Field'] . "\n";

// Try rendering LoginInc.php with errors on
echo "\nAttempting LoginInc.php render:\n";
chdir('/var/www/html');
$_SERVER['PHP_SELF']    = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = []; $_POST = []; $_REQUEST = [];

ob_start();
try {
    require_once '/var/www/html/LoginInc.php';
} catch (Throwable $e) {
    echo "\nEXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}
$out = ob_get_clean();
echo "Output length: " . strlen($out) . " bytes\n";
if (strlen($out) > 0) {
    echo "First 500 chars:\n" . htmlspecialchars(substr($out, 0, 500)) . "\n";
} else {
    echo "No output produced.\n";
}

// Fix profile_id=0 → profile_id=1 (admin) so the login path resolves the profile correctly
$ok1 = $m->query("UPDATE login_authentication SET profile_id=1 WHERE username='os4ed'");
echo "\nprofile_id fix: " . ($ok1 ? "OK ({$m->affected_rows} rows)" : "FAILED: " . $m->error) . "\n";

// Reset admin password to a known value
$newpass = 'Admin1234!';
$newhash = password_hash($newpass, PASSWORD_DEFAULT);
$ok2 = $m->query("UPDATE login_authentication SET password='$newhash' WHERE username='os4ed'");
echo "Password reset: " . ($ok2 ? "OK ({$m->affected_rows} rows)" : "FAILED: " . $m->error) . "\n";
echo "Login with  username=os4ed  password=$newpass\n";

// Verify
$row = $m->query("SELECT profile_id, password FROM login_authentication WHERE username='os4ed'")->fetch_assoc();
echo "profile_id now: " . ($row['profile_id'] ?? '(null)') . "\n";
echo "password_verify check: " . (password_verify($newpass, $row['password'] ?? '') ? 'PASS' : 'FAIL') . "\n";

// Check staff record exists
$sr = $m->query("SELECT staff_id, current_school_id FROM staff WHERE staff_id=1")->fetch_assoc();
echo "staff row: " . ($sr ? "staff_id={$sr['staff_id']} school_id={$sr['current_school_id']}" : "MISSING") . "\n";
$ssr = $m->query("SELECT syear, school_id FROM staff_school_relationship WHERE staff_id=1")->fetch_assoc();
echo "staff_school_rel: " . ($ssr ? "syear={$ssr['syear']} school_id={$ssr['school_id']}" : "MISSING") . "\n";
$sy = $m->query("SELECT syear, school_id, start_date, end_date FROM school_years WHERE school_id=1 LIMIT 1")->fetch_assoc();
echo "school_years: " . ($sy ? "syear={$sy['syear']} school_id={$sy['school_id']} {$sy['start_date']}→{$sy['end_date']}" : "MISSING") . "\n";

$m->close();
echo "\n</pre>\n";
