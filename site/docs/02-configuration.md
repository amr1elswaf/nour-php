# Configuration

Nour reads two JSON files at boot. `setup.json` defines what runs
(services, ports, the bootstrap class, providers, the HTTP handler,
middlewares); `sitting.json` defines what runs **with** (databases,
Redis, CORS allowlist, application-level settings).

Both live under `data/` in the host project.

## `setup.json`

Top-level shape:

```json
{
    "name":      "myapp",
    "bootstrap": "App\\Bootstrap",
    "providers": [],
    "services": {
        "http":      { ... },
        "websocket": { ... },
        "webhook":   { ... }
    },
    "swoole":  { ... },
    "runtime": { ... }
}
```

### `name`

Free-form label. Currently surfaced only in boot logs.

### `bootstrap`

FQCN of a class with a `public static function register(): void`
method. Called once per worker, before routes/webhooks load.
Bind container contracts here, register event listeners, etc.

```php
namespace App;

use Nour\Container\App;
use Nour\Contracts\Auth\AuthPipelineInterface;

final class Bootstrap
{
    public static function register(): void
    {
        App::container()->bind(
            AuthPipelineInterface::class,
            new MyAuthPipeline(),
        );
    }
}
```

### `providers`

List of FQCNs implementing
`Nour\Contracts\Plugin\ProviderInterface`. Each is instantiated
once per worker; `register()` runs before routes load,
contributed routes/webhooks are merged, then `boot()` runs after
everything else. See [10-plugins.md](10-plugins.md).

```json
"providers": [
    "App\\Plugins\\Billing\\BillingProvider",
    "Vendor\\Pkg\\PkgProvider"
]
```

### `services`

Three independent transport listeners. Each can be enabled or
disabled. At least one must be enabled.

#### `services.http`

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

| Key | Type | Default | Notes |
|---|---|---|---|
| `enabled`     | bool   | — (req) | Listener is created only when true. |
| `host`        | string | `0.0.0.0` | Bind address. |
| `port`        | int    | `9501`    | Bind port. |
| `handler`     | string | (none)    | Custom `RequestHandlerInterface`; default is `Nour\core\http\DefaultHttpRequestHandler`. |
| `middlewares` | list   | `[]`      | Array of `MiddlewareInterface` FQCNs (see [04-middleware.md](04-middleware.md)). |

#### `services.websocket`

```json
"websocket": {
    "enabled":   true,
    "host":      "0.0.0.0",
    "port":      9503,
    "store":     "redis",
    "namespace": "myapp:ws"
}
```

| Key | Type | Default | Notes |
|---|---|---|---|
| `store`     | string | `redis` | One of `redis`, `memory`, or an FQCN implementing `SocketStoreInterface`. |
| `namespace` | string | `nour:sockets` | Redis prefix for `RedisSocketStore`. Ignored for `memory`. |

See [08-websocket.md](08-websocket.md).

#### `services.webhook`

```json
"webhook": {
    "enabled": true,
    "host":    "0.0.0.0",
    "port":    9502
}
```

Webhook routes are read from `data/Webhooks.json` (see
[09-webhooks-and-timers.md](09-webhooks-and-timers.md)).

### `swoole`

Passed verbatim to Swoole's `$server->set([...])`. Defaults the
framework applies:

```php
[
    'worker_num'              => 4,
    'enable_coroutine'        => true,
    'open_http_protocol'      => true,
    'open_websocket_protocol' => $isWebSocketServer,
    'log_file'                => '/tmp/nour.log',
    'log_level'               => SWOOLE_LOG_INFO,
]
```

Anything in `swoole` overrides those defaults. Common knobs:

| Key | What it does |
|---|---|
| `worker_num`             | HTTP/WS workers. Match CPU cores for typical apps. |
| `task_worker_num`        | Async task workers (used by `$server->task(...)`). |
| `task_enable_coroutine`  | Required for coroutine code in tasks. |
| `max_request`            | Worker recycles after N requests (memory hygiene). |
| `max_conn`               | Max concurrent connections. |
| `package_max_length`     | Max body size in bytes. |
| `buffer_output_size`     | Per-response output buffer. |

