<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/database.php';
require __DIR__ . '/src/release-maintenance.php';
require __DIR__ . '/src/auth.php';
require __DIR__ . '/src/activity.php';
require __DIR__ . '/src/snapshot-response.php';
require __DIR__ . '/src/shopping.php';
require __DIR__ . '/src/profiles.php';
require __DIR__ . '/src/admin-members.php';
require __DIR__ . '/src/calendar.php';
require __DIR__ . '/src/memories.php';
require __DIR__ . '/src/notes.php';
require __DIR__ . '/src/polls.php';
require __DIR__ . '/src/admin-activity.php';
require __DIR__ . '/src/usage.php';
require __DIR__ . '/src/reporting.php';
require __DIR__ . '/src/admin-insights.php';
require __DIR__ . '/src/week-context.php';
require __DIR__ . '/src/week-preferences.php';
require __DIR__ . '/src/food-alerts.php';
require __DIR__ . '/src/weather-warnings.php';
require __DIR__ . '/src/travel.php';

set_exception_handler(static function (Throwable $error): void {
    if ($error instanceof HttpError) {
        if ($error->status === 503) header('Retry-After: 60');
        jsonResponse(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->status);
    } else {
        error_log(get_class($error) . ': ' . $error->getMessage());
        jsonResponse(['error' => 'The service is temporarily unavailable. Please try again.'], 500);
    }
});

$configuration = loadConfiguration(__DIR__ . '/.env');
// Must precede health, authentication, sessions and every database operation.
$requestLease = acquireFamilyRequestLease($configuration);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path)) {
    jsonResponse(['error' => 'Invalid request path.'], 400);
    exit;
}

// Support an Apache subdirectory, including the existing XAMPP project path.
$mount = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
if ($mount !== '' && $mount !== '.' && ($path === $mount || str_starts_with($path, $mount . '/'))) {
    $path = substr($path, strlen($mount)) ?: '/';
}

$routes = [
    '/health' => ['GET', 'HEAD'], '/auth/session' => ['GET'], '/auth/login' => ['POST'],
    '/auth/logout' => ['POST'], '/auth/password' => ['POST'], '/family' => ['GET'],
    '/shopping' => ['GET'], '/shopping/add' => ['POST'], '/shopping/edit' => ['POST'],
    '/shopping/check' => ['POST'], '/shopping/remove' => ['POST'],
    '/profile' => ['GET'], '/profile/nickname' => ['POST'],
    '/admin/users' => ['GET'], '/admin/users/nickname' => ['POST'],
    '/admin/members' => ['GET'], '/admin/members/add' => ['POST'],
    '/admin/members/edit' => ['POST'], '/admin/members/password' => ['POST'],
    '/calendar' => ['GET'], '/calendar/add' => ['POST'],
    '/calendar/edit' => ['POST'], '/calendar/remove' => ['POST'],
    '/memories' => ['GET'], '/memories/photo' => ['GET'],
    '/memories/add' => ['POST'], '/memories/edit' => ['POST'],
    '/memories/remove' => ['POST'], '/memories/react' => ['POST'],
    '/notes' => ['GET'], '/notes/add' => ['POST'],
    '/notes/edit' => ['POST'], '/notes/remove' => ['POST'],
    '/polls' => ['GET'], '/polls/add' => ['POST'], '/polls/edit' => ['POST'],
    '/polls/vote' => ['POST'], '/polls/close' => ['POST'], '/polls/remove' => ['POST'],
    '/admin/activity' => ['GET'],
    '/auth/presence' => ['POST'], '/admin/usage' => ['GET'],
    '/admin/insights' => ['GET'],
    '/week-context' => ['GET'],
    '/food-alerts' => ['GET'],
    '/weather-warnings' => ['GET'],
    '/travel' => ['GET'], '/profile/travel-preferences' => ['GET', 'POST'],
    '/profile/week-preferences' => ['GET', 'POST'], '/week-context/towns' => ['GET'],
];
if (!isset($routes[$path])) throw new HttpError(404, 'Not found.');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $routes[$path], true)) {
    header('Allow: ' . implode(', ', $routes[$path]));
    throw new HttpError(405, 'Method not allowed.');
}
if ($path === '/health') {
    jsonResponse(['status' => 'ok', 'service' => $configuration['APP_NAME']]);
    exit;
}

$db = null;
try {
    $db = connectDatabase($configuration);
} catch (Throwable $error) {
    // Database/analytics downtime must not prevent the user clearing their login cookie.
    if ($path !== '/auth/logout') throw $error;
    error_log('Usage recording unavailable during sign-out.');
}
startFamilySession($configuration, $db);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') requireCsrf($configuration);
if ($path === '/auth/logout') {
    $logoutBody = requestJson();
    if ($db !== null) {
        try {
            endPlatformSession($db, 'logout', $logoutBody['page'] ?? null);
        } catch (Throwable $error) {
            error_log('Usage recording unavailable during sign-out.');
        }
    }
    $_SESSION = [];
    session_destroy();
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => $cookie['secure'],
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    jsonResponse(['ok' => true]);
    exit;
}

