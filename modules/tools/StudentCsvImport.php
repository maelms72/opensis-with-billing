<?php

include '../../RedirectModulesInc.php';
include_once '../../functions/PasswordHashFnc.php';

DrawBC(_schoolSetup . ' > Student CSV Import');

$school_id = (int)UserSchool();
$syear     = (int)UserSyear();

// ── Grade level lookup (SHORT_NAME → ID) ──────────────────────────────────
$gl_rows = DBGet(DBQuery("SELECT ID, SHORT_NAME, TITLE FROM school_gradelevels WHERE SCHOOL_ID='$school_id' ORDER BY SORT_ORDER"));
$grade_map = [];
foreach ($gl_rows as $g) {
    $grade_map[strtolower(trim($g['SHORT_NAME']))] = $g['ID'];
    $grade_map[strtolower(trim($g['TITLE']))]      = $g['ID'];
    $grade_map[(string)(int)$g['ID']]              = $g['ID'];
}

// ── Default enrollment code ───────────────────────────────────────────────
$enc = DBGet(DBQuery("SELECT ID FROM student_enrollment_codes WHERE SYEAR=$syear ORDER BY ID LIMIT 1"));
$default_enrollment_code = $enc[1]['ID'] ?? null;

// ── Default calendar ─────────────────────────────────────────────────────
$cal = DBGet(DBQuery("SELECT CALENDAR_ID FROM school_calendars WHERE SYEAR=$syear AND SCHOOL_ID=$school_id AND DEFAULT_CALENDAR='Y' LIMIT 1"));
$default_calendar_id = $cal[1]['CALENDAR_ID'] ?? null;

