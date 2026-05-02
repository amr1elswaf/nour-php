# Middleware

A request travels through a chain of middlewares before reaching
your handler. Each middleware can read the request, modify the
response, short-circuit the chain, or just pass through. The shape
is PSR-15-flavoured — same `(request, handler) → response` semantics
— but using Swoole's `Request` / `Response` types directly (no
PSR-7 bridge).

## Pipeline shape

```
incoming request
       │
       ▼
   ┌──────────────────────┐
   │ middleware A (pre)   │
   │   ┌────────────────┐ │
   │   │ middleware B   │ │
   │   │   ┌──────────┐ │ │
   │   │   │ terminal │ │ │   ← your RequestHandlerInterface
   │   │   │  handler │ │ │     (DefaultHttpRequestHandler etc.)
   │   │   └──────────┘ │ │
   │   │ middleware B   │ │
   │   │     (post)     │ │
   │   └────────────────┘ │
   │ middleware A (post)  │
   └──────────────────────┘
       │
       ▼
   response written
```

For `[A, B]` and a terminal `T`:

```
A.pre → B.pre → T.handle → B.post → A.post
```

The first middleware sees the request first AND the response last.
Middleware `B` only runs if `A` calls `$next->handle()`.

## Configuring the pipeline

`setup.json:services.http.middlewares` is a list of FQCNs:

```json
"http": {
    "enabled":     true,
    "host":        "0.0.0.0",
    "port":        9501,
    "handler":     "App\\Http\\MyHandler",
    "middlewares": [
        "Nour\\Http\\Middleware\\RequestIdMiddleware",
        "Nour\\Http\\Middleware\\CorsMiddleware",
        "Nour\\Http\\Middleware\\RateLimitMiddleware"
    ]
}
```

Order is significant — the first entry wraps the rest.

Each class is instantiated once per worker via `new $class()`. There
is no constructor-arg injection in the JSON; if you need configured
middleware, write a thin subclass with hardcoded args.

Boot logs confirm the wiring:

```
[Boot] HTTP handler: App\Http\MyHandler
[Boot] HTTP middlewares: 3 (Nour\Http\Middleware\RequestIdMiddleware,
                            Nour\Http\Middleware\CorsMiddleware,
                            Nour\Http\Middleware\RateLimitMiddleware)
```

## Built-in middlewares

All four live in `Nour\Http\Middleware\`. None are enabled by
default — opt in by listing them in `setup.json`.

### `RequestIdMiddleware`

Tags every response with `X-Request-Id`. Propagates the inbound
header if present (so request IDs flow through gateways), generates
a fresh 16-hex token otherwise.

```
"middlewares": ["Nour\\Http\\Middleware\\RequestIdMiddleware"]
```

Customizing header name / length:

```php
final class TenantIdMiddleware extends RequestIdMiddleware
{
    public function __construct() { parent::__construct('X-Tenant-Trace', 12); }
}
```

### `CorsMiddleware`

Reads `sitting.json:cors.allowed_origins` (exact-match
allowlist). On allowed origins, sets `Access-Control-Allow-Origin`
+ related headers; on `OPTIONS` preflights, replies 204 and
short-circuits the chain.

```json
"cors": {
    "allowed_origins": [
        "http://localhost:3000",
        "https://app.example.com"
    ]
}
```

Exact match only — no wildcards. For wildcard semantics, write your
own middleware that subclasses `CorsMiddleware` and overrides the
origin check.

### `RateLimitMiddleware`

Per-IP fixed-window rate limiter. Defaults to 60 requests per 60
seconds. Backed by Redis; fails-open when Redis is unreachable.

Adds three response headers on every successful request:

```
X-RateLimit-Limit:     60
X-RateLimit-Remaining: 47
X-RateLimit-Reset:     1777725720
```

When the cap is hit, returns HTTP 429 with `Retry-After` + the same
three headers. The body is `{"error":"rate limit exceeded","part":"rate_limit","code":"too_many_requests"}`.

Tighter limits on sensitive routes — write a subclass:

```php
final class StrictRateLimitMiddleware extends RateLimitMiddleware
{
    public function __construct() { parent::__construct('myapp:rl:auth', 5, 60); }
}
```

Then add it BEFORE the default in your pipeline. Both increment
independently — the strict one trips first for sensitive flows.

### `BlockIpMiddleware`

Rejects requests from IPs (or CIDR ranges) in the block-list. Backed
by `Nour\helpers\BlockIp` — same Redis store the
`nour ip:block` CLI command writes to.

> **Note:** The framework also runs an IP-block check in
> `HttpRequestHandleLogic::onRequest`, BEFORE the pipeline,
> applying to every port. `BlockIpMiddleware` is therefore
> redundant by default. It exists for apps that disable the global
> gate (e.g. by replacing the listener shim) or want IP-blocking
> only on certain pipelines.

## Writing your own middleware

Implement `Nour\Contracts\Http\MiddlewareInterface`:

```php
namespace App\Middleware;

