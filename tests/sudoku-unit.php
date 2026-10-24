<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/sudoku-puzzles.php';
$checks = 0;
$check = static function (bool $value, string $label) use (&$checks): void {
    if (!$value) throw new RuntimeException($label);
    $checks++;
};
$budget = 0;
$check(sudokuSolutionCount(str_repeat('0', 81), $budget, microtime(true) + 5) === null, 'Node budget must report unknown, never a unique proof.');
$budget = 100;
$check(sudokuSolutionCount(str_repeat('0', 81), $budget, microtime(true) - 1) === null, 'Deadline exhaustion must report unknown.');
$budget = 100;
$check(sudokuSolutionCount(str_repeat('1', 81), $budget, microtime(true) + 5) === 0, 'Contradictory clues must have zero solutions.');
$check(!sudokuComplete(str_repeat('1', 81)), 'A filled invalid board is not complete.');
$check(!sudokuComplete(str_repeat('0', 81)), 'An incomplete board is not complete.');
echo json_encode(['passed' => $checks]), PHP_EOL;
