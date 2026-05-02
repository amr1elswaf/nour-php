# Databases & Helpers

Three connection pools (MySQL, PostgreSQL, Redis) all opt-in via
`sitting.json:enabled` flags, plus the helpers that ride on top
(prepared statements, batch inserts, Redis structures).

## The pool model

Each pool is a per-worker fixed-size queue of connections. A
coroutine borrows one via `withConnection()`, runs its work, and
the connection is returned automatically — even on throw. The pool
is the framework's only DB-access path; never construct a raw
`mysqli` or `Predis` object in handlers.

```
worker boot:
  ├─ SqlDatabase::init()      ← reads sitting.json:db_api_user
  ├─ RedisDatabase::init()    ← reads sitting.json:redis
  └─ PostgresDatabase::init() ← reads sitting.json:postgres

per request:
  $result = SqlDatabase::withConnection(function ($db) {
      // $db is a Swoole\Database\MysqliProxy bound to one connection
      return $db->query('SELECT 1')->fetch_assoc();
  });
```

## MySQL — `Nour\Database\SqlDatabase`

`sitting.json:db_api_user`:

```json
"db_api_user": {
    "enabled":  true,
    "host":     "mariadb",
    "port":     3306,
    "user":     "myapp",
    "password": "secret",
    "db":       "myapp"
}
```

### Acquiring a connection

```php
use Nour\Database\SqlDatabase;

$row = SqlDatabase::withConnection(function ($db) {
    $stmt = $db->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
});
```