use Nour\Contracts\Http\MiddlewareInterface;
use Nour\Contracts\Http\RequestHandlerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class TimingMiddleware implements MiddlewareInterface
{
    public function process(Request $r, Response $resp, RequestHandlerInterface $next): void
    {
        $start = hrtime(true);
        $next->handle($r, $resp);
        $ms = (hrtime(true) - $start) / 1_000_000;
        $resp->header('X-Response-Time-Ms', number_format($ms, 2));
    }
}
```

Common patterns:

### Short-circuit (auth gate)

```php
public function process(Request $r, Response $resp, RequestHandlerInterface $next): void
{
    if (!$this->isAllowed($r)) {
        $resp->status(401);
        $resp->end('{"error":"unauthorized"}');
        return; // do NOT call $next
    }
    $next->handle($r, $resp);
}
```

### Wrap-and-forward (request ID propagation)

```php
public function process(Request $r, Response $resp, RequestHandlerInterface $next): void
{
    $id = $r->header['x-request-id'] ?? bin2hex(random_bytes(8));
    $resp->header('X-Request-Id', $id);
    // attach to context so handler code can read it:
    \Swoole\Coroutine::getContext()['request_id'] = $id;
    $next->handle($r, $resp);
}
```

### Post-step (response-mutating)

```php
public function process(Request $r, Response $resp, RequestHandlerInterface $next): void
{
    $next->handle($r, $resp);
    // After downstream finished. Note: $resp->end() has already
    // been called by the handler — most response-mutating calls
    // here are no-ops in Swoole. Headers can still be appended
    // before end() returns; for true after-the-fact response
    // capture, wrap the Response itself in a custom class.
    $resp->header('X-Server-Region', 'eu-west-1');
}
```

## Picking middleware order

Defensive middlewares (auth, IP block, rate limit) go FIRST so
they bail before downstream work runs. Response-decorating
middlewares (request ID, server region, debug headers) usually go
LAST so they apply to all responses including ones early-returned
by other middlewares.

A reasonable default for a typical API:

```json
"middlewares": [
    "Nour\\Http\\Middleware\\RequestIdMiddleware",
    "Nour\\Http\\Middleware\\BlockIpMiddleware",
    "Nour\\Http\\Middleware\\RateLimitMiddleware",
    "Nour\\Http\\Middleware\\CorsMiddleware"
]
```

`RequestIdMiddleware` first → every response (including 429 and
403) carries an ID. `BlockIp` next (cheap O(1) Redis check),
then `RateLimit`, then `Cors` last — preflights handled by Cors
short-circuit have already passed the ID/block/rate steps.

## Building a pipeline programmatically

`Pipeline` is itself a `RequestHandlerInterface`, so you can
construct one for testing:

```php
$pipeline = new \Nour\Http\Pipeline(
    [new RequestIdMiddleware(), new RateLimitMiddleware()],
    new MyTerminalHandler(),
);

// Pipeline can be used anywhere a RequestHandlerInterface is expected.
$pipeline->handle($request, $response);
```

This is also useful when an app wants different pipelines for
different sub-paths — though Nour's flat router doesn't really do
sub-paths. If you need that, layer it inside your terminal handler.
