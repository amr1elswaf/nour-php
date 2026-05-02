# Routing

Nour's router is intentionally tiny. Every inbound HTTP request is
`POST /` with a `req` form field; the router maps `req` → handler
class. There's no URL DSL, no parameter extraction, no named
routes. What you get in return is O(1) lookup, hot-reload of new
routes without a worker restart, and a flat config file you can
grep.

## Request shape

Every authenticated endpoint:

```
POST /                          (path is irrelevant)
Content-Type: multipart/form-data

req=GET HOME POSTS              (uppercase, spaces, ASCII)
data={"last_post_id":null}      (JSON-encoded payload — string!)
API=<50-char-token>             (optional; required if pre>=2)
```

Convention: `req` keys are uppercase with spaces (`"ADD POST"`,
`"GET MY PROFILE"`). The framework matches them as exact strings.

## `FilesMap.json` row shape

```json
{
    "file_path":      "community/post/PostHandler",
    "req":            "ADD POST",
    "pre":            2,
    "up":             ["Student", "Teacher"],
    "security_level": 2,
    "bypass_ban":     false
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `file_path` | string | yes | Handler class. Three accepted shapes (see below). |
| `req`       | string | yes | Inbound key. Case-sensitive. |
| `pre`       | int    | yes | Auth level: `1` = open, `2` = API key required, `3` = high-security. |
| `up`        | list\|string | no | Allowed roles. `[]` = any. Comma-string also works. |
| `security_level` | int | no | Forwarded to your `AuthPipelineInterface` for additional gating. |
| `bypass_ban` | bool | no | If true, route stays accessible to banned users. |

### `file_path` resolution

The router accepts three shapes:

1. **Slash path** (gooabb / default convention) —
   `community/post/PostHandler` → prepend
   `framework.handler_namespace_prefix` (default `App\handlers\`)
   and convert slashes to backslashes →
   `App\handlers\community\post\PostHandler`.
2. **Already prefixed** — `App\handlers\foo\Bar` → used as-is.
3. **Already-FQCN** — `App\Plugins\Example\PluginHelloHandler`
   (any backslash in the value AND the class autoloads) → used as-is.

The default prefix is configurable via
`sitting.json:framework.handler_namespace_prefix`. Override it if
your app uses a different namespace convention.

## Handler contract

A handler is a class with `public static function main(): void`
(or non-static — both work; the router prefers the static form for
the no-allocation path, falls back to instantiation otherwise).

```php
<?php
namespace App\handlers\community\post;

use Nour\Database\BaseDatabase;
use Nour\helpers\IsValidSomething;
use Swoole\Coroutine;