if ($path === '/auth/presence') {
    $user = requireFamilyUser($db);
    jsonResponse(recordPlatformPresence($db, $user, requestJson()));
    exit;
}
if ($path === '/admin/usage') {
    $user = requireFamilyUser($db);
    jsonResponse(adminUsageSnapshot($db, $user, $_GET));
    exit;
}
if ($path === '/admin/insights') {
    $user = requireFamilyUser($db);
    jsonResponse(adminInsightsSnapshot($db, $user, $_GET));
    exit;
}
if ($path === '/food-alerts') {
    requireFamilyUser($db);
    session_write_close();
    jsonResponse(foodAlertsSnapshot($configuration));
    exit;
}
if ($path === '/profile/travel-preferences') {
    $user = requireFamilyUser($db);
    jsonResponse($_SERVER['REQUEST_METHOD'] === 'POST'
        ? saveTravelPreferences($db, $user, requestJson()) : travelPreferences($db, $user));
    exit;
}
if ($path === '/travel') {
    $user = requireFamilyUser($db);
    $preferences = travelPreferences($db, $user);
    session_write_close();
    jsonResponse(travelSnapshot($configuration, $preferences));
    exit;
}
if ($path === '/weather-warnings') {
    $user = requireFamilyUser($db);
    $preferences = weekPreferenceRecord($db, $user);
    session_write_close();
    jsonResponse(weatherWarningsSnapshot($configuration, $preferences));
    exit;
}
if ($path === '/week-context') {
    $user = requireFamilyUser($db);
    $preferences = weekPreferenceRecord($db, $user);
    // Slow external providers must not hold the user's session lock.
    session_write_close();
    jsonResponse(weekContextSnapshot($configuration, $preferences));
    exit;
}
if ($path === '/profile/week-preferences') {
    $user = requireFamilyUser($db);
    jsonResponse(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        ? saveWeekPreferences($db, $configuration, $user, requestJson())
        : weekPublicPreferences(weekPreferenceRecord($db, $user)));
    exit;
}
if ($path === '/week-context/towns') {
    $user = requireFamilyUser($db);
    $term = $_GET['q'] ?? '';
    if (time() - (int)($_SESSION['town_search_at'] ?? 0) < 2) throw new HttpError(429, 'Please wait a moment before searching again.');
    $_SESSION['town_search_at'] = time();
    session_write_close();
    jsonResponse(weekTownSearch($configuration, $user, $term));
    exit;
}
if ($path === '/admin/activity') {
    $user = requireFamilyUser($db);
    jsonResponse(adminActivitySnapshot($db, $user, $_GET));
    exit;
}
if ($path === '/polls' || str_starts_with($path, '/polls/')) {
    $user = requireFamilyUser($db);
    if ($path === '/polls') familySnapshotResponse($db, $user, 'poll', fn() => pollsSnapshot($db, $user));
    else jsonResponse(mutatePoll($db, $user, substr($path, strlen('/polls/')), requestJson()));
    exit;
}
if ($path === '/notes' || str_starts_with($path, '/notes/')) {
    $user = requireFamilyUser($db);
    if ($path === '/notes') familySnapshotResponse($db, $user, 'personal_note', fn() => personalNotesSnapshot($db, $user));
    else jsonResponse(mutatePersonalNote($db, $user, substr($path, strlen('/notes/')), requestJson(100000)));
    exit;
}
if ($path === '/memories' || str_starts_with($path, '/memories/')) {
    $user = requireFamilyUser($db);
    if ($path === '/memories/photo') serveMemoryPhoto($db, $configuration);
    elseif ($path === '/memories') familySnapshotResponse($db, $user, 'memory', fn() => memoriesSnapshot($db, $user));
    else jsonResponse(mutateMemory($db, $configuration, $user, substr($path, strlen('/memories/')), memoryRequest()));
    exit;
}
if ($path === '/calendar' || str_starts_with($path, '/calendar/')) {
    $user = requireFamilyUser($db);
    if ($path === '/calendar') familySnapshotResponse($db, $user, 'calendar_event', fn() => calendarSnapshot($db));
    else jsonResponse(mutateCalendar($db, $user, substr($path, strlen('/calendar/')), requestJson()));
    exit;
}
if ($path === '/admin/members' || str_starts_with($path, '/admin/members/')) {
    $user = requireFamilyUser($db);
    jsonResponse($path === '/admin/members' ? adminMembersSnapshot($db, $user) :
        mutateAdminMember($db, $user, substr($path, strlen('/admin/members/')), requestJson()));
    exit;
}
if (in_array($path, ['/profile', '/profile/nickname', '/admin/users', '/admin/users/nickname'], true)) {
    $user = requireFamilyUser($db);
    if (str_starts_with($path, '/admin/')) requireFamilyAdmin($user);
    if ($path === '/profile') jsonResponse(['profile' => publicFamilyUser($user)]);
    elseif ($path === '/admin/users') jsonResponse(adminFamilyProfiles($db));
    else jsonResponse(changeFamilyNickname($db, $user, requestJson(), $path === '/admin/users/nickname'));
    exit;
}
if ($path === '/shopping' || str_starts_with($path, '/shopping/')) {
    $user = requireFamilyUser($db);
    if ($path === '/shopping') familySnapshotResponse($db, $user, 'shopping_item', fn() => shoppingSnapshot($db));
    else jsonResponse(mutateShopping($db, $user, substr($path, strlen('/shopping/')), requestJson()));
    exit;
}
if ($path === '/auth/session') jsonResponse(sessionResponse(currentFamilyUser($db)));
if ($path === '/auth/login') jsonResponse(loginFamilyUser($db, $configuration, requestJson()));
if ($path === '/auth/password') jsonResponse(changeFamilyPassword($db, $configuration, requestJson()));
if ($path === '/family') {
    $user = requireFamilyUser($db);
    jsonResponse(['message' => 'Welcome to your private family space.', 'user' => publicFamilyUser($user)]);
}
