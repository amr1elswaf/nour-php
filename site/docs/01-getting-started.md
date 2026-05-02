# Getting Started

A working Nour server in ~5 minutes. We'll build the framework
Docker image, scaffold a tiny app, hit one route, and tear down.

## Prerequisites

- **Docker** + **Docker Compose** (Linux containers).
- **MariaDB** + **Redis** containers if you want to exercise DB /
  cache features. Both are optional — the framework boots fine
  without them; handlers that need them just fail per-request.
- **PHP 8.1+** locally if you want to run `composer` against the host
  app's `composer.json` (otherwise the Docker image runs Composer
  inside a Linux container).

## 1. Pull the framework image

The official image is published on Docker Hub at
[`amr1elswaf/nour`](https://hub.docker.com/r/amr1elswaf/nour). It
already has OpenSwoole, the framework source at `/opt/nour`, the
framework's `vendor/` baked in, and `bin/server.php` /
`bin/nour` ready to run.

```bash
docker pull amr1elswaf/nour:latest
```

> **Building from source instead?** If you're modifying the
> framework, clone the repo and `docker build -t nour:latest .` —
> see [12-deployment.md](12-deployment.md). For app development
> the published image is what you want.

## 2. Scaffold a host app

A "host app" is the directory that gets mounted into the image at
`/app`. Minimum required files:

```
my-app/
├── data/
│   ├── setup.json       # ports, services, bootstrap class
│   ├── sitting.json     # databases, redis, cors
│   └── FilesMap.json    # routes (can be empty list `[]`)
├── lib/
│   └── app/
│       └── Bootstrap.php  # registers container bindings
└── composer.json        # PSR-4 autoload for App\ namespace
```

### `composer.json`

```json
{
    "name": "myapp/server",
    "type": "project",
    "require": { "php": "^8.1" },
    "autoload": {
        "psr-4": { "App\\": "lib/app/" }
    }
}
```

Run `composer install` once to generate `vendor/autoload.php`.

### `data/setup.json`

```json
{
    "name":      "myapp",
    "bootstrap": "App\\Bootstrap",
    "services": {
        "http": { "enabled": true, "host": "0.0.0.0", "port": 9501 }
    },
    "swoole": {
        "worker_num":     2,
        "max_request":    1000
    },
    "runtime": {
        "memory_limit": "512M",
        "timezone":     "UTC"
    }
}
```

See [02-configuration.md](02-configuration.md) for every option.

### `data/sitting.json`

```json
{
    "db_api_user": { "enabled": false },
    "redis":       { "enabled": false }
}
```

Disabling both DB and Redis means handlers that don't need them
work, and ones that do fail loudly. Enable them later when you need
them — see [07-databases.md](07-databases.md).

### `data/FilesMap.json`

```json
[
    {
        "file_path":      "App\\PingHandler",
        "req":            "PING",
        "pre":            1,
        "up":             [],
        "security_level": 1
    }
]
```

`req` is the inbound key clients send; `file_path` is the handler's
fully-qualified class name (or a slash-path like `community/feed/Handler`
that resolves to `App\handlers\community\feed\Handler`).

### `lib/app/Bootstrap.php`

```php
<?php
namespace App;

final class Bootstrap
{
    public static function register(): void
    {
        // Bind contracts here. For now, we don't need anything.
    }
}
```

### `lib/app/PingHandler.php`

```php
<?php
namespace App;

use Swoole\Coroutine;

final class PingHandler
{
    public static function main(): void
    {
        $ctx  = Coroutine::getContext();
        $data = $ctx['data'] ?? null;

        echo_data([
            'pong'  => true,
            'ts'    => time(),
            'echo'  => is_object($data) ? (array) $data : $data,
        ]);
    }
}
```

`echo_data()` is a global helper from Nour's `opertion.php` (loaded
at boot). It writes a JSON response and short-circuits the handler.

## 3. Run the server

```bash
docker run --rm --name myapp \
    -p 9501:9501 \
    -v $(pwd):/app \
    amr1elswaf/nour:latest
```

You should see boot output like:

```
[Boot] HTTP middlewares: 0 (none)
[Worker #0] Starting...
[Router] Initialized with 1 routes
[Worker #0] Ready
========================================
🚀 HTTP          : http://0.0.0.0:9501
(main: http)
========================================
```

## 4. Send a request

```bash
curl -s -X POST http://127.0.0.1:9501 \
    -F 'req=PING' \
    -F 'data={"name":"world"}'
```

Expected:

```json
{"pong":true,"ts":1777724326,"echo":{"name":"world"}}
```

## Request shape primer

Every Nour request is `POST /` (path is irrelevant — the dispatcher
keys off `req`), `multipart/form-data`, with three fields:

| Field | Required | Notes |
|---|---|---|
| `req`  | yes | Uppercase route key (matches `FilesMap.json:req`). |
| `data` | yes | JSON-encoded payload — the **string**, not the object. |
| `API`  | no  | Auth token. Routes with `pre: 2` reject requests without it. |

The framework parses `data` to a `stdClass` and exposes it via
`Coroutine::getContext()['data']`. See [03-routing.md](03-routing.md)
for the full handler contract.

## 5. Tear down

```bash
docker stop myapp
```

The container has `--rm` so it cleans itself up.

## Where next

- **[02-configuration.md](02-configuration.md)** — turn on the DB,
  Redis, WebSocket, webhooks, providers.
- **[03-routing.md](03-routing.md)** — convention for writing
  handlers, role-gated routes, ban policies.
- **[04-middleware.md](04-middleware.md)** — wrap requests with
  CORS, rate limiting, etc.
- **[11-cli.md](11-cli.md)** — `nour list` shows every operational
  command (`route:list`, `migrate`, `ip:block`, …).

## Common first-run problems

- **`bind() failed: Address already in use`** — another process is
  using port 9501. Pick a different `services.http.port` in
  `setup.json`.
- **`Boot: required config not found: data/setup.json`** — the
  `-v` mount doesn't point at a directory containing `data/`.
  Check your `docker run` command.
- **`Class App\… not found`** — composer's autoload isn't generated.
  Run `composer install` (or `composer dump-autoload`) on the host
  to refresh `vendor/composer/autoload_psr4.php`.
- **Empty response, exit code 0** — your handler probably forgot to
  call `echo_data()` or `error()`. Without one, Swoole returns the
  empty body.
