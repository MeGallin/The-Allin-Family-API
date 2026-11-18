<?php
declare(strict_types=1);

function advertDefaultConfig(): array
{
    return ['enabled' => false, 'paused' => false, 'manualApproval' => true,
        'brand' => ['name' => 'The Allin Family', 'tone' => 'Calm, useful British English', 'exclusions' => ['No private family records', 'No invented features or claims']],
        'formats' => ['headline', 'checklist', 'process', 'comparison'],
        'limits' => ['headline' => 80, 'message' => 240, 'item' => 180, 'maxItems' => 4],
        'schedule' => ['mode' => 'daily', 'intervalSeconds' => 86400, 'dailyAt' => '09:00', 'timezone' => 'Europe/London', 'windowStart' => '00:00', 'windowEnd' => '23:59', 'idlePolicy' => 'keep', 'idleSeconds' => 86400, 'preparationLeadSeconds' => 3600],
        'generation' => ['provider' => 'openai', 'model' => 'gpt-6-luna', 'effort' => 'low', 'maxOutputTokens' => 512, 'timeoutSeconds' => 30, 'retries' => 1, 'backoffSeconds' => 30],
        'review' => ['enabled' => false, 'model' => 'gpt-6.1-sol', 'effort' => 'low', 'maxOutputTokens' => 256],
        'image' => ['enabled' => false, 'model' => 'gpt-image-2.6-flare', 'quality' => 'medium', 'complexity' => 'medium', 'size' => '1536x1024', 'timeoutSeconds' => 120,
            'promptStyle' => 'Distinctive editorial campaign artwork. Relevant visual storytelling, no text, logos, screenshots or fabricated interface.', 'maxPromptCharacters' => 1200, 'reservationMicros' => 2000000,
            'layouts' => ['poster', 'split', 'image-led'], 'maxHeadlineWords' => 5],
        'budget' => ['dailyMicros' => 0, 'monthlyMicros' => 0, 'dailyCalls' => 0, 'monthlyCalls' => 0, 'dailyTokens' => 0, 'monthlyTokens' => 0, 'dailyImages' => 0, 'monthlyImages' => 0],
        'prices' => ['checkedAt' => '2026-11-17', 'validDays' => 30, 'models' => array_map(static fn(array $model): array => ['input' => $model['inputPrice'], 'output' => $model['outputPrice']], advertModels())],
        'features' => advertCatalogue()];
}

