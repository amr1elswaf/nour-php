<?php

declare(strict_types=1);

namespace Nour\core\http;

use Nour\Container\App;
use Nour\Contracts\Http\RequestHandlerInterface;
use Nour\Database\SqlDatabase;
use Nour\Events\Http\RequestHandledEvent;
use Nour\Events\Http\RequestReceivedEvent;
use Nour\helpers\ClientIp;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;

/**
 * Default {@see RequestHandlerInterface} implementation — preserves the
 * framework's pre-v0.2 behaviour:
 *
 *  1. Resolve real client IP via {@see ClientIp}.
 *  2. CORS headers + preflight 204.
 *  3. Parse fingerprint header (depth-32 limited).
 *  4. Acquire MySQL (or null when disabled), call {@see Main::start}.
 *
 * This is what {@see \Nour\core\server\Http\HttpRequestHandleLogic::onRequest}
 * dispatches to when no `services.http.handler` is set in `setup.json`.
 *
 * IP block-list rejection happens earlier — in `HttpRequestHandleLogic::onRequest`
 * — so the gate applies to every port (HTTP and webhook) regardless of
 * which handler the app binds. This handler doesn't re-check; blocked
 * requests never reach `handle()`.
 *
 * ## Why this exists
 *
 * Splitting the per-request dispatch out of `HttpRequestHandleLogic`
 * lets apps **replace it** without subclassing the framework's listener
 * shim. The shim becomes a one-liner — `handler->handle($req, $resp)`
 * — and everything custom lives in the host app's class.
 *
 * ## Lifecycle events
 *
 * The handler fires {@see RequestReceivedEvent} at the very top (before
 * any framework-side work) and {@see RequestHandledEvent} at every exit
 * point. Listeners that need to short-circuit a request can write to
 * `$response` and call `stopPropagation()` on the received event — the
 * remaining lifecycle skips, but `RequestHandledEvent` still fires so
 * latency / metrics observers see every request.
 *
 * IP-blocked requests do NOT fire either event because they're rejected
 * before the handler runs. Apps that want to count blocked requests can
 * read counters directly from {@see \Nour\helpers\BlockIp}.
 */
final class DefaultHttpRequestHandler implements RequestHandlerInterface
{
    public function handle(Request $request, Response $response): void
    {
        $startedAt = hrtime(true);
        $headers   = $request->header ?? [];
        $ip        = ClientIp::fromRequest($request);
        $events    = App::events();

        // Pre-flight event — listeners can read the request, attach
        // attributes, or short-circuit by writing the response and
        // stopping propagation.
        $received = new RequestReceivedEvent($request, $response, $ip);
        try {
            $events->dispatch($received);
        } catch (Throwable $e) {
            // Dispatcher swallows listener throws, but a malformed
            // dispatcher implementation could surface one — don't take
            // the request down with it.
            error_log('[Http] RequestReceivedEvent dispatch threw: ' . $e->getMessage());
        }
        if ($received->isPropagationStopped()) {
            $this->fireHandled($events, $request, $response, $ip, $startedAt, null);
            return;
        }

        // Optional fingerprint header — used by Phase 3's auth pipeline
        // (gooabb's GooabbAuthPipeline) for security_level >= 2 routes.
        // Other apps that don't need it just don't send the header.
        $fingerprint = [];
        if (!empty($headers['fingerprint'])) {
            try {
                $fingerprint = json_decode($headers['fingerprint'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($fingerprint)) {
                    $fingerprint = [];
                }
            } catch (\JsonException) {
                $response->status(400);
                $response->end('Invalid fingerprint JSON data');
                $this->fireHandled($events, $request, $response, $ip, $startedAt, 400);
                return;
            }
        }

        $response->header('Content-Type', 'application/json; Charset=UTF-8');

        // CORS — allowlist read from sitting.json. Keeping it in the
        // default handler (rather than the listener shim) means apps
        // that swap handlers can decide their own CORS policy.
        $origin         = $headers['origin'] ?? '';
        $allowedOrigins = $GLOBALS['setting']['cors']['allowed_origins'] ?? [];
        if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Vary', 'Origin');
            $response->header('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, fingerprint');
        }
        if (strtoupper($request->server['request_method'] ?? '') === 'OPTIONS') {
            $response->status(204);
            $response->end();
            $this->fireHandled($events, $request, $response, $ip, $startedAt, 204);
            return;
        }

        $postData = $request->post ?? [];

        if (!SqlDatabase::isEnabled()) {
            Main::start($postData, null, $ip, $response, $fingerprint, $headers);
        } else {
            SqlDatabase::withConnection(function ($db) use ($postData, $response, $ip, $fingerprint, $headers) {
                Main::start($postData, $db, $ip, $response, $fingerprint, $headers);
            });
        }

        // Main::start writes its own status; we can't read it back
        // through Swoole's Response API, so report null. Latency-only
        // observers don't care; status-aware ones can intercept via a
        // Phase-3 middleware once that lands.
        $this->fireHandled($events, $request, $response, $ip, $startedAt, null);
    }

    private function fireHandled(
        \Nour\Contracts\Event\EventDispatcherInterface $events,
        Request $request,
        Response $response,
        string $ip,
        int $startedAt,
        ?int $status,
    ): void {
        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
        try {
            $events->dispatch(new RequestHandledEvent(
                $request, $response, $ip, $durationMs, $status,
            ));
        } catch (Throwable $e) {
            error_log('[Http] RequestHandledEvent dispatch threw: ' . $e->getMessage());
        }
    }
}
