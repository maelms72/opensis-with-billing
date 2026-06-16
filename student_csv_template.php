<?php
session_start();
if (empty($_SESSION['USERNAME'])) { http_response_code(403); exit('Not logged in'); }
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="student_import_template.csv"');
echo "first_name,last_name,middle_name,gender,birthdate,grade,email,phone,alt_id,name_suffix,username,password\n";
echo "Jane,Smith,,F,2015-09-01,Y1,jane.smith@example.com,,,,, \n";
echo "Tom,Jones,,M,2014-03-15,Y2,,,TOM001,,,\n";
