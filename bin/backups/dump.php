<?php
declare(strict_types=1);
require_once __DIR__ . '/bundle.php';

/** Portable SQL export for restricted hosting without PHP process execution.
 * Deliberately supports only the verified InnoDB base-table inventory. Values
 * are hex literals: no interpolation of data into executable SQL or escaping
 * assumptions. The source credential needs SELECT only.
 */
function backupPdoDump(PDO $db, array $specs, string $destination): void
{
    releaseAssert((int)$db->query('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND UNIQUE_CONSTRAINT_SCHEMA<>DATABASE()')->fetchColumn() === 0, 'Cross-database relationships need a separately reviewed backup.');
    foreach ($specs as $name => $spec) {
        foreach ($db->query('SHOW COLUMNS FROM ' . releaseIdentifier($name))->fetchAll() as $column) {
            releaseAssert(stripos($column['Extra'], 'GENERATED') === false, 'Generated columns need a separately reviewed backup.');
        }
    }
    $file = fopen($destination, 'xb');
    releaseAssert(is_resource($file), 'Cannot create private SQL export.');
    @chmod($destination, 0600);
    $write = static function (string $sql) use ($file): void {
        releaseAssert(fwrite($file, $sql) === strlen($sql), 'Incomplete private SQL export.');
    };
    $buffered = $db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    try {
        $write("-- Allin Family portable export v1; restore only into an empty isolated database.\nSET NAMES utf8mb4;\nSET time_zone='+00:00';\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET FOREIGN_KEY_CHECKS=0;\n");
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->beginTransaction();
        foreach ($specs as $name => $spec) {
            $write($spec['schema'] . ";\n");
            $fields = implode(',', array_map('releaseIdentifier', $spec['columns']));
            $order = implode(',', array_map('releaseIdentifier', $spec['primary']));
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $query = $db->query('SELECT ' . $fields . ' FROM ' . releaseIdentifier($name) . ' ORDER BY ' . $order);
            try {
                while ($row = $query->fetch(PDO::FETCH_NUM)) {
                    $values = array_map(static fn($value): string => $value === null ? 'NULL' : "X'" . bin2hex((string)$value) . "'", $row);
                    $write('INSERT INTO ' . releaseIdentifier($name) . ' (' . $fields . ') VALUES (' . implode(',', $values) . ");\n");
                }
            } finally { $query->closeCursor(); $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered); }
        }
        $db->commit();
        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        releaseAssert(fflush($file), 'Cannot flush private SQL export.');
    } finally {
        if ($db->inTransaction()) $db->rollBack();
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        fclose($file);
    }
}
