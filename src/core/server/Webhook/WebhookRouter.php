<?php

declare(strict_types=1);

namespace Nour\core\server\Webhook;

use Nour\Container\App;
use Nour\Contracts\Webhook\WebhookHandlerInterface;
use Nour\Events\Http\WebhookProcessedEvent;
use RuntimeException;
use Swoole\HTTP\Request;
use Swoole\HTTP\Response;
use Throwable;

/**
 * Config-driven webhook dispatcher.
 *
 * Mirrors the way HTTP routes (`data/FilesMap.json`) and timers
 * (`data/Timers.json`) are wired: drop a row in `data/Webhooks.json`,
 * point it at a class implementing {@see WebhookHandlerInterface},
 * and the router picks it up at the next worker restart. No edits to
 * `server.php`, no `Bootstrap` change.
 *
 * ## Webhooks.json shape
 *
 * ```json
 * [
 *   {
 *     "path":   "/webhook/myfatoorah",
 *     "class":  "App\\Framework\\Webhook\\MyfatoorahWebhookHandler",
 *     "method": "POST"
 *   },
 *   {
 *     "path":   "/health",
 *     "class":  "App\\Framework\\Webhook\\HealthCheckHandler",
 *     "method": "GET"
 *   }
 * ]
 * ```
 *
 * - `path`   — exact match against `$request->server['request_uri']`.
 * - `class`  — fully-qualified class name. Must implement
 *              `WebhookHandlerInterface`. Instantiated once per worker
 *              and reused for every request.
 * - `method` — optional. Defaults to `"POST"`. Single string or
 *              comma-separated list (`"GET,POST"`).
 *
 * ## Response contract
 *
 * The router decodes the body once (JSON if `Content-Type` is JSON,
 * raw query+post otherwise) and hands `[$payload, $headers]` to the
 * handler. The handler returns `bool`: `true` → HTTP 200, `false` →
 * HTTP 400. The response body is a small JSON envelope —
 * `{ "ok": true|false, "path": "/…" }`. Handlers that need a custom
 * status / body should write directly to the response and throw
 * {@see HandlerWroteResponse} to short-circuit the default formatter.
 */
final class WebhookRouter
{
    /** @var array<string, array{class: string, methods: list<string>, instance: ?WebhookHandlerInterface}> */
    private static array $routes = [];

    private static bool $initialized = false;

    /**
     * Read the JSON config and instantiate handlers. Idempotent: a
     * second call replaces the previous registration.
     *
     * @param array<int, array<string, mixed>> $extraWebhooks
     *   Provider-supplied webhooks (Phase 2 / Session 5). Same row
     *   shape as the JSON file. Merged AFTER the JSON, so providers
     *   can override JSON entries by sharing the same `path`.
     */
    public static function init(string $configPath, array $extraWebhooks = []): void
    {
        $entries = [];
        if (file_exists($configPath)) {
            $raw = file_get_contents($configPath);
            $cfg = json_decode((string) $raw, true);
            if (!is_array($cfg)) {
                throw new RuntimeException("Invalid JSON in webhook config: {$configPath}");
            }
            foreach ($cfg as $entry) {
                if (is_array($entry)) $entries[] = $entry;
            }
        }
        foreach ($extraWebhooks as $entry) {
            if (is_array($entry)) $entries[] = $entry;
        }

        $routes = [];
        foreach ($entries as $entry) {
            $path  = isset($entry['path'])  ? (string) $entry['path']  : '';
            $class = isset($entry['class']) ? (string) $entry['class'] : '';
            if ($path === '' || $class === '') {
                error_log("[WebhookRouter] skipping invalid entry: " . json_encode($entry));
                continue;
            }
            if (!class_exists($class)) {
                error_log("[WebhookRouter] class not found: {$class}");
                continue;
            }
            if (!is_subclass_of($class, WebhookHandlerInterface::class)) {
                error_log("[WebhookRouter] {$class} does not implement WebhookHandlerInterface");
                continue;
            }
            $methods = self::parseMethods($entry['method'] ?? 'POST');
            $routes[$path] = [
                'class'    => $class,
                'methods'  => $methods,
                'instance' => null, // lazy
            ];
        }
        self::$routes      = $routes;
        self::$initialized = true;

        $jsonCount  = file_exists($configPath) ? count($entries) - count($extraWebhooks) : 0;
        $extraCount = count($extraWebhooks);
        echo "[WebhookRouter] loaded " . count($routes) . " route(s)"
            . ($extraCount > 0 ? " (incl. {$extraCount} from providers)" : "")
            . "\n";
    }

