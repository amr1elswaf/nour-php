<?php

namespace  Nour\core\http;

use Nour\Container\App;
use Nour\Contracts\Auth\BanCheckerInterface;
use Swoole\Table;
use Exception;
use Swoole\Coroutine;

final class Router
{
    private static Table $routesTable;
    private static array $handlerCache = [];
    private static string $configPath;
    private static int $lastModified = 0;

    /**
     * تهيئة الروتر
     *
     * @param array<int, array<string, mixed>> $extraRoutes
     *   Provider-supplied routes (Phase 2 / Session 5). Each entry has
     *   the same shape as a row in `data/FilesMap.json`. Merged AFTER
     *   the JSON file, so a provider with the same `req` key overrides
     *   the JSON.
     */
    public static function init(?string $configPath = null, array $extraRoutes = []): void
    {
        self::$configPath = $configPath ?? __DIR__ . '/../../data/FilesMap.json';

        self::createRoutesTable();
        self::loadRoutes();
        if ($extraRoutes !== []) {
            self::loadExtraRoutes($extraRoutes);
        }
        self::preloadHandlers();

        echo "[Router] Initialized with " . self::$routesTable->count() . " routes\n";
    }

    private static function createRoutesTable(): void
    {
        self::$routesTable = new Table(2048);
        self::$routesTable->column('class', Table::TYPE_STRING, 200);
        self::$routesTable->column('auth_level', Table::TYPE_INT, 1);
        self::$routesTable->column('permissions', Table::TYPE_STRING, 100);
        self::$routesTable->column('is_static', Table::TYPE_INT, 1);
        self::$routesTable->column('is_community', Table::TYPE_INT, 1);
        self::$routesTable->column('bypass_ban', Table::TYPE_INT, 1);
        self::$routesTable->create();
    }

    private static function loadRoutes(): void
    {
        clearstatcache(true, self::$configPath);

        if (!file_exists(self::$configPath)) {
            throw new Exception("Routes config file not found: " . self::$configPath);
        }

        $content = file_get_contents(self::$configPath);
        $routesArray = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in routes config: " . json_last_error_msg());
        }

        // تنظيف الجدول القديم
        foreach (self::$routesTable as $key => $_) {
            self::$routesTable->del($key);
        }

        $loadedCount = 0;

        foreach ($routesArray as $route) {
            if (empty($route['req']) || empty($route['file_path'])) {
                continue;
            }

            $requestKey = $route['req'];
            $filePath = $route['file_path'];
            $authLevel = $route['pre'] ?? 1;
            $permissions = $route['up'] ?? '';

            $className = self::convertPathToClassName($filePath);

            if (!class_exists($className) || !method_exists($className, 'main')) {
                continue;
            }

            // التحقق إذا كانت الدالة static
            $isStatic = 0;
            try {
                $reflection = new \ReflectionMethod($className, 'main');
                $isStatic = $reflection->isStatic() ? 1 : 0;
            } catch (\ReflectionException $e) {
            }

            self::$routesTable->set($requestKey, [
                'class'        => $className,
                'auth_level'   => $authLevel,
                'permissions'  => is_array($permissions) ? implode(',', $permissions) : $permissions,
                'is_static'    => $isStatic,
                'is_community' => self::isCommunityRoute($className) ? 1 : 0,
                'bypass_ban'   => !empty($route['bypass_ban']) ? 1 : 0,
            ]);

            $loadedCount++;
        }

