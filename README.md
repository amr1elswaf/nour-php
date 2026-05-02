# Nour

A small framework on top of [OpenSwoole](https://openswoole.com/) for
building HTTP + WebSocket + webhook + timer backends in PHP. It ships
the request lifecycle (router, security pipeline, response helpers),
the WebSocket dispatch layer (rooms, IPC across workers, photo-URL
signing), connection pools (MySQL, Redis), batch helpers, a tiny DI
container, and a config-driven server bootstrap — and stops there.
Domain concerns (auth backend, ban policy, photo signing, payment
webhooks) are interfaces the host application implements.

The big idea: build the framework once, into a Docker image. Each
new service then ships only its own JSON config and PHP handler
classes — no Swoole boilerplate, no `server.php` to maintain.

---

## Status

`0.1.x-dev`. The contracts under [`src/Contracts/`](src/Contracts/)
are the public API surface and are stabilising — expect **breaking
changes** between dev releases until `0.x` lands. Everything under
`src/core/`, `src/Database/`, `src/helpers/` is the default
implementation and is allowed to evolve.

---

## Requirements

- PHP **8.1+**
- ext-openswoole (≥ 22.x)
- ext-redis
- ext-mysqli
- ext-openssl, ext-curl, ext-json (typically built-in)
- A bundled native extension `binary_json` (sources in
  [`src/extensions/php_binary_json/`](src/extensions/php_binary_json/)).
  Build with:
  ```bash
  cd src/extensions/php_binary_json
  phpize && ./configure && make
  cp modules/binary_json.so $(php-config --extension-dir)/
  echo "extension=binary_json.so" >> /etc/php/8.x/cli/conf.d/30-binary_json.ini
  ```
  The Redis `KeyValue` structures rely on it for compact serialisation.

---

## Install

```bash
composer require nour/framework
```

Or, for local development against a working copy of this repo, add a
path repository to your project's `composer.json`:

```json
{
  "repositories": [
    { "type": "path", "url": "F:/projects/nour" }
  ],
  "require": {
    "nour/framework": "*"
  }
}
```

---

## Quick start — one-line server

After `composer require`, the smallest possible Nour app is a project
with no `server.php` at all. Just JSON config and your handler
classes:

```
my-app/
├── composer.json
├── data/
│   ├── setup.json         ← ports + Swoole tuning + bootstrap class
│   ├── sitting.json       ← DB / Redis credentials, runtime settings
│   ├── FilesMap.json      ← HTTP / WebSocket route table
│   ├── Webhooks.json      ← webhook routes (port 9502)
│   └── Timers.json        ← background timers
├── app/
│   ├── Bootstrap.php      ← container bindings (3-line class)
│   ├── handlers/          ← static-method route handlers (App\handlers\…)
│   ├── classes/           ← business logic
│   └── Framework/         ← contract adapters (or copy from examples/)
└── vendor/
```

Run it:

```bash
NOUR_APP_DIR=$(pwd) php vendor/bin/server.php
```

That's it. The bundled `bin/server.php` reads `data/setup.json`,
spins up the Swoole listeners declared there, and hands off to your
`App\Bootstrap::register()`. No project-specific Swoole code anywhere.

If you'd rather keep an explicit `server.php` (e.g. to add custom
event hooks before boot), it's three lines:

```php
<?php
require __DIR__ . '/vendor/autoload.php';
\Nour\Server\Boot::run(__DIR__);
```

---

## `setup.json` — the only file the framework requires you to write

```json
{
    "name":      "myapp",
    "bootstrap": "App\\Bootstrap",

    "services": {
        "websocket": { "enabled": true,  "host": "0.0.0.0", "port": 9503 },
        "http":      { "enabled": true,  "host": "0.0.0.0", "port": 9501 },
        "webhook":   { "enabled": true,  "host": "0.0.0.0", "port": 9502 }
    },

    "swoole": {
        "worker_num":         8,
        "max_conn":           2000,
        "task_worker_num":    2,
        "package_max_length": 2097152
    },

    "runtime": {
        "memory_limit": "3G",
        "timezone":     "Africa/Cairo",
        "socket_key":   "myapp:socket_system"
    }
}
```

| Field | Meaning |
|---|---|
| `name` | Free-form app identifier (used in logs / banner only). |
| `bootstrap` | Fully-qualified class with a static `register()` method. Called once per worker before any request lands; this is where you bind the framework's contracts. |
| `services.*.enabled` | Toggle a service on / off. **Disable services you don't need** — disabling `websocket` for example saves the WS dispatcher + per-worker socket manager overhead. |
| `services.*.host` / `port` | Bind address. The first enabled service drives the Swoole server type (WebSocket vs HTTP); the rest attach as listeners on the same workers. |
| `swoole` | Pass-through to `$server->set(…)`. See OpenSwoole docs for the full list. |
| `runtime.memory_limit` | `ini_set('memory_limit', …)` per worker. |
| `runtime.timezone` | `date_default_timezone_set(…)`. |
| `runtime.socket_key` | Redis key prefix used by the WebSocket rooms registry. Make this app-specific so two Nour apps sharing one Redis instance don't collide. |

A starter template is in
[`data/setup.example.json`](data/setup.example.json) — copy to
`data/setup.json` and adjust.

---

## Adding a webhook (no framework edits)

1. Write a class implementing
   `Nour\Contracts\Webhook\WebhookHandlerInterface`:

   ```php
   namespace App\Webhook;
   use Nour\Contracts\Webhook\WebhookHandlerInterface;

   final class StripeWebhookHandler implements WebhookHandlerInterface {
       public function handle(array $payload, array $headers): bool {
           // verify $headers['stripe-signature'], do the work
           return true; // 200, false → 400
       }
   }
   ```