final class PostHandler
{
    public static function main(): void
    {
        $ctx   = Coroutine::getContext();
        $data  = $ctx['data'];   // stdClass — parsed from POST `data`
        $user  = $ctx['user'];   // ['id', 'role', 'API', 'req']
        $mysql = $ctx['mysql'];  // MysqliProxy or null when DB disabled
        $ip    = $ctx['ip'];

        switch ($user['req']) {
            case 'ADD POST':
                IsValidSomething::isValidDataInput($data, 'text', ['string']);
                $id = AddPostLogic::run($mysql, $user['id'], $data->text);
                echo_data(['status' => true, 'post_id' => $id]);
                break;
        }
    }
}
```

### Coroutine context (`$ctx`)

| Key | Type | Notes |
|---|---|---|
| `data` | `stdClass` | Decoded JSON payload from POST `data`. |
| `user` | `array` | `id`, `role`, `API`, `req`, plus whatever your auth pipeline added. |
| `mysql` | `Swoole\Database\MysqliProxy`\|`null` | Pooled DB connection. Null when DB disabled in `sitting.json`. |
| `ip` | `string` | Resolved client IP via `ClientIp::fromRequest`. |
| `headers` | `array` | Lower-cased request headers. |

Routes can run multi-`req` switch statements in a single handler
(common pattern for a feature area: one `PostHandler` covers
`ADD POST`, `EDIT POST`, `DELETE POST`, `GET POST`).

## Auth levels (`pre`)

| `pre` | Meaning |
|---|---|
| `1` | Open. Anyone can call. Login, signup, password reset. |
| `2` | API key required. The `API` form field must resolve via your `AuthPipelineInterface`. |
| `3` | High-security. Same as `2` plus your pipeline can apply extra gating (fingerprint matching, MFA, etc.). |

The framework itself doesn't decide what `2` vs `3` means
beyond "non-zero requires an API key" — your pipeline does.
See [08-websocket.md](08-websocket.md) for the WS analog.

## Roles (`up`)

A list of role strings. The framework checks `$user['role']`
against the list:

| `up` value | Meaning |
|---|---|
| `[]` | Any role passes. |
| `["Student"]` | Only students. |
| `["Student", "Teacher"]` | Students OR teachers. |
| `["*"]` | Any **non-empty** role (any logged-in user with a role). |

`Admin` always passes regardless of the list (built-in escape hatch
in `Router::checkPermissions`).

## Ban policy

If you've bound a `Nour\Contracts\Auth\BanCheckerInterface` in your
Bootstrap, the router consults it AFTER auth + role checks but
BEFORE handler dispatch:

- **`SCOPE_FULL`** ban → blocks every route.
- **`SCOPE_ALL`** ban → blocks community routes only (route's class
  prefix matches `framework.community_route_prefixes`). Admins are
  exempt from `SCOPE_ALL`.

Routes with `bypass_ban: true` always pass — typically reserved for
"contact support" / "view ban reason" endpoints so banned users
aren't completely cut off.

If no `BanCheckerInterface` is bound, the ban check is skipped
entirely.

## Adding a new route

1. **Write the handler class** under your `App\handlers\…` tree
   (or anywhere autoloaded — see "FQCN" above).
2. **Add a row** to `data/FilesMap.json`. The router auto-reloads
   the file when its mtime changes; no worker restart for the
   route table.
3. **Restart the workers** if you added a NEW PHP file —
   Swoole's autoload caches don't see new files until restart:
   `docker restart <container>`.
4. **Smoke test**:
   ```bash
   curl -s -X POST http://localhost:9501 \
       -F 'req=MY NEW REQ' \
       -F 'data={}' \
       -F 'API=<token>'
   ```

Or list every loaded route (with provider contributions merged):

```bash
docker exec <container> php /opt/nour/bin/nour route:list
```

## Response helpers

Defined globally in `opertion.php` (loaded at boot). All write the
response and short-circuit the handler via an internal exception.

| Helper | Status | JSON shape | When to use |
|---|---|---|---|
| `echo_data($data)` | 200 | `$data` verbatim | Custom payload, full control. |
| `sucss($msg='success', $extra=[])` | 200 | `{success: msg, code: 'success', ...$extra}` | "Done, here's a tiny payload." |
| `fail($cause='failed')` | 400 | `{failed: cause, code: 'failed'}` | Generic failure with no detail. |
| `error($msg, $code=400, $part='unknow', $name='error')` | `$code` | `{error: msg, part, code: name}` | Validation / business errors with a machine-readable `code`. |

For application-level errors prefer `error()` with a meaningful
`name` — frontends dispatch on the code:

```php
error('Email already verified', 409, 'email', 'already_verified');
```

## Handler re-use vs single-`req` per file

Both are valid:

- **Multi-`req` switch** — one handler per feature area (gooabb's
  pattern). Easier to share helpers between related ops; harder to
  navigate when a feature has 20+ req keys.
- **One `req` per handler** — flatter directory tree, IDE jump-to-class
  works on the FQCN. Less DRY when ops share validation.

The router doesn't care; pick what fits your team.

## Where the magic isn't

There's no:

- **URL parameter extraction** (`/users/{id}`). Pass the id in `data`.
- **Method-specific routing** (`GET vs POST`). Always `POST`.
- **Route groups** with shared middleware. Apply middlewares
  globally via the pipeline, or guard inside the handler.
- **Named routes**. The route key IS its name.

If you find yourself wanting any of those, you might be using the
wrong framework — Laravel and Symfony both ship them out of the box.
