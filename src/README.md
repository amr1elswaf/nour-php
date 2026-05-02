# Nour — A Mini Framework on top of OpenSwoole

Nour is the minimal HTTP + WebSocket + worker framework that powers the
gooabb backend. It is intentionally small: it ships routing, request
context, a security pipeline, a WebSocket dispatch layer, Redis/MySQL
connection pools, batch helpers, and a tiny service container — and
nothing else. Every domain concern (user auth backend, ban policy,
photo signing, payment webhooks) is delegated to interfaces the host
application implements.

This document is the quick tour. It exists so a new contributor can
land in `lib/nour/` and know which directory does what without
reverse-engineering 38 files.

---

## Layout

```
lib/nour/
├── Container/             # Service container (DI)
│   ├── ContainerInterface.php
│   ├── Container.php
│   └── App.php             # static facade over the per-worker container
├── Contracts/             # Interfaces the host app implements
│   ├── Auth/
│   │   ├── UserResolverInterface.php
│   │   └── BanCheckerInterface.php
│   ├── Media/
│   │   └── PhotoSignerInterface.php
│   ├── Security/
│   │   ├── RateLimiterInterface.php
│   │   ├── BlacklistInterface.php
│   │   ├── VerificationCacheInterface.php
│   │   └── SecurityEventsInterface.php
│   └── Webhook/
│       └── WebhookHandlerInterface.php
├── Exceptions/            # Framework-level exceptions
│   ├── FrameworkException.php (abstract)
│   ├── BindingNotFoundException.php
│   └── BindingResolutionException.php
├── Database/              # MySQL + Redis pools, base data access class
├── Database/redis/        # Redis structures (KeyValue, Queue, SocketRooms)
├── core/http/             # Main, Router, Security, FingerprintVerifier
├── core/socket/           # GlobalRegistry, MessageDispatcher, SocketManager,
│                          # SocketMain, MessageManger, WebSocketWorker
├── core/server/           # Swoole HTTP/WS request handlers
├── core/Timers.php        # background timer registry
└── helpers/               # IsValidSomething, GenerateTokens, ClientIp,
                           # NameValidator, GetInfo, sql/BatchInsertHelper
```

The cardinal rule: **nothing under `lib/nour/` may `use` an `App\…`
class.** Domain concerns live behind a `Contracts/` interface and the
host app provides the implementation through the container.

---

## The container

Bind concrete implementations once per worker, in `workerStart`:

```php
use Nour\Container\App;
use Nour\Contracts\Auth\UserResolverInterface;

App::container()->bind(
    UserResolverInterface::class,
    new App\Framework\Auth\AppUserResolver()
);
```

Resolve from anywhere inside the framework:

```php
$resolver = App::resolve(UserResolverInterface::class);
$user = $resolver->resolveByApiKey($mysql, $apiKey);
```

Lazy factory closures are supported — they run once on first `get()`
and the result is memoized:

```php
App::container()->bind(
    ExpensiveServiceInterface::class,
    fn () => new ExpensiveService($a, $b, $c)
);
```

Optional contracts that the framework can degrade without:

```php
$signer = App::tryResolve(PhotoSignerInterface::class);
if ($signer !== null) {
    $url = $signer->sign($url, $ip);
}
```

`BindingNotFoundException` is thrown for missing required contracts;
`BindingResolutionException` wraps any error a factory closure raises.

---

## The seven framework contracts

