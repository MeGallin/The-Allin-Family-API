<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/database.php';
require __DIR__ . '/src/auth.php';
require __DIR__ . '/src/activity.php';
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

set_exception_handler(static function (Throwable $error): void {
    if ($error instanceof HttpError) {
        jsonResponse(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->status);
    } else {
        error_log(get_class($error) . ': ' . $error->getMessage());
        jsonResponse(['error' => 'The service is temporarily unavailable. Please try again.'], 500);
    }
});

$configuration = loadConfiguration(__DIR__ . '/.env');
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
if ($path === '/admin/activity') {
    $user = requireFamilyUser($db);
    jsonResponse(adminActivitySnapshot($db, $user, $_GET));
    exit;
}
if ($path === '/polls' || str_starts_with($path, '/polls/')) {
    $user = requireFamilyUser($db);
    jsonResponse($path === '/polls' ? pollsSnapshot($db, $user) : mutatePoll($db, $user, substr($path, strlen('/polls/')), requestJson()));
    exit;
}
if ($path === '/notes' || str_starts_with($path, '/notes/')) {
    $user = requireFamilyUser($db);
    jsonResponse($path === '/notes' ? personalNotesSnapshot($db, $user) : mutatePersonalNote($db, $user, substr($path, strlen('/notes/')), requestJson(100000)));
    exit;
}
if ($path === '/memories' || str_starts_with($path, '/memories/')) {
    $user = requireFamilyUser($db);
    if ($path === '/memories/photo') serveMemoryPhoto($db, $configuration);
    else jsonResponse($path === '/memories' ? memoriesSnapshot($db, $user) : mutateMemory($db, $configuration, $user, substr($path, strlen('/memories/')), memoryRequest()));
    exit;
}
if ($path === '/calendar' || str_starts_with($path, '/calendar/')) {
    $user = requireFamilyUser($db);
    jsonResponse($path === '/calendar' ? calendarSnapshot($db) : mutateCalendar($db, $user, substr($path, strlen('/calendar/')), requestJson()));
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
    jsonResponse($path === '/shopping' ? shoppingSnapshot($db) : mutateShopping($db, $user, substr($path, strlen('/shopping/')), requestJson()));
    exit;
}
if ($path === '/auth/session') jsonResponse(sessionResponse(currentFamilyUser($db)));
if ($path === '/auth/login') jsonResponse(loginFamilyUser($db, $configuration, requestJson()));
if ($path === '/auth/password') jsonResponse(changeFamilyPassword($db, $configuration, requestJson()));
if ($path === '/family') {
    $user = requireFamilyUser($db);
    jsonResponse(['message' => 'Welcome to your private family space.', 'user' => publicFamilyUser($user)]);
}