The closure runs in the same coroutine; the connection is released
on return AND on throw (Swoole's pool wrapper). Don't store `$db`
outside the closure — once released, it may be handed to another
coroutine.

### `BaseDatabase::stmt_handle` — the prepared-statement helper

Most app code uses this instead of raw `prepare/bind/execute`:

```php
use Nour\Database\BaseDatabase;

class UserQueries extends BaseDatabase
{
    public static function findByEmail($db, string $email): ?array
    {
        $result = self::stmt_handle(
            $db,
            'SELECT id, name FROM users WHERE email = ?',
            [$email],
            's',
        );
        return $result->fetch_assoc() ?: null;
    }
}
```

Type string convention: `i` int, `s` string, `d` double, `b` blob.
One char per `?` placeholder. Nullable strings bind fine through `s`.

For SELECT, `stmt_handle` returns the `mysqli_result` (call
`->fetch_assoc`, `->fetch_all(MYSQLI_ASSOC)`, `->num_rows` on it).
For INSERT/UPDATE/DELETE, it returns the statement-like object
(`->affected_rows`, `->insert_id`).

### `BatchInsertHelper` — bulk inserts

Hot paths (timer queues, batch fan-outs) need many-row inserts. The
helper builds one parameterised statement with N value tuples:

```php
use Nour\helpers\sql\BatchInsertHelper;

$result = BatchInsertHelper::insert_batch(
    $db,
    'INSERT INTO views (user_id, post_id, ts) VALUES',
    'ON DUPLICATE KEY UPDATE ts = VALUES(ts)',
    ['user_id' => 'i', 'post_id' => 'i', 'ts' => 'i'],
    [
        ['user_id' => 1, 'post_id' => 7, 'ts' => time()],
        ['user_id' => 1, 'post_id' => 8, 'ts' => time()],
        // …
    ],
);
// $result['affected'], $result['failed_rows'], $result['errors']
```

The keys in `data_keys` set both the column order and the bind type.
Each row in the batch is an associative array keyed the same way.

> **Gotcha:** when `bind_param`/`execute` throws, the helper logs
> via `error_log` and increments `failed_rows` but does NOT add the
> rows to `failed_rows_array`. Capture `errors` and decide whether
> to requeue / alert.

### Failure modes

- **Pool exhausted** — every connection in use; `withConnection()`
  blocks until one frees up. Tune `pool_size` in
  `sitting.json:db_api_user.pool_size` if this becomes routine.
- **`MySQL has gone away`** — Swoole's pool detects the dead
  connection, discards it, retries with a fresh one. Transparent.
- **DDL inside a coroutine** — works, but DDL implicitly commits
  any open transaction. Save migrations for the CLI.

### Disabling MySQL

`"db_api_user": { "enabled": false }` makes
`SqlDatabase::isEnabled()` return false. The HTTP entry point
detects this and runs `Main::start(...)` with `null` for
`$mysql`. Handlers that need it must check or fail.

## PostgreSQL — `Nour\Database\PostgresDatabase`

`sitting.json:postgres`:

```json
"postgres": {
    "enabled":  true,
    "host":     "postgres",
    "port":     5432,
    "user":     "myapp",
    "password": "secret",
    "db":       "myapp"
}
```

PDO + pgsql under the hood. Same shape as MySQL pool:

```php
use Nour\Database\PostgresDatabase;

$rows = PostgresDatabase::withConnection(function (\PDO $pdo) {
    return $pdo->query('SELECT id, name FROM users')->fetchAll(\PDO::FETCH_ASSOC);
});
```

> **Limitation:** PDO is blocking inside a coroutine — Swoole
> doesn't yet hook PDO_pgsql for async I/O. For true async, use
> `Swoole\Coroutine\PostgreSQL` (custom Swoole build with
> `--with-postgresql`) and bind your own pool.

## Redis — `Nour\Database\RedisDatabase`

`sitting.json:redis`:

```json
"redis": {
    "enabled":  true,
    "host":     "redis",
    "port":     6379,
    "scheme":   "tcp",
    "auth":     "",
    "timeout":  10
}
```

```php
use Nour\Database\RedisDatabase;

$count = RedisDatabase::withConnection(function ($redis) use ($key) {
    return (int) $redis->incr($key);
});
```

`$redis` is a `Swoole\Coroutine\Redis` object. All the standard
methods are available (`get`, `set`, `setex`, `incr`, `expire`,
`del`, `exists`, `scan`, `hget/hset`, `lpush/rpop`, `sadd/smembers`,
`eval` for Lua, …).

### Pool reliability

- `idlePingThreshold = 3 s`. Connections idle for less than 3s skip
  the validation `PING` (cheap path); idle longer get a `PING`
  before being handed out (catches restarted Redis fast).
- `connect_timeout = 1 s` default. Add `connect_timeout` to
  `sitting.json:redis` to override.

### Common pitfalls

- **Forgetting to release** — `withConnection` is the ONLY way to
  get a connection. There's no `RedisDatabase::get()` /
  `RedisDatabase::release()` pair. If you find yourself wanting one,
  the work probably belongs inside `withConnection`'s closure.
- **Long-running `subscribe` loops** — these hold a connection
  open forever; use the dedicated `WebSocketWorker::start` pattern
  in worker 0 instead, or a separate process.

## Redis structures (`Nour\Database\redis\Structures`)

Three reusable patterns sit on top of the pool.

### `Queue`

Redis-list-backed FIFO. Used by every background timer to buffer
work between request-time enqueues and timer-time drains.

```php
use Nour\Database\redis\Structures\Queue;

// enqueue (in a request handler):
Queue::enqueue('myapp:notifications', [
    'user_id' => 42,
    'type'    => 'comment',
    'content' => '…',
]);

// dequeue (in a timer):
$rows = Queue::dequeueMultiple('myapp:notifications', 50);
// $rows is a list of arrays in the same shape they were enqueued.

Queue::size('myapp:notifications');  // current length
```

The dequeue contract is "you get back exactly what you enqueued."
No internal `__redis_id` injection (older versions did this and
broke numeric-array payloads silently).

### `KeyValue`

Hash-store helper with TTL support. Backed by Redis hashes + EXPIRE.

```php
use Nour\Database\redis\Structures\KeyValue;

KeyValue::set('chat:presence:42', 'state', 'online', 30);
KeyValue::get('chat:presence:42', 'state');
KeyValue::mget('chat:presence', [42, 43, 44]);  // bulk
KeyValue::del('chat:presence:42', 'state');
```

### `SocketRooms`

The WebSocket layer's Redis-backed registry. App code rarely calls
this directly — see [08-websocket.md](08-websocket.md).

## Helpers around the pools

### `Nour\helpers\BlockIp`

IP block-list. Single-IP exact match (O(1) Redis EXISTS) plus CIDR
ranges:

```php
use Nour\helpers\BlockIp;

$blocker = new BlockIp();
$blocker->block('1.2.3.4', 3600, 'spam');
$blocker->block('10.0.0.0/8', 0, 'corp range');

if ($blocker->isBlocked($clientIp)) {
    // banned
}

$blocker->unblock('1.2.3.4');
$blocker->ttl('1.2.3.4');                 // seconds remaining
$blocker->listAll(100);                    // for an admin panel
```

Fail-CLOSED on Redis unreachable (returns `true` from
`isBlocked()`) — security takes priority over availability.

### `Nour\helpers\RateLimiter`

Per-key fixed-window counter:

```php
use Nour\helpers\RateLimiter;

$rl  = new RateLimiter('myapp:rl', 100, 60);
$res = $rl->check($_SERVER['REMOTE_ADDR']);
if (!$res['allowed']) {
    header('Retry-After: ' . max(1, $res['reset_at'] - time()));
    http_response_code(429);
    exit;
}
```

Returns `['allowed' => bool, 'count' => int, 'max' => int, 'reset_at' => int]`.
Fail-OPEN on Redis unreachable (returns `allowed: true`) — the
opposite of `BlockIp` because the cost of letting a few extra
requests through is lower than taking the platform offline.

### `Nour\helpers\ClientIp`

Real client IP from a Swoole request, respecting trusted proxy
forwards:

```php
use Nour\helpers\ClientIp;

$ip = ClientIp::fromRequest($request);
if (ClientIp::ipInCidrRanges($ip, ['10.0.0.0/8', '192.168.0.0/16'])) {
    // internal network
}
```

Reads `sitting.json:trusted_proxies` for the forward chain — only
proxies in that list are trusted to set `X-Forwarded-For`.

### `Nour\helpers\GenerateTokens`

Wrapper for `bin2hex(random_bytes())`:

```php
use Nour\helpers\GenerateTokens;

$apiKey = GenerateTokens::generate(25);    // 50 hex chars
$short  = GenerateTokens::generate(6);     // 12 hex chars (msg tokens)
```

### `Nour\helpers\IsValidSomething`

Legacy validation helpers (pre-Validator-class).
`isValidDataInput(stdClass $data, string $field, array $shapes)`
and friends. Kept for backward-compat; new code should use
[Validator](06-validation.md) instead.

## Connecting from outside the pool

For one-shot CLI work where you don't want pool overhead, vanilla
`mysqli` is fine — see how `Nour\Console\MigrationRunner` does it.
That's the only place in the framework that bypasses the pool.