// ─────────────────────────────────────────────────────────────────────────
// POST: process upload
// ─────────────────────────────────────────────────────────────────────────
$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csvfile'])) {
    $tmp = $_FILES['csvfile']['tmp_name'];
    if (!$tmp || !is_uploaded_file($tmp)) {
        echo '<div class="alert alert-danger">No file uploaded.</div>';
    } else {
        $handle = fopen($tmp, 'r');
        $header = fgetcsv($handle);
        // normalise header
        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        $col = array_flip($header); // column name → index

        $row_num = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;
            $v = fn(string $name) => isset($col[$name]) ? trim($row[$col[$name]] ?? '') : '';

            $first = $v('first_name');
            $last  = $v('last_name');
            if ($first === '' || $last === '') {
                $results[] = ['row' => $row_num, 'status' => 'skip', 'msg' => 'Missing first_name or last_name'];
                continue;
            }

            // ── Duplicate check ──────────────────────────────────────────
            $fn  = $GLOBALS['connection']->real_escape_string($first);
            $ln  = $GLOBALS['connection']->real_escape_string($last);
            $dup = DBGet(DBQuery("SELECT COUNT(*) AS C FROM students WHERE FIRST_NAME='$fn' AND LAST_NAME='$ln'"));
            if ((int)($dup[1]['C'] ?? 0) > 0) {
                $alt_id = $v('alt_id');
                if ($alt_id !== '') {
                    $ai = $GLOBALS['connection']->real_escape_string($alt_id);
                    $dup2 = DBGet(DBQuery("SELECT COUNT(*) AS C FROM students WHERE ALT_ID='$ai'"));
                    if ((int)($dup2[1]['C'] ?? 0) > 0) {
                        $results[] = ['row' => $row_num, 'status' => 'dup', 'msg' => "$first $last — already exists"];
                        continue;
                    }
                } else {
                    $results[] = ['row' => $row_num, 'status' => 'dup', 'msg' => "$first $last — already exists"];
                    continue;
                }
            }

            // ── Resolve grade ────────────────────────────────────────────
            $grade_raw = strtolower($v('grade') ?: $v('grade_id'));
            $grade_id  = $grade_map[$grade_raw] ?? null;

            // ── INSERT students ──────────────────────────────────────────
            $esc = fn(string $s) => "'" . $GLOBALS['connection']->real_escape_string($s) . "'";
            $cols = ['FIRST_NAME', 'LAST_NAME'];
            $vals = [$esc($first), $esc($last)];

            $optional_map = [
                'middle_name'  => 'MIDDLE_NAME',
                'gender'       => 'GENDER',
                'birthdate'    => 'BIRTHDATE',
                'email'        => 'EMAIL',
                'phone'        => 'PHONE',
                'alt_id'       => 'ALT_ID',
                'name_suffix'  => 'NAME_SUFFIX',
            ];
            foreach ($optional_map as $csv_col => $db_col) {
                $val = $v($csv_col);
                if ($val !== '') {
                    $cols[] = $db_col;
                    $vals[] = $esc($val);
                }
            }

            DBQuery('INSERT INTO students (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');
            global $connection;
            $student_id = mysqli_insert_id($connection);

            // ── INSERT student_enrollment ────────────────────────────────
            $en_cols = ['SYEAR','SCHOOL_ID','STUDENT_ID'];
            $en_vals = [$syear, $school_id, $student_id];
            if ($default_enrollment_code) { $en_cols[] = 'ENROLLMENT_CODE'; $en_vals[] = $default_enrollment_code; }
            if ($default_calendar_id)     { $en_cols[] = 'CALENDAR_ID';     $en_vals[] = $default_calendar_id; }
            if ($grade_id)                { $en_cols[] = 'GRADE_ID';        $en_vals[] = $grade_id; }
            $en_cols[] = 'START_DATE';
            $en_vals[] = "'" . date('Y-m-d') . "'";

            $enc_cols = implode(',', $en_cols);
            $enc_vals = implode(',', array_map(fn($x) => is_string($x) && str_starts_with($x, "'") ? $x : "'$x'", $en_vals));
            DBQuery("INSERT INTO student_enrollment ($enc_cols) VALUES ($enc_vals)");

            // ── INSERT login_authentication ──────────────────────────────
            $username = $v('username') ?: strtolower($first . '.' . $last);
            $password = $v('password') ?: 'changeme';
            $username = $GLOBALS['connection']->real_escape_string($username);
            $pass_hash = GenerateNewHash($password);
            DBQuery("INSERT INTO login_authentication (USER_ID,PROFILE_ID,USERNAME,PASSWORD)
                     VALUES ($student_id, 3, '$username', '$pass_hash')");

            // ── INSERT student_address (minimal, required for searches) ──
            foreach (['Home Address','Mail','Primary','Secondary'] as $atype) {
                $at = $GLOBALS['connection']->real_escape_string($atype);
                DBQuery("INSERT INTO student_address (STUDENT_ID,SYEAR,SCHOOL_ID,TYPE)
                         VALUES ($student_id,$syear,$school_id,'$at')");
            }

            $results[] = ['row' => $row_num, 'status' => 'ok', 'msg' => "Imported $first $last" . ($grade_id ? " (grade $grade_raw)" : '')];
        }
        fclose($handle);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Results display
// ─────────────────────────────────────────────────────────────────────────
if ($results) {
    $ok   = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
    $dup  = count(array_filter($results, fn($r) => $r['status'] === 'dup'));
    $skip = count(array_filter($results, fn($r) => $r['status'] === 'skip'));
    echo '<div class="alert alert-info">';
    echo "<strong>Import complete:</strong> $ok imported, $dup duplicates skipped, $skip rows skipped.";
    echo '</div>';
    echo '<table class="table table-condensed table-bordered" style="font-size:13px;">';
    echo '<thead><tr><th>Row</th><th>Result</th><th>Detail</th></tr></thead><tbody>';
    foreach ($results as $r) {
        $cls = $r['status'] === 'ok' ? 'success' : ($r['status'] === 'dup' ? 'warning' : 'danger');
        echo "<tr class=\"$cls\"><td>{$r['row']}</td><td>{$r['status']}</td><td>" . htmlspecialchars($r['msg']) . "</td></tr>";
    }
    echo '</tbody></table>';
}

// ─────────────────────────────────────────────────────────────────────────
// Upload form
// ─────────────────────────────────────────────────────────────────────────
?>
<div class="panel panel-white">
  <div class="panel-heading"><h4 class="panel-title">Import Students from CSV</h4></div>
  <div class="panel-body">

    <p class="text-muted" style="font-size:13px;">
      Upload a CSV file with a header row. Supported columns:
    </p>
    <table class="table table-condensed" style="font-size:12px;max-width:600px;">
      <thead><tr><th>Column</th><th>Required</th><th>Notes</th></tr></thead>
      <tbody>
        <tr><td><code>first_name</code></td><td><span class="text-danger">Yes</span></td><td></td></tr>
        <tr><td><code>last_name</code></td><td><span class="text-danger">Yes</span></td><td></td></tr>
        <tr><td><code>middle_name</code></td><td>No</td><td></td></tr>
        <tr><td><code>gender</code></td><td>No</td><td>M or F</td></tr>
        <tr><td><code>birthdate</code></td><td>No</td><td>YYYY-MM-DD</td></tr>
        <tr><td><code>grade</code></td><td>No</td><td>Short name or title from Grade Levels setup</td></tr>
        <tr><td><code>email</code></td><td>No</td><td></td></tr>
        <tr><td><code>phone</code></td><td>No</td><td></td></tr>
        <tr><td><code>alt_id</code></td><td>No</td><td>Your school's student reference number</td></tr>
        <tr><td><code>name_suffix</code></td><td>No</td><td>Jr, Sr, etc.</td></tr>
        <tr><td><code>username</code></td><td>No</td><td>Defaults to firstname.lastname</td></tr>
        <tr><td><code>password</code></td><td>No</td><td>Defaults to <em>changeme</em></td></tr>
      </tbody>
    </table>

    <?php
    // Show available grade levels to help user fill the CSV
    if ($gl_rows) {
        echo '<p class="text-muted" style="font-size:12px;"><strong>Available grade levels in this school:</strong> ';
        $labels = [];
        foreach ($gl_rows as $g) $labels[] = htmlspecialchars($g['SHORT_NAME']) . ' (' . htmlspecialchars($g['TITLE']) . ')';
        echo implode(', ', $labels) . '</p>';
    }
    ?>

    <form method="POST" enctype="multipart/form-data" style="max-width:400px;">
      <div class="form-group">
        <label>CSV File</label>
        <input type="file" name="csvfile" accept=".csv,text/csv" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="icon-upload"></i> Upload &amp; Import
      </button>
      <a href="student_csv_template.php" class="btn btn-default">
        <i class="icon-download"></i> Download Template
      </a>
    </form>

  </div>
</div>
