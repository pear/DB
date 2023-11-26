--TEST--
Bug 16617: MSSQL escape doesn't take into account trailing backslashes
--FILE--
<?php
include 'DB.php';
include 'DB/mssql.php';

$dbh = new DB_mssql();

$str = "C:\\\r\nX";
echo print_r($str, true), "\n";
echo print_r($dbh->escapeSimple($str), true), "\n";
?>
--EXPECT--
C:\
X
C:\\

X