| Interface | Used by | What the host app does |
|---|---|---|
| `Auth\UserResolverInterface` | `Security`, `WebsocketRequestHandleLogic` | Resolve API key → user identity (with cache + DB fallback) |
| `Auth\BanCheckerInterface` | `Router::checkBan` | Decide if a user is `full`/`all` banned |
| `Media\PhotoSignerInterface` | `MessageDispatcher`, `GlobalRegistry` | Sign CDN URLs against a recipient's IP |
| `Security\RateLimiterInterface` | `Security::start` | Per-API/IP request throttling |
| `Security\BlacklistInterface` | `Security::start` | Persistent abuse lockouts |
| `Security\VerificationCacheInterface` | `Security::start` | Cache fingerprint-validation verdicts |
| `Security\SecurityEventsInterface` | `Security::start` | Audit log for security events |
| `Webhook\WebhookHandlerInterface` | `WebhookRouter` (port 9502) | Process incoming webhooks — see [Webhooks](#webhooks) below |

Implementations live in the host app (e.g., `lib/app/Framework/`) and
are wired in a single `Bootstrap::register()` call. See
[`lib/app/Framework/Bootstrap.php`](../app/Framework/Bootstrap.php)
for the gooabb wiring; copy and adapt for new deployments.

---

## Configuration touch-points

The framework reads a small set of optional values from
`$GLOBALS['setting']` (loaded from `data/sitting.json` at boot):

| Path | Default | Effect |
|---|---|---|
| `cors.allowed_origins` | `[gooabb production + localhost defaults]` | CORS allowlist for HTTP responses |
| `trusted_proxies` | Cloudflare IP ranges | Sources whose `X-Forwarded-For` / `CF-Connecting-IP` headers are honoured |
| `security.enforce_fingerprint` | `0` (soft mode) | When `1`, fingerprint validation failures reject the request instead of warn-and-pass |
| `framework.handler_namespace_prefix` | `App\handlers\` | Namespace prefix prepended to route `file_path` entries |
| `framework.community_route_prefixes` | `[App\handlers\community\, App\handlers\sockets\]` | Class-name prefixes the ban checker treats as community/socket routes |

---

## Webhooks

Webhooks are registered through `data/Webhooks.json`, in the same
config-driven style as HTTP routes (`FilesMap.json`) and timers
(`Timers.json`). Adding a new webhook is three steps and zero edits to
`server.php` or `Bootstrap`:

1. **Write a handler.** A class implementing
   {@see Contracts\Webhook\WebhookHandlerInterface}:

   ```php
   namespace App\Framework\Webhook;

   use Nour\Contracts\Webhook\WebhookHandlerInterface;

   final class StripeWebhookHandler implements WebhookHandlerInterface
   {
       public function handle(array $payload, array $headers): bool
       {
           // verify signature with $headers['stripe-signature']
           // do the work
           return true; // 200, false → 400
       }
   }
   ```

2. **Register a route.** Append a row to `data/Webhooks.json`:

   ```json
   {
     "path":   "/webhook/stripe",
     "class":  "App\\Framework\\Webhook\\StripeWebhookHandler",
     "method": "POST"
   }
   ```

   `method` accepts a single verb (`"POST"`) or a comma-separated
   list (`"GET,POST"`). Defaults to `POST` when omitted.

3. **Restart the worker** so the router re-reads the config:

   ```bash
   docker restart nour_php_app
   ```

The router lives at port `9502`. Behaviour:

| Outcome | HTTP | Body |
|---|---|---|
| Handler returns `true` | 200 | `{"ok":true,"path":"/…"}` |
| Handler returns `false` | 400 | `{"ok":false,"path":"/…"}` |
| Path not in config | 404 | `{"ok":false,"message":"Webhook path not found"}` |
| Method mismatch | 405 (with `Allow:`) | `{"ok":false,"message":"Method not allowed"}` |
| Handler throws | 500 | `{"ok":false,"message":"Webhook handler error"}` |
| Router not initialised | 503 | `{"ok":false,"message":"Webhook router not initialised"}` |

The framework decodes the body once and hands `($payload, $headers)`
to the handler:

- `Content-Type: application/json` → `$payload` is the decoded JSON.
- Anything else → `$payload` is `$_POST + $_GET` merged.
- `$headers` keys are lowercased (`'stripe-signature'`, not
  `'Stripe-Signature'`).

A bundled `/health` handler ships in `data/Webhooks.json` for
liveness probes — keep it, replace it, or remove the entry; the
framework doesn't depend on it.

---

## Bootstrap order (per worker)

In `workerStart`, the boot is:

```php
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_CURL);

App\Framework\Bootstrap::register();          // 1. bind contracts
HttpRequestHandleLogic::workerStart(...);      // 2. init pools, Router (FilesMap.json),
                                               //    Security, WebhookRouter (Webhooks.json),
                                               //    Redis scripts
GlobalRegistry::initialize($server);           // 3. WS dispatcher
GlobalRegistry::initializeWorker();            // 4. WS worker (worker_id 0 only)
WebsocketRequestHandleLogic::initialize(...);  // 5. per-worker socket manager
```

Step 1 must run first — the rest of the boot uses the container.

The three config files loaded in step 2 / by other timers:

| File | What it registers | Read by |
|---|---|---|
| `data/FilesMap.json`   | HTTP + WebSocket routes | `Router::init`, `Security::_init_` |
| `data/Webhooks.json`   | Webhook routes (port 9502) | `WebhookRouter::init` |
| `data/Timers.json`     | Background timers | `Timers::init` |

---

## Replacing an implementation

To swap the user resolver for a custom one (testing, alternative
backend, etc.):

```php
// Before HttpRequestHandleLogic::workerStart() runs:
App::container()->bind(
    UserResolverInterface::class,
    new MyCustomResolver()
);
```

The framework picks up the new binding on the next request. No code
inside `lib/nour/` changes.

---

## Status

Today the framework is bundled with its host app (`gooabb_server/nour`)
and uses the same composer autoloader. The next step in the long-term
plan is to extract `lib/nour/` to its own composer package
(`nour/framework`) once the contracts have stabilised. The contracts
folder is the public API surface; everything under `core/` is the
default implementation and is allowed to evolve.