        self::$lastModified = filemtime(self::$configPath);
    }

    /**
     * Ingest provider-supplied routes (Phase 2 / Session 5). Same
     * row shape and validation as `loadRoutes()`, just sourced from
     * an in-memory list instead of a JSON file. Called AFTER
     * `loadRoutes()` so providers can override JSON entries by
     * sharing a `req` key.
     *
     * @param array<int, array<string, mixed>> $extraRoutes
     */
    private static function loadExtraRoutes(array $extraRoutes): void
    {
        $loadedCount = 0;
        foreach ($extraRoutes as $route) {
            if (empty($route['req']) || empty($route['file_path'])) {
                continue;
            }
            $requestKey  = (string) $route['req'];
            $filePath    = (string) $route['file_path'];
            $authLevel   = $route['pre'] ?? 1;
            $permissions = $route['up']  ?? '';

            $className = self::convertPathToClassName($filePath);
            if (!class_exists($className) || !method_exists($className, 'main')) {
                continue;
            }

            $isStatic = 0;
            try {
                $reflection = new \ReflectionMethod($className, 'main');
                $isStatic   = $reflection->isStatic() ? 1 : 0;
            } catch (\ReflectionException $e) {
            }

            self::$routesTable->set($requestKey, [
                'class'        => $className,
                'auth_level'   => $authLevel,
                'permissions'  => is_array($permissions) ? implode(',', $permissions) : (string) $permissions,
                'is_static'    => $isStatic,
                'is_community' => self::isCommunityRoute($className) ? 1 : 0,
                'bypass_ban'   => !empty($route['bypass_ban']) ? 1 : 0,
            ]);
            $loadedCount++;
        }
        if ($loadedCount > 0) {
            echo "[Router] +{$loadedCount} provider route(s)\n";
        }
    }

    /**
     * Translate a route's `file_path` into a fully-qualified class name.
     *
     * Three input shapes are accepted:
     *   1. Already-fully-qualified (`App\Plugins\Foo\BarHandler`) — used
     *      as-is. Detection is "contains at least one backslash AND the
     *      class autoloads"; misses fall through.
     *   2. Starts with the configured prefix — used as-is (legacy gooabb
     *      shape that already had the prefix baked in).
     *   3. Slash-separated path (`logs/login/Handler`) — converted to
     *      backslashes and the prefix prepended. Default prefix is
     *      `App\handlers\`; override via
     *      `$GLOBALS['setting']['framework']['handler_namespace_prefix']`.
     */
    private static function convertPathToClassName(string $filePath): string
    {
        $prefix = $GLOBALS['setting']['framework']['handler_namespace_prefix']
            ?? 'App\\handlers\\';

        // (2) starts with the configured prefix
        if (strpos($filePath, $prefix) === 0) {
            return $filePath;
        }

        // (1) already-FQCN — Phase 2 / Session 5: providers contribute
        // routes whose file_path is the full class name, since they
        // don't live under `App\handlers\`.
        if (strpos($filePath, '\\') !== false && class_exists($filePath)) {
            return $filePath;
        }

        // (3) gooabb-style slash path
        return $prefix . str_replace('/', '\\', $filePath);
    }

    private static function preloadHandlers(): void
    {
        self::$handlerCache = [];

        foreach (self::$routesTable as $key => $route) {
            $className = $route['class'];
            $isStatic = $route['is_static'];

            if (!$isStatic && !isset(self::$handlerCache[$className])) {
                try {
                    if (class_exists($className)) { // ✅ تحقق أولاً
                        self::$handlerCache[$className] = new $className();
                    }
                } catch (Exception $e) {
                    echo ($e->getMessage());
                }
            }
        }
    }
    /*
    

    
    */
    /**
     * تنفيذ الطلب - النسخة المبسطة
     */
    public static function dispatch(string $requestKey, $user, bool $check_auth = true): void
    {
        $route = self::$routesTable->get($requestKey);

        if (!$route) {
            //error("unfinded request");
            throw new Exception('Route not found: ' . $requestKey, 404);
        }


        if ($check_auth) {
            // 2. التحقق من المصادقة
            $requiredAuthLevel = $route['auth_level'];
            $userAuthLevel = self::calculateUserAuthLevel($user);
            if ($userAuthLevel < $requiredAuthLevel) {
                throw new Exception('Authentication required', 401);
            }

            // 3. التحقق من الصلاحيات
            if (!empty($route['permissions']) && !empty($user)) {
                self::checkPermissions($user, $route['permissions']);
            }

            // 4. التحقق من الحظر
            self::checkBan($user, $route);
        }


        // 4. تنفيذ الـ Handler
        $className = $route['class'];
        $isStatic = $route['is_static'];



        if ($isStatic) {
            $className::main();
        } else {
            $handler = self::getHandler($className);
            $handler->main();
        }
    }

    /**
     * Ban checks — runs after auth & permissions.
     *
     * full ban  → blocks every route for every role
     * all ban   → blocks community & socket routes only (Student / Teacher)
     *
     * Admin accounts are never blocked by 'all' (they need access to admin panel).
     * A 'full' ban on an Admin account is treated as an edge-case safety net.
     */
    private static function checkBan(?array $user, array $route): void
    {
        if (empty($user['id'])) {
            return;
        }

        $ctx   = Coroutine::getContext();
        $mysql = $ctx['mysql'] ?? null;

        if ($mysql === null) {
            return;
        }

        // Ban checking is an optional framework contract — if the host
        // app didn't bind one, treat all users as not-banned and skip.
        $banChecker = App::tryResolve(BanCheckerInterface::class);
        if ($banChecker === null) {
            return;
        }

        $userId   = $user['id'];
        $userRole = $user['role'] ?? '';

        // Routes a banned user MUST still reach — otherwise they can never
        // see why they're banned or contact support. Set `bypass_ban: 1`
        // in FilesMap.json on the corresponding entry to add to this list.
        if (!empty($route['bypass_ban'])) {
            return;
        }

        // 'full' ban → account completely disabled, blocks every route
        if ($banChecker->check($mysql, $userId, BanCheckerInterface::SCOPE_FULL) !== null) {
            error("account_disabled", 403);
        }

        // 'all' ban → blocks community & socket routes for non-admin users
        // is_community is pre-computed at load time — no string check here
        if ($userRole !== 'Admin' && $route['is_community'] === 1) {
            if ($banChecker->check($mysql, $userId, BanCheckerInterface::SCOPE_ALL) !== null) {
                error("account_restricted", 403);
            }
        }
    }

    /**
     * Computed once at loadRoutes() and stored in the Swoole Table.
     *
     * "Community" routes are the ones a user with an `all` ban (community
     * lockout) cannot reach. The classifier is a list of namespace prefixes
     * — by default the gooabb conventions, overridable via
     * `$GLOBALS['setting']['framework']['community_route_prefixes']` so
     * the framework is reusable in apps with different naming.
     */
    private static function isCommunityRoute(string $className): bool
    {
        /** @var list<string> $prefixes */
        $prefixes = $GLOBALS['setting']['framework']['community_route_prefixes']
            ?? ['App\\handlers\\community\\', 'App\\handlers\\sockets\\'];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private static function calculateUserAuthLevel(?array $user): int
    {
        if (empty($user)) {
            return 1;
        }
        return !empty($user['API']) ? 2 : 1;
    }

    private static function checkPermissions(array $user, string $requiredPermissions): void
    {
        $userRole = $user['role'] ?? null;

        if (empty($userRole)) {
            throw new Exception('User role not specified', 403);
        }

        $requiredPerms = explode(',', $requiredPermissions);
        if ($userRole === 'Admin') return; // bypass for admin
        if (!in_array($userRole, $requiredPerms, true) && !in_array('*', $requiredPerms, true)) {
            error('Insufficient permissions. User role: ' . $userRole .
                ', Required: ' . $requiredPermissions, 403);
        }
    }

    private static function getHandler(string $className): object
    {
        if (!isset(self::$handlerCache[$className])) {
            if (!class_exists($className)) {
                throw new Exception("Handler class not found: $className");
            }
            self::$handlerCache[$className] = new $className();
        }

        return self::$handlerCache[$className];
    }

    public static function getRoutes(): array
    {
        $routes = [];
        foreach (self::$routesTable as $key => $route) {
            $routes[$key] = [
                'class' => $route['class'],
                'auth_level' => $route['auth_level'],
                'permissions' => $route['permissions'],
                'is_static' => $route['is_static']
            ];
        }
        return $routes;
    }

    public static function hasRoute(string $requestKey): bool
    {
        return self::$routesTable->exists($requestKey);
    }
}
