<?php
declare(strict_types=1);

/** Counts only as far as two solutions. Null means the proof budget was exhausted. */
function sudokuSolutionCount(string $puzzle, int &$budget, float $deadline): ?int
{
    $cells = array_map('intval', str_split($puzzle));
    $rows = $columns = $boxes = array_fill(0, 9, 0);
    foreach ($cells as $i => $digit) {
        if (!$digit) continue;
        $r = intdiv($i, 9); $c = $i % 9; $b = intdiv($r, 3) * 3 + intdiv($c, 3); $bit = 1 << ($digit - 1);
        if (($rows[$r] | $columns[$c] | $boxes[$b]) & $bit) return 0;
        $rows[$r] |= $bit; $columns[$c] |= $bit; $boxes[$b] |= $bit;
    }
    static $sizes = null;
    if ($sizes === null) { $sizes = []; for ($i = 0; $i < 512; $i++) $sizes[$i] = substr_count(decbin($i), '1'); }
    $count = 0; $exhausted = false;
    $search = function () use (&$search, &$cells, &$rows, &$columns, &$boxes, &$budget, $deadline, &$count, &$exhausted, $sizes): void {
        if ($count >= 2 || $exhausted) return;
        if (--$budget < 0 || microtime(true) > $deadline) { $exhausted = true; return; }
        $best = -1; $mask = 0; $size = 10;
        foreach ($cells as $i => $digit) {
            if ($digit) continue;
            $r = intdiv($i, 9); $c = $i % 9; $b = intdiv($r, 3) * 3 + intdiv($c, 3);
            $available = 511 & ~($rows[$r] | $columns[$c] | $boxes[$b]);
            if (!$available) return;
            if ($sizes[$available] < $size) { $best = $i; $mask = $available; $size = $sizes[$available]; if ($size === 1) break; }
        }
        if ($best === -1) { $count++; return; }
        $r = intdiv($best, 9); $c = $best % 9; $b = intdiv($r, 3) * 3 + intdiv($c, 3);
        for ($digit = 1; $digit <= 9; $digit++) {
            $bit = 1 << ($digit - 1); if (!($mask & $bit)) continue;
            $cells[$best] = $digit; $rows[$r] |= $bit; $columns[$c] |= $bit; $boxes[$b] |= $bit;
            $search();
            $cells[$best] = 0; $rows[$r] &= ~$bit; $columns[$c] &= ~$bit; $boxes[$b] &= ~$bit;
            if ($count >= 2 || $exhausted) return;
        }
    };
    $search();
    return $exhausted ? null : $count;
}

function sudokuShuffle(array $values): array
{
    for ($i = count($values) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$values[$i], $values[$j]] = [$values[$j], $values[$i]]; }
    return $values;
}
function sudokuDifficulty(mixed $value): string
{
    if (!is_string($value) || !in_array($value, ['easy', 'medium', 'hard'], true)) throw new HttpError(422, 'Choose Easy, Medium or Hard.');
    return $value;
}
/** Self-generated puzzles; levels describe clue density, not a human-solving rating. */
function generateSudoku(string $difficulty): string
{
    $target = ['easy' => 42, 'medium' => 36, 'hard' => 30][$difficulty];
    $budget = 100000; $deadline = microtime(true) + 3.5;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $order = static function (): array { $result = []; foreach (sudokuShuffle([0, 1, 2]) as $band) foreach (sudokuShuffle([0, 1, 2]) as $within) $result[] = $band * 3 + $within; return $result; };
        $rows = $order(); $columns = $order(); $digits = sudokuShuffle(range(1, 9)); $puzzle = '';
        foreach ($rows as $r) foreach ($columns as $c) $puzzle .= $digits[($r * 3 + intdiv($r, 3) + $c) % 9];
        $remaining = 81;
        foreach (sudokuShuffle(range(0, 80)) as $cell) {
            $previous = $puzzle[$cell]; $puzzle[$cell] = '0';
            $solutions = sudokuSolutionCount($puzzle, $budget, $deadline);
            if ($solutions === null) throw new HttpError(503, 'A new puzzle could not be prepared in time. Your existing game is unchanged. Try again.', 'sudoku_generation_busy');
            if ($solutions !== 1) $puzzle[$cell] = $previous;
            elseif (--$remaining === $target) return $puzzle;
        }
    }
    throw new HttpError(503, 'A unique puzzle could not be prepared. Your existing game is unchanged. Try again.', 'sudoku_generation_busy');
}
function sudokuComplete(string $entries): bool
{
    if (str_contains($entries, '0')) return false;
    $budget = 1; // A full valid board uses the terminal node only.
    return sudokuSolutionCount($entries, $budget, microtime(true) + 1) === 1;
}