    /**
     * Dispatch a Swoole HTTP request. Writes the response itself —
     * 200/400 envelope on `bool` returns, 404 if no path matches,
     * 405 if the path matches but method does not, 500 on uncaught
     * handler exceptions, 503 if the router was never initialised.
     */
    public static function dispatch(Request $request, Response $response): void
    {
        if (!self::$initialized) {
            self::respond($response, 503, ['ok' => false, 'message' => 'Webhook router not initialised']);
            return;
        }

        $path   = (string) ($request->server['request_uri']    ?? '/');
        $method = strtoupper((string) ($request->server['request_method'] ?? 'GET'));
        $route  = self::$routes[$path] ?? null;

        // 404 / 405 don't fire WebhookProcessedEvent — no handler ran.
        // Apps that want to observe routing misses can listen on
        // RequestHandledEvent / write their own access log middleware.
        if ($route === null) {
            self::respond($response, 404, ['ok' => false, 'message' => 'Webhook path not found']);
            return;
        }

        if (!in_array($method, $route['methods'], true)) {
            $response->header('Allow', implode(', ', $route['methods']));
            self::respond($response, 405, ['ok' => false, 'message' => 'Method not allowed']);
            return;
        }

        // Lazy-instantiate the handler — first hit pays the cost,
        // subsequent requests reuse the same object.
        if ($route['instance'] === null) {
            try {
                $instance = new $route['class']();
            } catch (Throwable $e) {
                error_log("[WebhookRouter] failed to construct {$route['class']}: " . $e->getMessage());
                self::respond($response, 500, ['ok' => false, 'message' => 'Webhook construction failed']);
                return;
            }
            self::$routes[$path]['instance'] = $instance;
            $route['instance'] = $instance;
        }

        $payload   = self::decodePayload($request);
        $headers   = self::lowercaseHeaders($request->header ?? []);
        $startedAt = hrtime(true);
        $result    = null;
        $error     = null;

        try {
            $result = $route['instance']->handle($payload, $headers);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            error_log("[WebhookRouter] {$route['class']} threw: " . $error);
        }

        if ($error !== null) {
            self::respond($response, 500, ['ok' => false, 'message' => 'Webhook handler error']);
        } else {
            self::respond($response, $result ? 200 : 400, ['ok' => $result, 'path' => $path]);
        }

        self::fireProcessedEvent(
            $path, $method, $route['class'],
            $payload, $headers, $result, $error, $startedAt,
        );
    }

    /**
     * Fire the post-handler observer event. Best-effort — a misbehaving
     * dispatcher must not affect the handler's response.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    private static function fireProcessedEvent(
        string $path,
        string $method,
        string $handlerClass,
        array $payload,
        array $headers,
        ?bool $result,
        ?string $error,
        int $startedAt,
    ): void {
        try {
            $events = App::events();
        } catch (Throwable) {
            return; // Container not initialised yet — nothing to observe.
        }

        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
        try {
            $events->dispatch(new WebhookProcessedEvent(
                $path, $method, $handlerClass,
                $payload, $headers, $result, $error, $durationMs,
            ));
        } catch (Throwable $e) {
            error_log('[WebhookRouter] WebhookProcessedEvent dispatch threw: ' . $e->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private static function parseMethods(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        $out   = [];
        foreach ($parts as $p) {
            $p = strtoupper($p);
            if ($p !== '') $out[] = $p;
        }
        return $out !== [] ? $out : ['POST'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodePayload(Request $request): array
    {
        $contentType = (string) ($request->header['content-type'] ?? '');

        // JSON body
        if (str_contains($contentType, 'application/json')) {
            $raw = $request->rawContent();
            $decoded = json_decode((string) $raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        // Form / multipart → POST + GET fields
        $payload = [];
        if (!empty($request->post))  $payload = array_merge($payload, $request->post);
        if (!empty($request->get))   $payload = array_merge($payload, $request->get);
        return $payload;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function lowercaseHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[strtolower((string) $k)] = (string) $v;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function respond(Response $response, int $status, array $body): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->end(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, array{class: string, methods: list<string>}>
     */
    public static function getRoutes(): array
    {
        $out = [];
        foreach (self::$routes as $path => $route) {
            $out[$path] = ['class' => $route['class'], 'methods' => $route['methods']];
        }
        return $out;
    }
}
