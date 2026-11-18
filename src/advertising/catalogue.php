<?php
declare(strict_types=1);

function advertCatalogue(): array
{
    $entries = [
        ['jobs', 'Household Jobs', ['Keep shared family jobs and private jobs in Mine.', 'Claim, assign and complete one-off or repeating jobs.', 'Optional target days are a guide, not a deadline.']],
        ['shopping', 'Shopping List', ['Keep family essentials in one shared shopping list.', 'Add, check and edit items for the family.']],
        ['polls', 'Polls', ['Share a question with two to six choices.', 'Each family member can vote once and see the results.']],
        ['dinner', 'Dinner Spinner', ['Try meal ideas privately before sharing a family choice.', 'Each installation has its own meals and visibility.', 'Spinning does not publish a family meal automatically.']],
        ['where', 'Where Is It?', ['Keep track of where useful things are stored.', 'Add a description and an optional photo to an item.']],
        ['nonograms', 'Nonograms', ['Solve personal picture-logic puzzles in My widgets.', 'Nonogram progress is autosaved and stays private.']],
        ['sudoku', 'Sudoku', ['Solve a personal nine-by-nine number puzzle.', 'Use Save progress to keep your Sudoku changes.', 'Your Sudoku progress stays private.']],
        ['focus', 'Focus Timer', ['Choose a focus period or a short or long break.', 'Your timer and session history stay private.', 'There is no alarm while the browser is closed or asleep.']],
        ['word', 'Daily Word', ['Try the personal daily word puzzle.', 'Share a result with the family only when you choose.']],
        ['calendar', 'Calendar', ['Keep family plans together in the calendar.', 'Review upcoming days without changing your plans.']],
        ['memories', 'Memories', ['Share a family memory with an optional photo.', 'Revisit moments together in Memories.']],
        ['notes', 'Notes', ['Keep notes in your personal space.', 'Your private notes are not an administrator overview.']],
        ['appearance', 'Appearance', ['Choose your theme and accent in your profile.', 'Your appearance preferences belong to your account.']],
        ['week', 'Days Ahead', ['See the week ahead from Home.', 'Choose your own local information preferences in Profile.']],
        ['tfl', 'TfL travel', ['Choose the London transport lines you want to follow.', 'Travel information shows its source and freshness when available.']],
    ];
    return array_map(static function (array $entry): array {
        return ['id' => $entry[0], 'title' => $entry[1], 'ctaId' => $entry[0], 'enabled' => true,
            'weight' => 1, 'expiresAt' => null, 'exclusions' => [],
            'facts' => array_map(static fn(string $text, int $index): array => ['id' => 'fact-' . ($index + 1), 'text' => $text], $entry[2], array_keys($entry[2]))];
    }, $entries);
}

function advertModels(): array
{
    $date = '2026-11-17';
    return [
        'gpt-6-luna' => ['efforts' => ['none', 'low', 'medium', 'high', 'xhigh', 'max'], 'inputPrice' => 100000, 'outputPrice' => 500000, 'priceCheckedAt' => $date],
        'gpt-6.1-sol' => ['efforts' => ['low', 'medium', 'high', 'xhigh', 'max'], 'inputPrice' => 2000000, 'outputPrice' => 10000000, 'priceCheckedAt' => $date],
        'gpt-6.1-terra' => ['efforts' => ['none', 'low', 'medium', 'high', 'xhigh', 'max'], 'inputPrice' => 1000000, 'outputPrice' => 5000000, 'priceCheckedAt' => $date],
        'gpt-6-astra' => ['efforts' => ['low', 'medium', 'high', 'xhigh', 'max'], 'inputPrice' => 10000000, 'outputPrice' => 50000000, 'priceCheckedAt' => $date],
    ];
}

function advertFeatureHash(array $feature): string
{
    unset($feature['weight']); // Selection frequency does not invalidate copy.
    return hash('sha256', json_encode($feature, JSON_THROW_ON_ERROR));
}

function advertEligibleFeatures(array $config, int $now): array
{
    return array_values(array_filter($config['features'], static fn(array $feature): bool =>
        $feature['enabled'] && ($feature['expiresAt'] === null || strtotime($feature['expiresAt']) > $now)));
}
