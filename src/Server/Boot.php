<?php

declare(strict_types=1);

namespace Nour\Server;

use Nour\Container\App as Framework;
use Nour\Contracts\Http\MiddlewareInterface;
use Nour\Contracts\Http\RequestHandlerInterface;
use Nour\Contracts\WebSocket\SocketStoreInterface;
use Nour\core\http\DefaultHttpRequestHandler;
use Nour\Database\PostgresDatabase;
use Nour\Database\RedisDatabase;
use Nour\Database\SqlDatabase;
use Nour\core\server\Http\HttpRequestHandleLogic;
use Nour\core\server\Websocket\WebsocketRequestHandleLogic;
use Nour\core\socket\GlobalRegistry;
use Nour\Http\Pipeline;
use Nour\Plugin\PluginLoader;
use Nour\WebSocket\InMemorySocketStore;
use Nour\WebSocket\RedisSocketStore;
use RuntimeException;
use Swoole\Http\Server as HttpServer;
use Swoole\WebSocket\Server as WebSocketServer;

/**
 * Nour application bootstrap.
 *
 * Reads `data/setup.json` + `data/sitting.json` from the host project,
 * spins up Swoole listeners according to the declared services, wires
 * the framework's request lifecycle, and calls the host app's
 * `Bootstrap` class to register container bindings.
 *
 * The host's `server.php` becomes a three-liner:
 *
 * ```php
 * require __DIR__ . '/vendor/autoload.php';
 * \Nour\Server\Boot::run(__DIR__);
 * ```
 *
 * Everything else is configuration.
 *
 * ## setup.json shape
 *
 * ```json
 * {
 *   "name":      "myapp",
 *   "bootstrap": "App\\Bootstrap",
 *   "services": {
 *     "websocket": { "enabled": true,  "host": "0.0.0.0", "port": 9503 },
 *     "http":      { "enabled": true,  "host": "0.0.0.0", "port": 9501 },
 *     "webhook":   { "enabled": true,  "host": "0.0.0.0", "port": 9502 }
 *   },
 *   "swoole": { ... Swoole server settings ... },
 *   "runtime": {
 *     "memory_limit": "3G",
 *     "timezone":     "Africa/Cairo",
 *     "socket_key":   "myapp:socket_system"
 *   }
 * }
 * ```
 *
 * - At least one service must be enabled.
 * - When `websocket.enabled = true` the main server is a
 *   `Swoole\WebSocket\Server`; otherwise it falls back to
 *   `Swoole\Http\Server` on the first enabled service's port.
 * - Additional services attach as Swoole *listeners* on the same
 *   process (sharing workers).
 */
final class Boot
{
    private function __construct() {}

    public static function run(string $mainFolder): void
    {
        $mainFolder = rtrim($mainFolder, "/\\");
        $GLOBALS['main_folder'] = $mainFolder;

        $setting = self::loadJson("{$mainFolder}/data/sitting.json", required: true);
        $setup   = self::loadJson("{$mainFolder}/data/setup.json",   required: true);

        $GLOBALS['setting']    = $setting;
        $GLOBALS['socket_key'] = $setup['runtime']['socket_key'] ?? 'nour:socket_system';

        self::applyRuntime($setup['runtime'] ?? []);

        // Each database is opt-in via `enabled: false` in sitting.json.
        // Missing blocks count as disabled too — apps that don't need
        // a particular DB just don't configure it. The `init()` calls
        // are safe to invoke unconditionally; they short-circuit when
        // disabled.
        SqlDatabase::init();
        RedisDatabase::init();
        PostgresDatabase::init();

        if (!SqlDatabase::isEnabled() && !RedisDatabase::isEnabled() && !PostgresDatabase::isEnabled()) {
            echo "[Boot] No database/cache enabled. Handlers that need one will fail per-request.\n";
        }

        $services = self::servicesFrom($setup);
        $main     = self::pickMainService($services);

        $server = self::buildServer($main, $services);
        self::applySwooleSettings($server, $setup['swoole'] ?? []);
        self::wireEvents($server, $setup);

        $bannerLines = self::banner($main, $services);
        echo implode("\n", $bannerLines) . "\n";

        $server->start();
    }

