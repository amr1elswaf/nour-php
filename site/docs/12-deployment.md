# Deployment

How to ship a Nour app to a production box. The framework is
distributed as a Docker image
([`amr1elswaf/nour`](https://hub.docker.com/r/amr1elswaf/nour) on
Docker Hub) — your app code mounts at `/app`. This doc covers the
image, environment expectations, hardening, and operational basics.

## Architecture target

A Nour deployment is typically:

```
[Cloudflare / nginx]
        │
        ├── HTTPS:443 → API endpoint
        │       │
        │       ▼ (proxy to Swoole)
        │    nour_php_app container
        │       ├── 9501  HTTP (req-routed JSON)
        │       ├── 9502  Webhook (path-routed)
        │       └── 9503  WebSocket
        │
        ├── mariadb container (port 3306, internal)
        ├── redis container (port 6379, internal)
        └── (optional) postgres / phpmyadmin / etc.
```

OpenResty / nginx terminates TLS, applies upload-size limits,
proxies to the Swoole container. Swoole serves plain HTTP/WS
internally — no SSL inside the container.

> **Important:** The OpenSwoole image Nour ships against is built
> WITHOUT `--enable-openssl`. `Swoole\Coroutine\Http\Client`
> cannot do HTTPS. For outbound HTTPS use libcurl
> (`curl_init`/`curl_exec`). This is documented per-callsite — see
> [07-databases.md](07-databases.md).

## Pulling the image

The published image is hosted at
[`amr1elswaf/nour`](https://hub.docker.com/r/amr1elswaf/nour). Pull
the latest tag:

```bash
docker pull amr1elswaf/nour:latest
```

For production, **pin to a specific tag** rather than `latest` so a
new framework release can't surprise you mid-deploy:

```bash
docker pull amr1elswaf/nour:0.2.0
```

The image:

- Base: `openswoole-php` with Swoole, Redis, mysqli, msgpack
  extensions.
- Framework source at `/opt/nour` with `vendor/` baked in (no
  Composer install at runtime).
- `NOUR_APP_DIR=/app` set as default — your host project mounts
  there.
- Default `CMD` is `/opt/nour/bin/server.php` — starts the Swoole
  server. Override with `bin/nour <command>` for one-shot CLI work.

### Building from source

Only needed if you're modifying the framework. Clone
[the repo](https://hub.docker.com/r/amr1elswaf/nour) and:

```bash
docker build -t nour:local .
```

The Dockerfile:

```dockerfile
FROM openswoole-php:latest

COPY . /opt/nour

RUN if ! command -v composer >/dev/null; then  \
        curl -sS https://getcomposer.org/installer \
            | php -- --install-dir=/usr/local/bin --filename=composer ; \
    fi \
    && cd /opt/nour \
    && composer install --no-dev --optimize-autoloader --no-interaction

ENV NOUR_APP_DIR=/app
WORKDIR /app

CMD ["/opt/nour/bin/server.php"]
```

For a custom base image (different PHP version, additional
extensions), edit the `FROM` line and rebuild. See
`Dockerfile.example` for variations. After building, push to your
own registry (`docker tag` + `docker push`) and reference that tag
in your deployments.

## Running

The runtime mount is the host app folder:

```bash
docker run -d \
    --name myapp_server \
    --network mynet \
    --restart unless-stopped \
    -p 9501:9501 -p 9502:9502 -p 9503:9503 \
    -v /opt/myapp:/app \
    amr1elswaf/nour:latest
```

The Docker network (`mynet` here) should also include `mariadb` and
`redis` containers so internal hostnames resolve.

### Running with Compose

```yaml
services:
  app:
    image: amr1elswaf/nour:latest
    container_name: myapp_server
    restart: unless-stopped
    networks: [mynet]
    ports:
      - "9501:9501"
      - "9502:9502"
      - "9503:9503"
    volumes:
      - ./:/app
    depends_on:
      - mariadb
      - redis

  mariadb:
    image: mariadb:11.8.5
    container_name: mariadb
    restart: unless-stopped
    networks: [mynet]
    environment:
      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASS}
      MARIADB_DATABASE:      myapp
    volumes:
      - mariadb-data:/var/lib/mysql

  redis:
    image: redis:alpine
    container_name: redis
    restart: unless-stopped
    networks: [mynet]
    command: ["redis-server", "--appendonly", "yes"]
    volumes:
      - redis-data:/data

networks:
  mynet:
    driver: bridge

volumes:
  mariadb-data:
  redis-data:
```

## Production tuning

### `setup.json:swoole`

Sensible production defaults:

```json
"swoole": {
    "worker_num":            8,
    "task_worker_num":       4,
    "task_enable_coroutine": true,
    "max_request":           5000,
    "max_conn":              10000,
    "package_max_length":    8388608,
    "buffer_output_size":    8388608,
    "log_file":              "/var/log/nour/swoole.log",
    "log_level":             1
}
```

| Knob | Picking |
|---|---|
| `worker_num` | Match CPU cores. More doesn't help once Swoole is CPU-bound. |
| `max_request` | Worker recycles every N requests. Catches memory leaks. |
| `max_conn` | Concurrent connection limit. Raise for WS-heavy workloads. |
| `package_max_length` | Max body size in bytes. Default `2 MB`; raise for upload endpoints. |
| `task_worker_num` | Set if you use `$server->task(...)`. Otherwise 0. |

### `setup.json:runtime.memory_limit`

Per-worker `memory_limit`. `1G` is generous for most apps; if your
handlers leak, tune `max_request` down rather than raising this.

### `sitting.json` pool sizes

Each pool defaults to ~32 connections. Raise via:

```json
"db_api_user": {
    "enabled":   true,
    "host":      "mariadb",
    "user":      "myapp",
    "password":  "secret",
    "db":        "myapp",
    "pool_size": 64
},
"redis": {
    "enabled":   true,
    "host":      "redis",
    "pool_size": 64
}
```

A pool too small starves coroutines that wait for connections
under load. Too big and you waste memory. Watch
`SHOW PROCESSLIST` on MySQL during load — if you're hitting the
cap, raise. If most are idle, lower.

## Reverse-proxy template (OpenResty / nginx)

```nginx
server {
    listen 443 ssl http2;
    server_name api.example.com;

    ssl_certificate     /etc/letsencrypt/live/api.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.example.com/privkey.pem;

    # API
    location /v1/ {
        proxy_pass http://127.0.0.1:9501/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_buffering off;
        client_max_body_size 8M;
    }

    # Webhooks
    location /webhook/ {
        proxy_pass http://127.0.0.1:9502/webhook/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }

    # WebSocket upgrade
    location /ws/ {
        proxy_pass http://127.0.0.1:9503/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 300s;
    }
}
```

`X-Forwarded-For` is consumed by `Nour\helpers\ClientIp` — but
ONLY if the proxy IP is in `sitting.json:trusted_proxies`. Add
your reverse-proxy / Cloudflare ranges:

```json
"trusted_proxies": [
    "127.0.0.1",
    "172.18.0.0/16",
    "173.245.48.0/20"
]
```

Without this, the framework treats `X-Forwarded-For` as untrusted
input and uses the direct connection IP — which is your proxy's IP,
not the client's.

## Database hardening

### Two users, two grants

The runtime user (`db_api_user`) should have minimal rights:

```sql
CREATE USER 'myapp'@'%' IDENTIFIED BY '…';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON myapp.* TO 'myapp'@'%';
-- explicitly NO CREATE, ALTER, DROP, INDEX, REFERENCES
```

The migrations user (`db_migrations`) gets DDL:

```sql
CREATE USER 'myapp_mig'@'%' IDENTIFIED BY '…';
GRANT ALL ON myapp.* TO 'myapp_mig'@'%';
```

`sitting.json` lists both:

```json
"db_api_user":   { "user": "myapp",     "password": "…", "db": "myapp", "host": "mariadb" },
"db_migrations": { "user": "myapp_mig", "password": "…", "db": "myapp", "host": "mariadb" }
```

The runtime can never DROP a table even if the framework is
compromised. `nour migrate` uses the migrations user only when
explicitly invoked.

### Backup

Migrations don't replace backups. Snapshot mariadb-data nightly
(`docker exec mariadb mariadb-dump …` piped to a backup volume)
even with the migration runner in place.

## Logging

Swoole writes to `swoole.log` (configurable via `setup.json:swoole.log_file`).
PHP `error_log` calls go to the container's stdout — pickable by
`docker logs`.

For aggregation (CloudWatch, ELK, etc.), tail container stdout to
your log forwarder. The framework's `Nour\helpers\AwsLogger` (when
configured) batches calls into CloudWatch Logs:

```json
"aws": {
    "access_key": "…",
    "secret_key": "…",
    "region":     "me-south-1",
    "log_group":  "/myapp/auth"
}
```

## Health checks

A minimal health-check webhook:

```json
[
    { "path": "/health", "class": "App\\Webhook\\HealthHandler", "method": "GET" }
]
```

```php
namespace App\Webhook;

use Nour\Contracts\Webhook\WebhookHandlerInterface;
use Nour\Database\RedisDatabase;
use Nour\Database\SqlDatabase;

final class HealthHandler implements WebhookHandlerInterface
{
    public function handle(array $payload, array $headers): bool
    {
        $sqlOk   = SqlDatabase::isEnabled()
            && SqlDatabase::withConnection(fn ($db) => (bool) $db->query('SELECT 1'));
        $redisOk = RedisDatabase::isEnabled()
            && RedisDatabase::withConnection(fn ($r) => $r->ping() === '+PONG');
        return $sqlOk && $redisOk;
    }
}
```

Hook your container orchestrator at `:9502/health`.

## Zero-downtime deploys

Swoole supports SIGUSR1 for graceful worker reload — workers
finish in-flight requests, then respawn with fresh code:

```bash
docker exec myapp_server kill -USR1 1
```

For framework code changes (when the new code is in the image), a
`docker pull && docker stop && docker run` cycle is cleaner — it
takes seconds, and your reverse proxy can buffer briefly.

For pure config changes (`FilesMap.json`, etc.), the framework
hot-reloads the file when its mtime changes — no restart needed.

## Common production gotchas

- **`bind() failed: Address already in use`** — usually means a
  stale Swoole process from a previous run. `docker stop` first,
  then `docker run`. If the host network is in use by another
  process, change the published ports.
- **Container OOM-killed** — raise `runtime.memory_limit` slightly,
  but more often the right answer is to lower `max_request` (force
  worker recycling) and find the leak.
- **High latency on a single endpoint** — check `RequestHandledEvent`
  durations (see [05-events.md](05-events.md)). A single slow
  handler doesn't slow the others (Swoole serves them concurrently).
- **WebSocket connections drop after 60s** — check the reverse-
  proxy's `proxy_read_timeout`. Default nginx is 60s; raise to
  300s+ for chat apps.

## What's not in scope

- **Multi-region failover.** The framework is region-local. Replicate
  the DB across regions yourself.
- **Hot config reload of `setup.json`** (the things `Boot::run` reads
  once). Restart the container.
- **Upgrades across major framework versions.** Read the release
  notes; expect breaking changes until `1.0`.
