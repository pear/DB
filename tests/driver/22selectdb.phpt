--TEST--
DB_mysqli selects the database only when the link may not have it
--SKIPIF--
<?php
require_once dirname(__FILE__) . '/skipif.inc';
if ($dbh->phptype != 'mysqli') {
    die('skip this test is for the mysqli driver');
}
?>
--FILE--
<?php
require_once dirname(__FILE__) . '/connect.inc';

$dbh->setErrorHandling(PEAR_ERROR_DIE);
$db = $dbh->dsn['database'];

// Without WeakMap (PHP < 8) the driver selects before every statement.
$once = class_exists('WeakMap', false);

/**
 * Returns how many times the session has changed database so far
 */
function changes($dbh) {
    $row = $dbh->getRow("SHOW SESSION STATUS LIKE 'Com_change_db'",
                        array(), DB_FETCHMODE_ORDERED);
    return (int) $row[1];
}

function check($label, $got, $expected) {
    echo $label . ': ' . ($got === $expected ? 'ok' : "got $got, expected $expected") . "\n";
}

/**
 * Runs a statement that some servers refuse
 */
function attempt($dbh, $query) {
    $dbh->setErrorHandling(PEAR_ERROR_RETURN);
    $dbh->query($query);
    $dbh->setErrorHandling(PEAR_ERROR_DIE);
}

$dbh->query('SELECT 1');
$before = changes($dbh);
for ($i = 0; $i < 5; $i++) {
    $dbh->query('SELECT 1');
}
check('five queries on one link', changes($dbh) - $before, $once ? 0 : 6);

$before = changes($dbh);
$dbh->query('START TRANSACTION');
$dbh->query('ROLLBACK');
$dbh->query("SHOW VARIABLES LIKE 'version'");
$dbh->query('COMMIT');
$dbh->query('DROP TEMPORARY TABLE IF EXISTS selectdb_tmp');
check('five neutral statements', changes($dbh) - $before, $once ? 0 : 6);

// The MariaDB-only forms are syntax errors on MySQL.
foreach (array(
    'USE information_schema',
    '/*!32312 USE information_schema*/',
    'SET STATEMENT max_statement_time=1 FOR USE information_schema',
    'SET /*!100100STATEMENT*/ max_statement_time=1 FOR USE information_schema',
    'BEGIN NOT ATOMIC EXECUTE IMMEDIATE "USE information_schema"; END',
) as $query) {
    attempt($dbh, $query);
    check("database after: $query", $dbh->getOne('SELECT DATABASE()'), $db);
}

$clone = clone $dbh;
$clone->query('USE information_schema');
check('database after a USE through a clone', $dbh->getOne('SELECT DATABASE()'), $db);

// Each must make the next statement select. Every server refuses the
// CREATE and ALTER on information_schema or rejects their syntax.
foreach (array(
    '/* a comment */ SELECT 1',
    'SET @a = 1',
    'CREATE OR REPLACE DATABASE information_schema',
    'ALTER DATABASE information_schema UPGRADE DATA DIRECTORY NAME',
    'EXPLAIN FOR SCHEMA information_schema SELECT 1',
) as $query) {
    $before = changes($dbh);
    attempt($dbh, $query);
    $dbh->query('SELECT 1');
    check("next statement selects after: $query", changes($dbh) - $before, $once ? 1 : 3);
}
?>
--EXPECT--
five queries on one link: ok
five neutral statements: ok
database after: USE information_schema: ok
database after: /*!32312 USE information_schema*/: ok
database after: SET STATEMENT max_statement_time=1 FOR USE information_schema: ok
database after: SET /*!100100STATEMENT*/ max_statement_time=1 FOR USE information_schema: ok
database after: BEGIN NOT ATOMIC EXECUTE IMMEDIATE "USE information_schema"; END: ok
database after a USE through a clone: ok
next statement selects after: /* a comment */ SELECT 1: ok
next statement selects after: SET @a = 1: ok
next statement selects after: CREATE OR REPLACE DATABASE information_schema: ok
next statement selects after: ALTER DATABASE information_schema UPGRADE DATA DIRECTORY NAME: ok
next statement selects after: EXPLAIN FOR SCHEMA information_schema SELECT 1: ok
