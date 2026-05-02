# CLI (`bin/nour`)

The framework ships an executable script at `bin/nour` with 11
built-in commands covering routes inspection, IP blocking, cache
flushing, server start, and database migrations. Inside the
provided Docker image it lives at `/opt/nour/bin/nour`.

## Invocation

```bash
docker exec <container> php /opt/nour/bin/nour <command> [args]
```

Or directly when running on the host:

```bash
php /path/to/nour/bin/nour <command> [args]
```

The script:

1. Resolves the host app folder (`$NOUR_APP_DIR` env, or `--app=<path>`,
   or current working directory).
2. Loads the framework autoload (`vendor/autoload.php` next to `bin/`).
3. Loads the host app's autoload if present.
4. Bootstraps `CliBootstrap::init` — reads `sitting.json` /
   `setup.json`, sets `$GLOBALS`, initialises DB/Redis/Postgres pools.
5. Dispatches to the named command.

The Docker image sets `NOUR_APP_DIR=/app`, so `docker exec` calls
work without `--app=`.

## Listing commands

```bash
$ docker exec <c> php /opt/nour/bin/nour list

nour 0.2.0 — Nour framework CLI

Usage:  nour <command> [arguments]

Available commands:
  command           description
  ----------------  -----------------------------------------------
  migrate           Apply pending database migrations
  serve             Start the Swoole HTTP/WebSocket server
  cache:flush       Delete Redis keys matching a glob pattern
  ip:block          Block an IP or CIDR range
  ip:list           List currently blocked IPs and CIDR ranges
  ip:unblock        Remove an IP or CIDR range from the block-list
  migrate:rollback  Roll back the most recently applied migration
  migrate:status    Show applied / pending migrations
  route:list        List HTTP routes from FilesMap.json + providers
  timer:list        List background timers from Timers.json
  webhook:list      List webhooks from Webhooks.json + providers
```

`nour help <command>` prints a single command's signature.
`nour --version` prints the framework version.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Success. |
| `1` | Generic failure (validation, command misuse). |
| `2` | Environment error (DB / Redis unreachable, config missing). |
| `127` | Unknown command. |

## Argument parsing

Long-form options only — short forms (`-f`) aren't supported.

```bash
nour ip:block 1.2.3.4 --ttl=60 --reason="bad actor"
nour ip:block 1.2.3.4 --ttl 60                       # space-separated also works
nour migrate --force                                  # boolean flag
```

Boolean flags evaluate as true for `1`, `true`, `yes`, `on`. Anything
else (including being absent) is false.

## Inspection commands

### `route:list`

Print the full route table — JSON config + provider-supplied
contributions, in one merged view:

```bash
$ docker exec <c> php /opt/nour/bin/nour route:list
  req                            handler                              auth      roles    source
  -----------------------------  -----------------------------------  --------  -------  ------
  ADD POST                       community/post/PostHandler           required  Student  json
  …
  PLUGIN HELLO                   App\Plugins\Example\PluginHello…     open      -        plugin

336 route(s) total.
```

Source column shows where the entry came from (`json` =
FilesMap.json; `plugin` = a provider).

### `webhook:list`

Same shape for webhook routes — JSON + provider-supplied:

```bash
$ docker exec <c> php /opt/nour/bin/nour webhook:list
  path                 method    handler                                          source
  -------------------  --------  -----------------------------------------------  ------
  /health              GET,POST  App\Webhook\HealthHandler                        json
  /plugin/echo         POST      App\Plugins\Example\PluginEchoWebhookHandler     plugin
  /webhook/myfatoorah  POST      App\Webhook\MyfatoorahHandler                    json

3 webhook(s) total.
```

### `timer:list`

```bash
$ docker exec <c> php /opt/nour/bin/nour timer:list
  path                                  interval  every
  ------------------------------------  --------  -----
  insert/messages/messageAdd.php        1s        1s
  update/last_seen/UsersLastseen.php    10s       10s
  update/plans/UpdatePlansTimer.php     43200s    12.0h

3 timer(s) total.
```

The `every` column is a human-friendly version of `interval`.

## IP block-list commands

All three operate on the same Redis-backed store the runtime
checks via `Nour\helpers\BlockIp` / the global gate /
`BlockIpMiddleware`. Changes take effect immediately — no worker
restart needed.

### `ip:block <target> [--ttl=3600] [--reason=<text>]`

```bash
nour ip:block 1.2.3.4
nour ip:block 1.2.3.4 --ttl=120 --reason="rate-limit abuser"
nour ip:block 10.0.0.0/8 --ttl=0 --reason="corporate range"
```

`--ttl=0` (or omitting `--ttl` and using the helper directly)
makes the block permanent. CIDR ranges are stored separately and
matched on every `isBlocked()` call.

### `ip:unblock <target>`

```bash
nour ip:unblock 1.2.3.4
nour ip:unblock 10.0.0.0/8
```

### `ip:list [--limit=100]`