function advertObject(mixed $value, array $keys): array
{
    if (is_object($value)) $value = (array)$value;
    if (!is_array($value) || array_diff(array_keys($value), $keys) || array_diff($keys, array_keys($value))) {
        throw new HttpError(422, 'Advertising settings have unexpected or missing fields.');
    }
    return $value;
}
function advertIsList(array $value): bool { return array_values($value) === $value; }
function advertInt(mixed $value, int $min, int $max, string $label): int
{
    if (!is_int($value) || $value < $min || $value > $max) throw new HttpError(422, $label . ' is outside the supported limits.');
    return $value;
}
function advertText(mixed $value, int $max, string $label, bool $empty = false): string
{
    if (!is_string($value) || (!$empty && trim($value) === '') || preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]|https?:\/\/|www\./iu', $value)
        || mb_strlen($value) > $max || strlen($value) > $max * 4) throw new HttpError(422, 'Use plain text within the limit for ' . $label . '.');
    return trim($value);
}
function advertEnum(mixed $value, array $choices, string $label): string
{
    if (!in_array($value, $choices, true)) throw new HttpError(422, 'Choose a supported ' . $label . '.');
    return $value;
}
function advertBool(mixed $value): bool
{
    if (!is_bool($value)) throw new HttpError(422, 'Use true or false for advertising switches.');
    return $value;
}
function advertTime(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value)) throw new HttpError(422, 'Use a time in HH:mm format.');
    return $value;
}
function advertExactDate(mixed $value, string $format): bool
{
    if (!is_string($value)) return false;
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format($format) === $value;
}
function advertStrings(mixed $value, int $count, int $length, string $label): array
{
    if (!is_array($value) || !advertIsList($value) || count($value) > $count) throw new HttpError(422, 'Too many values for ' . $label . '.');
    return array_map(static fn($item) => advertText($item, $length, $label), $value);
}
function advertValidateConfig(mixed $value): array
{
    $c = advertObject($value, array_keys(advertDefaultConfig()));
    foreach (['enabled', 'paused', 'manualApproval'] as $key) $c[$key] = advertBool($c[$key]);
    $c['brand'] = advertObject($c['brand'], ['name', 'tone', 'exclusions']);
    $c['brand']['name'] = advertText($c['brand']['name'], 80, 'brand name');
    $c['brand']['tone'] = advertText($c['brand']['tone'], 160, 'tone');
    $c['brand']['exclusions'] = advertStrings($c['brand']['exclusions'], 12, 160, 'copy exclusions');
    $c['formats'] = advertStrings($c['formats'], 4, 20, 'formats');
    if (!$c['formats'] || count(array_unique($c['formats'])) !== count($c['formats'])) throw new HttpError(422, 'Choose distinct advertising formats.');
    foreach ($c['formats'] as $format) advertEnum($format, ['headline', 'checklist', 'process', 'comparison'], 'format');
    $c['limits'] = advertObject($c['limits'], ['headline', 'message', 'item', 'maxItems']);
    foreach (['headline' => [20, 100], 'message' => [40, 400], 'item' => [40, 240], 'maxItems' => [2, 4]] as $key => [$min, $max]) $c['limits'][$key] = advertInt($c['limits'][$key], $min, $max, $key);
    $s = advertObject($c['schedule'], array_keys(advertDefaultConfig()['schedule']));
    $s['mode'] = advertEnum($s['mode'], ['interval', 'daily'], 'schedule');
    $s['intervalSeconds'] = advertInt($s['intervalSeconds'], 30, 2678400, 'Interval seconds');
    foreach (['dailyAt', 'windowStart', 'windowEnd'] as $key) $s[$key] = advertTime($s[$key]);
    $s['timezone'] = advertEnum($s['timezone'], DateTimeZone::listIdentifiers(), 'IANA timezone');
    $s['idlePolicy'] = advertEnum($s['idlePolicy'], ['keep', 'pause'], 'idle policy');
    $s['idleSeconds'] = advertInt($s['idleSeconds'], 60, 2678400, 'Idle seconds');
    $s['preparationLeadSeconds'] = advertInt($s['preparationLeadSeconds'], 0, 86400, 'Preparation lead');
    $c['schedule'] = $s;
    $g = advertObject($c['generation'], array_keys(advertDefaultConfig()['generation']));
    $g['provider'] = advertEnum($g['provider'], ['openai'], 'provider');
    $g['model'] = advertEnum($g['model'], array_keys(advertModels()), 'model');
    $g['effort'] = advertEnum($g['effort'], advertModels()[$g['model']]['efforts'], 'reasoning effort');
    foreach (['maxOutputTokens' => [128, 2048], 'timeoutSeconds' => [5, 45], 'retries' => [0, 2], 'backoffSeconds' => [5, 3600]] as $key => [$min, $max]) $g[$key] = advertInt($g[$key], $min, $max, $key);
    $c['generation'] = $g;
    $r = advertObject($c['review'], array_keys(advertDefaultConfig()['review']));
    $r['enabled'] = advertBool($r['enabled']);
    $r['model'] = advertEnum($r['model'], array_keys(advertModels()), 'review model');
    $r['effort'] = advertEnum($r['effort'], advertModels()[$r['model']]['efforts'], 'review effort');
    $r['maxOutputTokens'] = advertInt($r['maxOutputTokens'], 128, 1024, 'Review tokens');
    $c['review'] = $r;
    $image = advertObject($c['image'], array_keys(advertDefaultConfig()['image']));
    $image['enabled'] = advertBool($image['enabled']);
    $image['model'] = advertEnum($image['model'], ['gpt-image-2.6-flare', 'gpt-image-2.6-sunburst'], 'image model');
    $image['quality'] = advertEnum($image['quality'], ['low', 'medium', 'high'], 'image quality');
    $image['complexity'] = advertEnum($image['complexity'], ['low', 'medium', 'high'], 'image planning complexity');
    $image['size'] = advertEnum($image['size'], ['1024x1024', '1536x1024'], 'image size');
    $image['timeoutSeconds'] = advertInt($image['timeoutSeconds'], 15, 120, 'Image timeout');
    $image['maxPromptCharacters'] = advertInt($image['maxPromptCharacters'], 200, 2000, 'Image prompt characters');
    $image['promptStyle'] = advertText($image['promptStyle'], 800, 'Image style brief');
    $image['reservationMicros'] = advertInt($image['reservationMicros'], 100000, 10000000, 'Estimated image reservation');
    $image['layouts'] = advertStrings($image['layouts'], 3, 12, 'campaign layouts');
    if (!$image['layouts'] || count(array_unique($image['layouts'])) !== count($image['layouts'])) throw new HttpError(422, 'Choose distinct campaign layouts.');
    foreach ($image['layouts'] as $layout) advertEnum($layout, ['poster', 'split', 'image-led'], 'campaign layout');
    $image['maxHeadlineWords'] = advertInt($image['maxHeadlineWords'], 2, 8, 'Maximum headline words');
    $c['image'] = $image;
    $c['budget'] = advertObject($c['budget'], array_keys(advertDefaultConfig()['budget']));
    foreach ($c['budget'] as $key => $v) $c['budget'][$key] = advertInt($v, 0, str_ends_with($key, 'Micros') ? 100000000 : 10000000, $key);
    $p = advertObject($c['prices'], ['checkedAt', 'validDays', 'models']);
    if (!advertExactDate($p['checkedAt'], 'Y-m-d')) throw new HttpError(422, 'Use a valid price check date.');
    if ($p['checkedAt'] > gmdate('Y-m-d')) throw new HttpError(422, 'The price check date cannot be in the future.');
    $p['validDays'] = advertInt($p['validDays'], 1, 30, 'Price validity days');
    $p['models'] = advertObject($p['models'], array_keys(advertModels()));
    foreach ($p['models'] as $modelId => &$price) {
        $price = advertObject($price, ['input', 'output']);
        foreach ($price as $key => $v) $price[$key] = advertInt($v, 1, 500000000, 'Price per million tokens');
        if ($price['input'] < advertModels()[$modelId]['inputPrice'] || $price['output'] < advertModels()[$modelId]['outputPrice']) throw new HttpError(422, 'Price estimates cannot be below the verified service baseline.');
    }
    unset($price);
    $c['prices'] = $p;
    if (!is_array($c['features']) || !advertIsList($c['features']) || count($c['features']) !== count(advertCatalogue())) throw new HttpError(422, 'Keep the approved feature catalogue.');
    $base = array_column(advertCatalogue(), null, 'id'); $seen = [];
    foreach ($c['features'] as &$feature) {
        $feature = advertObject($feature, array_keys(advertCatalogue()[0]));
        $id = advertEnum($feature['id'], array_keys($base), 'feature');
        if (isset($seen[$id]) || $feature['title'] !== $base[$id]['title'] || $feature['ctaId'] !== $base[$id]['ctaId']) throw new HttpError(422, 'Feature identity and destination are fixed.');
        $seen[$id] = true;
        $feature['enabled'] = advertBool($feature['enabled']);
        $feature['weight'] = advertInt($feature['weight'], 1, 100, 'Feature weight');
        if ($feature['expiresAt'] !== null && !advertExactDate($feature['expiresAt'], 'Y-m-d\\TH:i:s\\Z')) throw new HttpError(422, 'Use a UTC expiry timestamp or leave it empty.');
        $feature['exclusions'] = advertStrings($feature['exclusions'], 8, 160, 'feature exclusions');
        if (!is_array($feature['facts']) || !advertIsList($feature['facts']) || count($feature['facts']) < 2 || count($feature['facts']) > 8) throw new HttpError(422, 'Keep two to eight approved facts per feature.');
        $facts = [];
        foreach ($feature['facts'] as &$fact) {
            $fact = advertObject($fact, ['id', 'text']);
            if (!is_string($fact['id']) || !preg_match('/^fact-[1-8]$/D', $fact['id']) || isset($facts[$fact['id']])) throw new HttpError(422, 'Use distinct fact IDs fact-1 to fact-8.');
            $facts[$fact['id']] = true;
            $fact['text'] = advertText($fact['text'], min($c['limits']['message'], $c['limits']['item']), 'approved fact');
        }
        unset($fact);
    }
    unset($feature);
    return $c;
}
