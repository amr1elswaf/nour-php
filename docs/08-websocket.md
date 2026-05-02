# WebSocket

Nour ships a WebSocket layer that's transport-only — your app
decides what's authenticated, what data lives on each socket, and
what messages mean. The framework provides:

- A handshake event your app uses to authenticate and attach data.
- A pluggable socket store (Redis multi-worker or in-memory single-
  worker) so cross-worker IPC can find a user's sockets.
- Event hooks for connection-opened, message-received, connection-closed.
- An outbound dispatcher (`MessageManger`) that handles same-worker
  pushes, cross-worker IPC, room broadcasts, and per-socket photo-
  URL signing.
- An inbound dispatcher that routes `command` frames through the
  same `Router` HTTP uses.

## Connecting (handshake)

The client opens `ws://host:9503` and sends an API key via one of:

1. `?api-key=<key>` query string.
2. `Authorization: Bearer <key>` header.
3. `Sec-WebSocket-Protocol: <key>` subprotocol.
4. `api_key` cookie.

The framework extracts the key, fires `HandshakeEvent` BEFORE
accepting the upgrade, and lets app listeners decide whether to
accept and what data to attach.

### App-side: register a listener

In `Bootstrap::register()`:

```php
use Nour\Container\App;
use Nour\Database\SqlDatabase;
use Nour\WebSocket\Events\HandshakeEvent;

App::events()->addListener(
    HandshakeEvent::class,
    static function (HandshakeEvent $e): void {
        $apiKey = $e->getApiKey();
        if ($apiKey === '') {
            // Anonymous connection — leave userId at 0.
            return;
        }

        $row = SqlDatabase::withConnection(
            fn ($db) => /* lookup the API key, return user row */
        );
        if (!$row) {
            $e->reject(401, 'Invalid API key');
            $e->stopPropagation();
            return;
        }

        $e->setUserId((int) $row['id']);
        $e->setToken($apiKey);
        $e->setUserData(['role' => $row['role']]);
    }
);
```

The framework writes the welcome frame on accept:

```json
{ "type":"welcome", "socket_id":1, "nour_id":42, "worker_id":3,
  "ip":"172.18.0.1", "handshake_mode":"manual" }
```

`socket_id` is the Swoole `fd` (per-process). `nour_id` is whatever
your listener called `setUserId()` with — `0` if no listener
attached one.

### Anonymous handshakes

If your listener returns without rejecting and without calling
`setUserId()`, the connection is accepted with `userId = 0`. Your
inbound command handlers can decide whether to allow specific
commands without a user.

If you don't want anonymous at all, reject when the API key is
empty:

```php
if ($e->getApiKey() === '') {
    $e->reject(401, 'API key required');
    $e->stopPropagation();
}
```

## The socket store (`SocketStoreInterface`)

After the handshake accepts, the framework calls
`SocketStoreInterface::addSocket(...)` with the user data your
listener attached. The store is the system-of-record for "which
sockets does user 42 have, on which workers, with what IP?"

Configure via `setup.json:services.websocket.store`:

```json
"websocket": {
    "enabled":   true,
    "host":      "0.0.0.0",
    "port":      9503,
    "store":     "redis",
    "namespace": "myapp:ws"
}
```

| `store` value | Backend | Use when |
|---|---|---|
| `"redis"` | `Nour\WebSocket\RedisSocketStore` | Multi-worker (the default). Cross-worker IPC looks up a recipient's worker via this store. |
| `"memory"` | `Nour\WebSocket\InMemorySocketStore` | Dev / single-worker. No external dep, faster lookups. Crashes if you have more than one worker. |
| FQCN of your impl | Your custom store | Kafka, etcd, what-have-you. Implement `SocketStoreInterface`. |

App code retrieves the bound store via `App::socketStore()`. Most
app code doesn't need to touch it directly — `MessageManger` does.

## Inbound: `command` frames

Frames the server understands:

| Frame `type` | Reaction |
|---|---|
| `"ping"` | Reply with `{ type: "pong", timestamp }`. |
| `"heartbeat"` | Refresh socket's last-seen, reply `heartbeat_ack`. |
| `"command"` | Routes `body.req` through `Router::dispatch` (same router HTTP uses). |
| anything else | Reply `{ type: "unknown_command" }`. |

A `command` frame:

```json
{
    "type": "command",
    "body": {
        "req":  "ADD USER MESSAGE",
        "data": { "chat_id": 12, "message": "hi" }
    }
}
```

The router resolves `req` against `FilesMap.json` the same way HTTP
does. The handler runs with `Coroutine::getContext()['type'] = 's'`
(socket) instead of `'h'` (HTTP) — gate handlers that should be
WS-only or HTTP-only:

```php
public static function main(): void
{
    $ctx = Coroutine::getContext();
    if ($ctx['type'] === 'h') error('forbidden', 403);   // socket-only
    // …
}
```

## Outbound: `MessageManger`

All outbound pushes go through `Nour\core\socket\MessageManger`.
Picking the right method:

| Method | Use when |
|---|---|
| `notify($userId, $type, $data)` | Single recipient, one frame, no photos. |
| `sendToUser($userId, $message, $hasPhotos)` | Single recipient, message already shaped, photos need per-socket signing. |
| `sendOneMessagesToMultiUsers($userIds, $message, $hasPhotos)` | Same frame, many recipients. Cheaper than N×notify. |
| `MultiNotify($rows)` | Different frames per recipient (each row is `['user_id', 'message']`). |
| `broadcast($roomName, $message)` | Push to every socket in a Redis-tracked room. |

Frame shape on the wire is always:

```json
{ "type": "<string>", "data": { ... }, "timestamp": 1777099056 }
```

### Photo URLs in messages

BunnyCDN signed URLs are IP-bound. When a single message contains
URLs that need to render on the recipient's device, the dispatcher
must sign each URL against THAT socket's IP (a user with two
devices on different IPs needs two different signatures).

When `$hasPhotos = true`, the dispatcher iterates the recipient's
sockets, looks up each socket's IP from the store, and rewrites
URLs in `$message['data']['photos']` per socket. The signing is
delegated to the `Nour\Contracts\Media\PhotoSignerInterface` your
Bootstrap binds.

Cost: `O(sockets × photos)` per send. For frames without photos,
pass `$hasPhotos = false` — the JSON encode happens once.

## Lifecycle events

Beyond `HandshakeEvent` (above), three more fire during the
connection's life:

```php
use Nour\Container\App;
use Nour\WebSocket\Events\ConnectionOpenedEvent;
use Nour\WebSocket\Events\MessageReceivedEvent;
use Nour\WebSocket\Events\ConnectionClosedEvent;

App::events()->addListener(
    ConnectionOpenedEvent::class,
    static function (ConnectionOpenedEvent $e): void {
        // Connection accepted, addSocket succeeded, welcome frame sent.
        // Push "X is online" notifications, increment metrics, etc.
    }
);

App::events()->addListener(
    MessageReceivedEvent::class,
    static function (MessageReceivedEvent $e): void {
        // Fires before SocketMain dispatches the command.
        // Useful for tracing, audit, per-frame rate limiting.
        // Call $e->stopPropagation() to skip dispatch.
    }
);

App::events()->addListener(
    ConnectionClosedEvent::class,
    static function (ConnectionClosedEvent $e): void {
        // Socket already removed from store. $e->userData carries
        // whatever the handshake listener attached — useful for
        // fan-out by user_id / role / tenant.
    }
);
```

See [05-events.md](05-events.md) for the full event catalog.

## Rooms

Rooms are named groups of sockets — useful for live events,
chatrooms, presence-broadcast channels. The store tracks them; the
framework provides the lookup but doesn't auto-manage membership
(you decide when to join/leave).

```php
use Nour\Container\App;

$store = App::socketStore();
$store->addToRoom($socketId, 'live:exam:42');

// later, push to everyone in the room:
\Nour\core\socket\MessageManger::broadcast('live:exam:42', [
    'type' => 'live_update',
    'data' => ['question' => 7],
]);
```

## Socket-side handler skeleton

```php
<?php
namespace App\handlers\sockets\chat;

use Nour\helpers\IsValidSomething;
use Swoole\Coroutine;

final class AddMessageHandler
{
    public static function main(): void
    {
        $ctx = Coroutine::getContext();
        if ($ctx['type'] === 'h') error('forbidden', 403);   // WS-only

        $data = $ctx['data'];
        $user = $ctx['user'];

        IsValidSomething::isValidDataInput($data, 'chat_id', ['integer']);
        IsValidSomething::isValidDataInput($data, 'message', ['string']);

        // Enqueue to Redis, push to the recipient's sockets immediately,
        // let a timer drain the queue into MySQL.
        AddMessage::add($ctx['mysql'], $user['id'], $data->chat_id, $data->message);

        // Sockets don't return a response; if you want to confirm,
        // push a frame back via MessageManger::notify().
        end_request();
    }
}
```

`end_request()` (defined in `opertion.php`) throws an internal
exception to short-circuit the coroutine. WS handlers don't write
"return" responses — they push frames asynchronously.

## Common patterns

### Soft online / offline

Don't rely on `ConnectionClosedEvent` alone for "user X is offline"
— a user with two devices is offline only when BOTH connections
close. Use the store:

```php
$store = App::socketStore();
if (!$store->getUserConnectionsCount($userId)) {
    // last connection just closed
    fireOfflineNotifications($userId);
}
```

### Cross-worker pushes

`MessageManger` handles this transparently via Swoole IPC. When
you push to a socket on a different worker:

1. Looks up the target's worker_id from the store.
2. If same worker → `Server::push()` directly.
3. If different → `Server::sendMessage(envelope, targetWorker)`.
4. The receiving worker's `pipeMessage` handler unpacks and calls
   `Server::push()` locally.

App code doesn't see the difference.

### Detecting connection drops

The framework removes a socket from the store on `onClose`, fires
`ConnectionClosedEvent` after. Your timer-driven business logic can
trust the store — no zombie sockets accumulate.

## Reference test client

`F:/projects/nour_php/nour_php/test.html` (gooabb's test page) is a
working WS client showing the wire shape end-to-end. Connect with a
valid API key, send `{type:"ping"}`, watch the `pong` come back.
Useful for sanity checks.