```bash
$ nour ip:list
  target       kind  ttl   reason
  -----------  ----  ----  -----------------
  1.2.3.4      ip    2.0m  rate-limit abuser
  10.0.0.0/8   cidr  -1    corporate range

2 block(s) total.
```

`ttl` of `-1` = permanent. `kind` = `ip` (exact) or `cidr`.

## Cache commands

### `cache:flush <pattern> [--force]`

Delete Redis keys matching a glob pattern. Uses `SCAN` under the
hood (non-blocking) and `DEL` in chunks of 200.

```bash
nour cache:flush 'login:*'                  # clear all login state
nour cache:flush 'nour:rl:http:*'           # clear HTTP rate-limit counters
nour cache:flush 'oauth:jwks:*'             # clear OAuth JWKS cache
nour cache:flush '*' --force                # nuke everything (refused without --force)
```

Patterns starting with `*` (alone or as the full pattern) require
`--force` to prevent accidental whole-DB wipes.

## Server command

### `serve`

```bash
nour serve
```

Equivalent to invoking `bin/server.php` — starts the Swoole HTTP
+ WebSocket + webhook server. Listed as a command so `nour list`
is a complete inventory, but the Docker image's default `CMD` is
still `bin/server.php`.

## Migration commands

`Nour\Console\MigrationRunner` handles `nour migrate*` commands.
Connection priority: `sitting.json:db_migrations` →
fallback `db_api_user`. The runtime user typically has no DDL
grants, so configure `db_migrations` distinctly in production.

### Migration file conventions

```
database/migrations/{YYYY-MM-DD_HHMMSS}_{name}.up.sql      ← required
database/migrations/{YYYY-MM-DD_HHMMSS}_{name}.down.sql    ← optional
```

Examples:

```
database/migrations/2026-05-02_120000_create_users.up.sql
database/migrations/2026-05-02_120000_create_users.down.sql
database/migrations/2026-05-02_130000_add_user_index.up.sql
```

Files are sorted lexicographically — the timestamp prefix gives a
natural order. Apply order is up-files in lexical sequence.

### Tracking table

```sql
CREATE TABLE nour_migrations (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    checksum CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Auto-created on first run.

### `migrate:status`

```bash
$ nour migrate:status
  name                              status   applied_at
  --------------------------------  -------  -------------------
  2026-05-02_120000_create_users    applied  2026-05-02 12:00:43
  2026-05-02_130000_add_user_index  pending  -
```

A migration whose file's checksum has changed since apply shows
`applied (drift!)` — the file was edited after running. The
runner doesn't auto-revert; you decide whether to roll back +
re-apply or accept the drift.

A migration recorded in the table but missing from disk shows
`missing-file` — the file was deleted after applying. `migrate`
won't touch it; it's history.

### `migrate`

Apply every pending migration in lexical order. Each runs in its
own transaction. A failure stops the run; earlier migrations stay
applied.

```bash
$ nour migrate
[migrate] applied: 2026-05-02_130000_add_user_index
1 migration(s) applied.
```

Re-running with nothing pending:

```bash
$ nour migrate
Nothing to migrate.
```

### `migrate:rollback [--allow-empty]`

Reverts the most recently applied migration. Looks for the sibling
`.down.sql`:

```bash
$ nour migrate:rollback
[rollback] reverted: 2026-05-02_130000_add_user_index
```

If no `.down.sql` exists, the runner refuses unless `--allow-empty`
is passed. With `--allow-empty`, the migration is forgotten from
the tracking table without running anything (use this when you've
deleted a `.down.sql` and just want to clean state).

### Common migration gotchas

- **DDL implicitly commits.** MySQL auto-commits on `CREATE TABLE`
  / `ALTER TABLE` etc. The runner wraps each migration in a
  transaction for tracking-table consistency, but DDL inside still
  commits independently. Don't put a DDL+DML migration in one
  file expecting the DML to roll back if a later DDL fails;
  separate them.
- **`DELIMITER` for stored procedures.** When a migration creates
  a procedure with inner `;`s, wrap the body:
  ```sql
  DELIMITER $$
  DROP PROCEDURE IF EXISTS Foo$$
  CREATE PROCEDURE Foo() BEGIN ... END$$
  DELIMITER ;
  ```
  The runner uses `multi_query` which honors `DELIMITER` directives.
- **Drift detection is informational.** Editing an applied
  migration is bad practice (your dev DB ran the old version,
  prod will run the new), but the runner doesn't block it — only
  warns.

## Adding your own commands

The CLI is currently host-extensible only by editing `bin/nour`.
A future session may add `ProviderInterface::commands()` so plugins
can register commands. For now, app-specific commands belong in
your own `bin/<app>` script that wraps `Nour\Console\Application`:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

use Nour\Console\Application;
use Nour\Console\CliBootstrap;
use Nour\Console\Commands\MigrateCommand;
// … register everything you want from /opt/nour/bin/nour

CliBootstrap::init('/app');

$app = new Application('myapp', '1.0.0');
$app->register(new MigrateCommand());
$app->register(new App\Console\MyCustomCommand());

exit($app->run($argv));
```