    // ── Loading ───────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function loadJson(string $path, bool $required): array
    {
        if (!file_exists($path)) {
            if ($required) {
                throw new RuntimeException("Boot: required config not found: {$path}");
            }
            return [];
        }
        $raw = file_get_contents($path);
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Boot: invalid JSON in {$path}: " . json_last_error_msg());
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $runtime
     */
    private static function applyRuntime(array $runtime): void
    {
        if (!empty($runtime['memory_limit'])) {
            ini_set('memory_limit', (string) $runtime['memory_limit']);
        }
        date_default_timezone_set((string) ($runtime['timezone'] ?? 'UTC'));
    }

    // ── Service resolution ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $setup
     * @return array<string, array{name: string, host: string, port: int}>
     *         keyed by service name (`websocket` | `http` | `webhook`),
     *         only enabled services included.
     */
    private static function servicesFrom(array $setup): array
    {
        $defaults = [
            'websocket' => ['host' => '0.0.0.0', 'port' => 9503],
            'http'      => ['host' => '0.0.0.0', 'port' => 9501],
            'webhook'   => ['host' => '0.0.0.0', 'port' => 9502],
        ];

        $configured = $setup['services'] ?? [];
        $enabled    = [];
        foreach ($defaults as $name => $defaultCfg) {
            $cfg = $configured[$name] ?? [];
            if (empty($cfg['enabled'])) {
                continue;
            }
            $enabled[$name] = [
                'name' => $name,
                'host' => (string) ($cfg['host'] ?? $defaultCfg['host']),
                'port' => (int)    ($cfg['port'] ?? $defaultCfg['port']),
            ];
        }

        if ($enabled === []) {
            throw new RuntimeException(
                'Boot: setup.json has no enabled services. '
                . 'Enable at least one of: websocket, http, webhook.'
            );
        }
        return $enabled;
    }

    /**
     * The "main" service drives the Swoole server type. WebSocket beats
     * HTTP (since WebSocketServer can also serve HTTP); webhook can
     * stand alone too if it's the only thing enabled.
     *
     * @param array<string, array{name: string, host: string, port: int}> $services
     * @return array{name: string, host: string, port: int}
     */
    private static function pickMainService(array $services): array
    {
        if (isset($services['websocket'])) return $services['websocket'];
        if (isset($services['http']))      return $services['http'];
        return reset($services); // webhook-only mode
    }

    // ── Server build ──────────────────────────────────────────────────

    /**
     * @param array{name: string, host: string, port: int} $main
     * @param array<string, array{name: string, host: string, port: int}> $services
     */
    private static function buildServer(array $main, array $services): HttpServer|WebSocketServer
    {
        $isWebsocket = $main['name'] === 'websocket';

        /** @var HttpServer|WebSocketServer $server */
        $server = $isWebsocket
            ? new WebSocketServer($main['host'], $main['port'])
            : new HttpServer($main['host'], $main['port']);

        // The main server already covers `$main`; attach listeners for the rest.
        foreach ($services as $name => $cfg) {
            if ($name === $main['name']) {
                continue;
            }
            $listener = $server->addListener($cfg['host'], $cfg['port'], SWOOLE_SOCK_TCP);
            $listener->set([
                'open_http_protocol'      => true,
                'open_websocket_protocol' => false,
            ]);
            // Both http and webhook listeners route through the same
            // dispatcher; it discriminates by `request_uri` / port.
            $listener->on('request', [HttpRequestHandleLogic::class, 'onRequest']);
        }

        return $server;
    }

    /**
     * @param array<string, mixed> $swoole
     */
    private static function applySwooleSettings(HttpServer|WebSocketServer $server, array $swoole): void
    {
        $defaults = [
            'worker_num'              => 4,
            'enable_coroutine'        => true,
            'open_http_protocol'      => true,
            'open_websocket_protocol' => $server instanceof WebSocketServer,
            'log_file'                => '/tmp/nour.log',
            'log_level'               => SWOOLE_LOG_INFO,
        ];
        $server->set(array_merge($defaults, $swoole));
    }

    // ── Event wiring ──────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $setup
     */
    private static function wireEvents(HttpServer|WebSocketServer $server, array $setup): void
    {
        $bootstrapClass    = (string) ($setup['bootstrap'] ?? '');
        $httpHandler       = (string) ($setup['services']['http']['handler']      ?? '');
        $httpMiddlewares   = (array)  ($setup['services']['http']['middlewares'] ?? []);
        $providerClasses   = (array)  ($setup['providers'] ?? []);
        $wsStoreSpec       = (string) ($setup['services']['websocket']['store']     ?? 'redis');
        $wsNamespace       = (string) ($setup['services']['websocket']['namespace'] ?? '');

        $server->on('workerStart', function ($srv, $workerId) use ($bootstrapClass, $httpHandler, $httpMiddlewares, $providerClasses, $wsStoreSpec, $wsNamespace) {
            // Coroutine hooks — exclude CURL because Swoole is typically
            // built without --enable-openssl on the gooabb image.
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_CURL);

            // ⭐ Phase 2 (S1): bind the SocketStoreInterface based on
            // setup.json:services.websocket.store. The framework's
            // WS path resolves the store from the container, so the
            // host app gets to pick the backend.
            $store = match (true) {
                $wsStoreSpec === 'redis'  => new RedisSocketStore($wsNamespace),
                $wsStoreSpec === 'memory' => new InMemorySocketStore(),
                class_exists($wsStoreSpec) && is_subclass_of($wsStoreSpec, SocketStoreInterface::class)
                    => new $wsStoreSpec(),
                default => throw new RuntimeException(
                    "Boot: invalid services.websocket.store value: '{$wsStoreSpec}'. "
                    . "Expected 'redis', 'memory', or a class implementing SocketStoreInterface."
                ),
            };
            Framework::container()->bind(SocketStoreInterface::class, $store);

            // Run the host app's container bootstrap before anything
            // else — the rest of the boot uses contracts it bound.
            if ($bootstrapClass !== '') {
                if (!class_exists($bootstrapClass)) {
                    throw new RuntimeException("Boot: bootstrap class not found: {$bootstrapClass}");
                }
                if (!method_exists($bootstrapClass, 'register')) {
                    throw new RuntimeException("Boot: {$bootstrapClass}::register() not callable");
                }
                $bootstrapClass::register();
            }

            // ⭐ Phase 2 (S5): instantiate providers and run their
            // register() hooks BEFORE Router::init / WebhookRouter::init
            // so collectRoutes()/collectWebhooks() are populated when
            // the routers initialise.
            //
            // Reset ensures workers that share state (rare, but possible
            // under certain Swoole reload modes) start with a clean
            // provider list.
            PluginLoader::reset();
            PluginLoader::loadAll($providerClasses);

            // ⭐ Phase 4: register a custom HTTP request handler if
            // setup.json declared one. Otherwise the listener shim
            // falls back to DefaultHttpRequestHandler.
            //
            // ⭐ Phase 2 (S3): also wrap the terminal handler in a
            // middleware pipeline if `services.http.middlewares` is
            // set. The pipeline IS a RequestHandlerInterface, so the
            // listener shim's `App::tryResolve(RequestHandlerInterface)`
            // gets a Pipeline instance and the rest of the wiring is
            // unchanged.
            $terminal = $httpHandler !== ''
                ? self::buildTerminalHandler($httpHandler)
                : new DefaultHttpRequestHandler();

            if ($httpHandler !== '') {
                echo "[Boot] HTTP handler: {$httpHandler}\n";
            }

            if ($httpMiddlewares !== []) {
                $instances = self::buildMiddlewareInstances($httpMiddlewares);
                $pipeline  = new Pipeline($instances, $terminal);
                Framework::container()->bind(RequestHandlerInterface::class, $pipeline);
                echo "[Boot] HTTP middlewares: " . count($instances) . " ("
                    . implode(', ', array_map('get_class', $instances)) . ")\n";
            } elseif ($httpHandler !== '') {
                // No middlewares, but a custom handler — bind it directly.
                Framework::container()->bind(RequestHandlerInterface::class, $terminal);
            }
            // Else: no custom handler, no middlewares. The shim already
            // falls back to a fresh DefaultHttpRequestHandler per worker.

            HttpRequestHandleLogic::workerStart($srv, $workerId);
            GlobalRegistry::initialize($srv);
            GlobalRegistry::initializeWorker();

            if ($srv instanceof WebSocketServer) {
                WebsocketRequestHandleLogic::initialize($srv);
            }

            // ⭐ Phase 2 (S5): boot() runs LAST — every provider has
            // completed register(), the host's Bootstrap has run, and
            // routes/webhooks have loaded. Container resolution is safe.
            PluginLoader::bootAll();
        });

        $server->on('start', function ($srv) {
            HttpRequestHandleLogic::onStart($srv);
        });

        // Main service routes
        if ($server instanceof WebSocketServer) {
            $server->on('handshake', [WebsocketRequestHandleLogic::class, 'onHandshake']);
            $server->on('open',      [WebsocketRequestHandleLogic::class, 'onOpen']);
            $server->on('message',   [WebsocketRequestHandleLogic::class, 'onMessage']);
            $server->on('close',     [WebsocketRequestHandleLogic::class, 'onClose']);
            $server->on('pipeMessage', function ($srv, $src, $msg) {
                WebsocketRequestHandleLogic::onWorkerMessage($srv, $msg);
            });

            // The WS server may also receive plain HTTP on its port (stats /
            // health). Hand those off to the same dispatcher.
            $server->on('request', [HttpRequestHandleLogic::class, 'onRequest']);
        } else {
            $server->on('request', [HttpRequestHandleLogic::class, 'onRequest']);
        }

        $server->on('task',   [HttpRequestHandleLogic::class, 'onTask']);
        $server->on('finish', function ($srv, $taskId, $data) {});
    }

