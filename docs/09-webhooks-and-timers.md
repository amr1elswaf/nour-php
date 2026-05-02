# Webhooks & Timers

Two config-driven extension points that don't go through the
HTTP `req` router. Webhooks listen on a separate port for path-based
HTTP calls (typical use: payment provider callbacks, health checks).
Timers run periodic background work inside Swoole workers.

## Webhooks

A webhook is an HTTP endpoint identified by URL path (not by `req`
key). The framework provides a separate listener (port 9502 by
default), a config-driven router, and a small interface your
handlers implement.

### Configuration

Enable the webhook listener in `setup.json`:

```json
"services": {
    "webhook": { "enabled": true, "host": "0.0.0.0", "port": 9502 }
}
```

Register handlers in `data/Webhooks.json`:

```json
[
    {
        "path":   "/webhook/myfatoorah",
        "class":  "App\\Webhook\\MyfatoorahHandler",
        "method": "POST"
    },
    {
        "path":   "/health",
        "class":  "App\\Webhook\\HealthHandler",
        "method": "GET,POST"
    }
]
```

| Field | Notes |
|---|---|
| `path` | Exact match against `request_uri`. No path parameters. |
| `class` | FQCN implementing `WebhookHandlerInterface`. |
| `method` | Optional. Defaults to `POST`. Comma-separated for multiple (`"GET,POST"`). |

### Handler contract

```php
namespace App\Webhook;

use Nour\Contracts\Webhook\WebhookHandlerInterface;

final class HealthHandler implements WebhookHandlerInterface
{
    /**
     * @param array<string, mixed>  $payload Decoded body (JSON or merged GET+POST)
     * @param array<string, string> $headers Lower-cased header map
     */
    public function handle(array $payload, array $headers): bool
    {
        return true;
    }
}
```

The framework decodes the body once and hands it to you:

- `Content-Type: application/json` → `json_decode($body, true)`
- form-encoded / multipart → merged `$request->post + $request->get`

The handler returns `bool`:

| Return | Response |
|---|---|
| `true` | HTTP 200 with body `{ "ok": true, "path": "/…" }` |
| `false` | HTTP 400 with body `{ "ok": false, "path": "/…" }` |
| throws | HTTP 500 with body `{ "ok": false, "message": "Webhook handler error" }` (the throw is `error_log`ged; the message is generic to avoid leaking internals) |

For custom response shapes (different status codes, redirect,
HTML), don't return `bool` — write directly to the response and
throw `HandlerWroteResponse` to short-circuit the framework's
formatter. (The base interface still requires the `bool` return,
but the throw lets you bypass it.)

### Lifecycle

1. Worker boot reads `Webhooks.json`, validates each row, instantiates
   each handler lazily (first request).
2. Inbound request to `:9502/<path>`:
   - Path matched against the registry → 404 if no match.
   - Method validated → 405 with `Allow:` header if mismatched.
   - Body decoded, headers lower-cased.
   - `handler->handle($payload, $headers)` runs.
   - Response written based on return / throw.
3. `Nour\Events\Http\WebhookProcessedEvent` fires AFTER the response
   is written — observability hooks can attach there. Doesn't fire
   for 404/405.

### Adding a webhook

1. Write the handler class implementing `WebhookHandlerInterface`.
2. Add a row to `data/Webhooks.json`.
3. Restart the workers (`docker restart <container>`). Webhooks
   don't hot-reload like routes do.
4. Smoke test:
   ```bash
   curl -i http://localhost:9502/health
   ```

### Listing webhooks

```bash
docker exec <container> php /opt/nour/bin/nour webhook:list
```

Prints both JSON-defined and provider-supplied webhooks (from
plugins) in a single table.

### Common patterns

#### Idempotency

Webhook providers retry. Cache the request body's hash in Redis
with a TTL longer than the retry window:

```php
public function handle(array $payload, array $headers): bool
{
    $sigHash = hash('sha256', json_encode($payload));
    $key     = 'myapp:wh:idem:' . $sigHash;

    $alreadySeen = false;
    \Nour\Database\RedisDatabase::withConnection(function ($r) use ($key, &$alreadySeen) {
        $alreadySeen = !$r->set($key, '1', ['NX', 'EX' => 3600]);
    });
    if ($alreadySeen) return true;   // we already processed this

    // do the real work
    return true;
}
```

#### Signature verification