Full reference: [Swoole config docs](https://wiki.swoole.com/en/#/server/setting).

### `runtime`

```json
"runtime": {
    "memory_limit": "1G",
    "timezone":     "Africa/Cairo",
    "socket_key":   "myapp:socket_system"
}
```

| Key | Default | Notes |
|---|---|---|
| `memory_limit` | (php.ini) | Per-worker `memory_limit` ini override. |
| `timezone`     | `UTC` | `date_default_timezone_set` value. |
| `socket_key`   | `nour:socket_system` | Redis prefix the WS layer uses. Set per-app to avoid collisions when you run multiple Nour apps against one Redis. |

## `sitting.json`

Application-level settings — credentials, allowlists, business knobs.
`$GLOBALS['setting']` exposes the parsed map to handler code.

```json
{
    "db_api_user": {
        "enabled":  true,
        "host":     "mariadb",
        "port":     3306,
        "user":     "myapp",
        "password": "secret",
        "db":       "myapp"
    },

    "db_migrations": {
        "user":     "root",
        "password": "root",
        "db":       "myapp",
        "host":     "mariadb"
    },

    "postgres": {
        "enabled":  false,
        "host":     "postgres",
        "port":     5432,
        "user":     "myapp",
        "password": "secret",
        "db":       "myapp"
    },

    "redis": {
        "enabled":  true,
        "host":     "redis",
        "port":     6379,
        "scheme":   "tcp",
        "auth":     "",
        "timeout":  10
    },

    "cors": {
        "allowed_origins": [
            "http://localhost:3000",
            "https://app.example.com"
        ]
    },

    "framework": {
        "handler_namespace_prefix":  "App\\handlers\\",
        "community_route_prefixes":  ["App\\handlers\\community\\"]
    }
}
```

### Database blocks

Each DB section is opt-in via `enabled`. Missing or
`enabled: false` disables the pool — handlers that need it fail
loudly, which is the point.

- **`db_api_user`** — the runtime user. Should NOT have DDL
  grants in production.
- **`db_migrations`** — separate user the CLI's `nour migrate`
  uses. Falls back to `db_api_user` if not configured. Configure
  it distinctly so the runtime can't drop tables.
- **`postgres`** — `Nour\Database\PostgresDatabase` (PDO + pgsql).
- **`redis`** — `Nour\Database\RedisDatabase` (Swoole RedisPool).

### `cors.allowed_origins`

Read by `CorsMiddleware` and the legacy inline CORS handler.
Exact-match strings; no wildcards. Origins not in the list get no
CORS headers, so browsers block the request.

### `framework`

Optional knobs the framework reads:

| Key | Default | Notes |
|---|---|---|
| `framework.handler_namespace_prefix` | `App\handlers\` | Prefix prepended to slash-path `file_path` values in `FilesMap.json`. |
| `framework.community_route_prefixes` | `["App\\handlers\\community\\","App\\handlers\\sockets\\"]` | Routes whose class starts with one of these prefixes are flagged "community" — relevant when you've bound a `BanCheckerInterface` (community-scope ban). |

### App-specific keys

Anything else under `sitting.json` is yours. The framework reads
only the keys above; handlers can read whatever they want via
`$GLOBALS['setting']['my_app_key']`.

## `data/FilesMap.json`

Routing table — see [03-routing.md](03-routing.md) for full
details. Minimal entry:

```json
[
    {
        "file_path":      "logs/login/LoginHandler",
        "req":            "LOGIN",
        "pre":            1,
        "up":             [],
        "security_level": 1
    }
]
```

Auto-reloaded when the file's mtime changes — no worker restart
needed for new routes (handler classes still need to autoload, so
new PHP files DO need a restart).

## `data/Webhooks.json`

Webhook routing table — see [09-webhooks-and-timers.md](09-webhooks-and-timers.md):

```json
[
    {
        "path":   "/health",
        "class":  "App\\Webhook\\HealthHandler",
        "method": "GET,POST"
    }
]
```

## `data/Timers.json`

Background timer registrations — see [09-webhooks-and-timers.md](09-webhooks-and-timers.md):

```json
[
    { "path": "billing/CheckOverdue.php", "interval": 3600 }
]
```

## Environment variables

| Variable | What it does |
|---|---|
| `NOUR_APP_DIR` | Tells `bin/server.php` and `bin/nour` where the host app lives. The Docker image sets it to `/app`. |
| `NO_COLOR` | Disables ANSI colors in `bin/nour` output (any non-empty value). |
| `FORCE_COLOR` | Forces ANSI colors even when stdout isn't a TTY. |

## Hot reloading

- **`FilesMap.json`** — Router checks mtime each request; new
  entries are picked up without a worker restart. Class
  changes still need a restart (Swoole keeps autoload caches).
- **`Webhooks.json`** — Read once at worker start; new entries
  require a restart.
- **`Timers.json`** — Same as webhooks.
- **`sitting.json`** / **`setup.json`** — Boot only.

For development workflow, plain
`docker restart <container>` reloads everything in ~2 seconds.
