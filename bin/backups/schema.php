<?php
declare(strict_types=1);
require_once __DIR__ . '/bundle.php';

/** Compare database semantics across MariaDB versions, not SHOW CREATE formatting. */
function backupSchemaDetails(PDO $db, array $specs): array
{
    $result = [];
    $queries = [
        'table' => 'SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION,CREATE_OPTIONS,TABLE_COMMENT,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
        'columns' => 'SELECT COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',
        'indexes' => 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,NULLABLE,INDEX_TYPE,INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',
        'constraints' => 'SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME,CONSTRAINT_TYPE',
        'keys' => 'SELECT CONSTRAINT_NAME,ORDINAL_POSITION,POSITION_IN_UNIQUE_CONSTRAINT,COLUMN_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION',
        'relations' => 'SELECT CONSTRAINT_NAME,UNIQUE_CONSTRAINT_NAME,MATCH_OPTION,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME',
        'checks' => 'SELECT C.CONSTRAINT_NAME,C.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS C JOIN information_schema.TABLE_CONSTRAINTS T ON T.CONSTRAINT_SCHEMA=C.CONSTRAINT_SCHEMA AND T.CONSTRAINT_NAME=C.CONSTRAINT_NAME WHERE T.CONSTRAINT_SCHEMA=DATABASE() AND T.TABLE_NAME=? ORDER BY C.CONSTRAINT_NAME',
    ];
    $database = $db->query('SELECT DATABASE()')->fetchColumn();
    foreach ($specs as $name => $spec) {
        foreach ($queries as $section => $sql) {
            $query = $db->prepare($sql); $query->execute([$name]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                foreach ($row as $key => &$value) {
                    // Metadata protocol numeric types differ across PHP/server
                    // versions; retain NULL and exact text values consistently.
                    if ($value !== null) $value = (string)$value;
                    if ($key === 'REFERENCED_TABLE_SCHEMA' && $value === $database) $value = '@self';
                }
                unset($value);
            }
            unset($row);
            $result[$name][$section] = $rows;
        }
    }
    return $result;
}