2. Register a route in `data/Webhooks.json`:

   ```json
   [
     { "path": "/webhook/stripe", "class": "App\\Webhook\\StripeWebhookHandler", "method": "POST" }
   ]
   ```

3. Restart workers. That's it. See
   [`src/README.md#webhooks`](src/README.md#webhooks) for the full
   request/response contract.

---

## The eight framework contracts

| Interface | Purpose |
|---|---|
| `Auth\UserResolverInterface`      | API key → user identity (cache + DB + revoke + touch) |
| `Auth\BanCheckerInterface`        | Full / community ban policy |
| `Media\PhotoSignerInterface`      | CDN URL signing (per-IP) |
| `Security\RateLimiterInterface`   | Per-API/IP throttle |
| `Security\BlacklistInterface`     | Persistent abuse lockouts |
| `Security\VerificationCacheInterface` | Cache fingerprint-validation verdicts |
| `Security\SecurityEventsInterface`| Audit log writer |
| `Webhook\WebhookHandlerInterface` | Process inbound webhook payloads |

Each interface ships with a working reference implementation in
[`examples/`](examples/) — these are the actual gooabb adapters,
copied verbatim. Use them as a starting point for your own.

---

## Docker image build pattern

Bake the framework + extensions + composer install into a base image,
then mount only the host project's code:

```dockerfile
FROM php:8.1-cli

# Install Swoole + ext-redis + ext-mysqli (your distro's preferred way)
RUN ...

# Bring in the framework + its native extension source
COPY framework/ /opt/nour/

# Build the binary_json native extension
RUN cd /opt/nour/src/extensions/php_binary_json \
    && phpize \
    && ./configure \
    && make \
    && make install \
    && docker-php-ext-enable binary_json

# Install framework's PHP deps once at image-build time
RUN cd /opt/nour && composer install --no-dev --optimize-autoloader

# At runtime, the host app gets mounted at /app and uses the framework's
# autoloader. NOUR_APP_DIR tells the bundled entry where to look for
# data/setup.json and vendor/autoload.php.
ENV NOUR_APP_DIR=/app
WORKDIR /app
ENTRYPOINT ["php", "/opt/nour/bin/server.php"]
```

A `docker-compose.yml` for the host project then looks like:

```yaml
services:
  myapp:
    image: registry.example/nour-base:0.1
    volumes:
      - ./data:/app/data
      - ./app:/app/app
      - ./vendor:/app/vendor
    ports:
      - "9501:9501"
      - "9502:9502"
      - "9503:9503"
    depends_on: [mariadb, redis]
```

The host service ships with **only its own** `data/`, `app/`, and
`vendor/` — the framework, native extensions, and entry script are
already in the image.

---

## Configuration cheat sheet

The framework reads optional values from `$GLOBALS['setting']` (loaded
from `data/sitting.json`):

| Path | Default | Effect |
|---|---|---|
| `cors.allowed_origins` | gooabb production + localhost | Origin allowlist for HTTP responses |
| `trusted_proxies` | Cloudflare IPv4/v6 ranges | IPs whose `X-Forwarded-For` / `CF-Connecting-IP` headers are honoured |
| `security.enforce_fingerprint` | `0` (soft mode) | When `1`, fingerprint validation failures reject the request instead of warn-and-pass |
| `framework.handler_namespace_prefix` | `App\handlers\` | Namespace prepended to route `file_path` entries |
| `framework.community_route_prefixes` | `[App\handlers\community\, App\handlers\sockets\]` | Class-name prefixes the ban checker treats as community/socket |

---

## Repository layout

```
nour/
├── README.md              ← you are here
├── composer.json
├── .gitignore
├── bin/
│   └── server.php         ← generic entry point for Docker images
├── data/
│   └── setup.example.json ← setup.json template
├── src/                   ← framework code (autoloads under Nour\)
│   ├── README.md          ← deeper architecture docs (read this next)
│   ├── runtime.php        ← global response helpers (auto-loaded by composer)
│   ├── Server/Boot.php    ← config-driven server bootstrap
│   ├── Container/
│   ├── Contracts/
│   ├── Exceptions/
│   ├── core/
│   ├── Database/
│   ├── helpers/
│   ├── extensions/php_binary_json/
│   └── config/offensive-names.json
└── examples/              ← reference adapters (NOT autoloaded)
    ├── Bootstrap.php
    ├── Auth/AppUserResolver.php
    ├── Auth/AppBanChecker.php
    ├── Media/BunnyPhotoSigner.php
    ├── Security/AppSecurityServices.php
    ├── Security/AppSecurityEvents.php
    └── Webhook/MyfatoorahWebhookHandler.php
```

The `examples/` directory is documentation. Files there carry the
`App\Framework\…` namespace from the gooabb deployment and reference
gooabb-specific helpers (`App\helpers\ApiHelper`,
`App\classes\community\ban\BanChecker`, etc.) — they will not autoload
in this package as-is. Copy them into your app, rename the namespace
to match your project, and replace the gooabb helper calls with your
own.

---

## Further reading

- [`src/README.md`](src/README.md) — in-tree architecture: directory
  responsibilities, the container, the eight contracts in detail,
  bootstrap order, and the webhook router.
- [`data/setup.example.json`](data/setup.example.json) — annotated
  setup template.
- The gooabb integration is the canonical reference deployment —
  every framework feature is exercised in production there.