    /**
     * Resolve a class name from `services.http.handler` to a
     * concrete {@see RequestHandlerInterface} instance, raising if the
     * class is missing or doesn't implement the interface.
     */
    private static function buildTerminalHandler(string $className): RequestHandlerInterface
    {
        if (!class_exists($className)) {
            throw new RuntimeException("Boot: HTTP handler class not found: {$className}");
        }
        if (!is_subclass_of($className, RequestHandlerInterface::class)) {
            throw new RuntimeException(
                "Boot: {$className} must implement " . RequestHandlerInterface::class
            );
        }
        $instance = new $className();
        return $instance;
    }

    /**
     * Resolve `services.http.middlewares` (a list of class names) to
     * concrete {@see MiddlewareInterface} instances, preserving order.
     *
     * @param array<int, mixed> $list
     * @return list<MiddlewareInterface>
     */
    private static function buildMiddlewareInstances(array $list): array
    {
        $out = [];
        foreach ($list as $i => $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new RuntimeException(
                    "Boot: services.http.middlewares[{$i}] must be a non-empty class name."
                );
            }
            if (!class_exists($entry)) {
                throw new RuntimeException("Boot: middleware class not found: {$entry}");
            }
            if (!is_subclass_of($entry, MiddlewareInterface::class)) {
                throw new RuntimeException(
                    "Boot: {$entry} must implement " . MiddlewareInterface::class
                );
            }
            $out[] = new $entry();
        }
        return $out;
    }

    // ── Banner ────────────────────────────────────────────────────────

    /**
     * @param array{name: string, host: string, port: int} $main
     * @param array<string, array{name: string, host: string, port: int}> $services
     * @return list<string>
     */
    private static function banner(array $main, array $services): array
    {
        $lines = ['========================================'];
        foreach ($services as $name => $cfg) {
            $tag = match ($name) {
                'websocket' => '🔗 WebSocket',
                'http'      => '🚀 HTTP',
                'webhook'   => '📊 Webhook',
                default     => "  {$name}",
            };
            $lines[] = sprintf('%-15s: %s://%s:%d', $tag, $name === 'websocket' ? 'ws' : 'http', $cfg['host'], $cfg['port']);
        }
        $lines[] = "(main: {$main['name']})";
        $lines[] = '========================================';
        return $lines;
    }
}