Read the raw body via the closure (the decoded `$payload` won't
preserve byte order needed for some HMAC schemes). The framework
hands you `$payload` already decoded; for raw-body access you'd need
to subclass / replace the router. (For most providers, decoded
JSON works fine.)

## Timers

Periodic work runs as Swoole timers inside workers. Each entry in
`data/Timers.json` registers a closure that fires every N seconds.

### Configuration

```json
[
    { "path": "billing/CheckOverdue.php",    "interval": 3600 },
    { "path": "messages/messageInsert.php", "interval": 1 }
]
```

| Field | Notes |
|---|---|
| `path` | Resolved against `lib/timers/<path>` in the host project. |
| `interval` | Seconds. Floors at 1. |

### Wrapper file convention

Each timer file MUST `return` a closure. The closure runs once per
tick. Convention: thin shim that acquires a connection from the
pool and calls a static `main()` on a class in `App\Timers\…`.

`lib/timers/billing/CheckOverdue.php`:

```php
<?php
use App\Timers\OverdueChecker;
use Nour\Database\SqlDatabase;
use Swoole\Database\MysqliProxy;

return function () {
    SqlDatabase::withConnection(function (MysqliProxy $db) {
        OverdueChecker::main($db);
    });
};
```

### The `is_running` Atomic guard

The framework calls each closure on every tick — even if the
previous tick is still running. Without a guard, a slow tick stacks
up coroutines until the worker dies. The convention is a per-class
`Swoole\Atomic` flag plus try/finally:

```php
namespace App\Timers;

use Swoole\Atomic;
use Swoole\Database\MysqliProxy;

final class OverdueChecker
{
    public static ?Atomic $is_running = null;

    public static function main(MysqliProxy $db): void
    {
        if (!self::$is_running)        self::$is_running = new Atomic(0);
        if (self::$is_running->get())  return;       // previous tick still running
        self::$is_running->set(1);

        try {
            // … real work …
        } finally {
            self::$is_running->set(0);                // ALWAYS reset
        }
    }
}
```

`finally` is mandatory. If the body throws or hangs and the worker
gets killed, the Atomic stays at `1` forever in that worker, and
subsequent ticks short-circuit silently. Always wrap the work in
try/finally.

### TimerTickedEvent

The framework wraps each tick in a try/catch and fires
`Nour\Events\TimerTickedEvent` after the closure returns:

```php
App::events()->addListener(
    TimerTickedEvent::class,
    static function (TimerTickedEvent $e): void {
        if ($e->error !== null) {
            error_log("[timer] {$e->path}: {$e->error}");
        }
        if ($e->durationMs > $e->intervalMs * 0.5) {
            error_log("[timer] {$e->path} slow: {$e->durationMs}ms / {$e->intervalMs}ms");
        }
    }
);
```

See [05-events.md](05-events.md).

### Adding a timer

1. Write the timer class in `lib/app/Timers/<Name>.php` with the
   Atomic guard.
2. Write the wrapper in `lib/timers/<area>/<file>.php` that
   `return`s a closure.
3. Add a row to `data/Timers.json`.
4. Restart the workers.

### Listing timers

```bash
docker exec <container> php /opt/nour/bin/nour timer:list
```

### Typical timer pattern: enqueue + drain

Hot paths shouldn't write to MySQL on the request hot path —
enqueue to Redis instead and let a timer drain in batches.

```php
// In a request handler:
\Nour\Database\redis\Structures\Queue::enqueue('myapp:notif:queue', $payload);

// In the timer (lib/timers/notif/drain.php):
return function () {
    SqlDatabase::withConnection(function ($db) {
        NotifDrainer::main($db);
    });
};

// App\Timers\NotifDrainer::main:
$rows = Queue::dequeueMultiple('myapp:notif:queue', 200);
if ($rows === []) return;
BatchInsertHelper::insert_batch($db, ...);
```

### Worker timing — caveat for plugins

Timers register in worker processes, so they fire from each
worker independently. If you need a "run once across the cluster"
guarantee, gate the body with a Redis lock:

```php
public static function main($db): void
{
    $acquired = false;
    \Nour\Database\RedisDatabase::withConnection(function ($r) use (&$acquired) {
        $acquired = (bool) $r->set('myapp:timer:lock', '1', ['NX', 'EX' => 60]);
    });
    if (!$acquired) return;        // another worker has the lock
    // … work …
}
```

### Disabling a timer

Comment out the row in `Timers.json`, or set its file to a closure
that returns immediately. Restart workers to clear the registration.
