# Events

Nour's event dispatcher is PSR-14-flavoured (no hard `psr/event-dispatcher`
dep) and synchronous. The framework fires events at every major
lifecycle point — handshake, request-received, request-handled,
webhook-processed, timer-ticked — and any code can listen.

## Mental model

```
event happens  →  Dispatcher::dispatch($event)  →  every registered listener fires
                                                    in priority order, until one
                                                    calls $event->stopPropagation()
```

Three things to remember:

1. **Listeners run in the same coroutine** as the firing code. Heavy
   work in a listener delays the request.
2. **Throws are swallowed** by the framework's wrapping — a bad
   listener can't crash the request — but they're `error_log`ged.
3. **Listener registration is per-worker.** Register in
   `Bootstrap::register()` (and provider `register()`), not in
   per-request code.

## Getting the dispatcher

```php
use Nour\Container\App;

$events = App::events();   // EventDispatcherInterface; lazily created
```

The first call binds a default `Nour\Events\Dispatcher` into the
container. Apps that want a different dispatcher can bind their own
in `Bootstrap::register()` BEFORE the first `App::events()` call.

## Listening

```php
use Nour\Container\App;
use Nour\Events\Http\RequestHandledEvent;

App::events()->addListener(
    RequestHandledEvent::class,
    static function (RequestHandledEvent $e): void {
        if ($e->durationMs > 500) {
            error_log("[slow] {$e->ip} {$e->durationMs}ms");
        }
    },
    priority: 0,   // optional; higher runs first
);
```

`addListener(string $eventClass, callable $listener, int $priority = 0)`.
Higher priority runs first; ties broken by registration order.

### Listener resolution walks the class hierarchy

When `dispatch($event)` runs, the dispatcher walks the event's full
class hierarchy AND every interface it implements. So a listener
registered against an interface fires for every event that
implements it — useful for cross-cutting hooks (e.g. listen on a
hypothetical `LoggableEventInterface`).

## Stoppable events

Most framework events extend `Nour\Events\Event`, which
implements `isPropagationStopped()` / `stopPropagation()`. After
each listener runs, the dispatcher checks the flag — once true, no
more listeners fire for THIS dispatch.

```php
use Nour\WebSocket\Events\HandshakeEvent;

App::events()->addListener(
    HandshakeEvent::class,
    static function (HandshakeEvent $e): void {
        if ($e->getApiKey() === '') return;       // anonymous OK

        $userId = lookupApiKey($e->getApiKey());
        if ($userId === 0) {
            $e->reject(401, 'Invalid API key');   // close the connection
            $e->stopPropagation();                 // no more listeners
            return;
        }
        $e->setUserId($userId);
    }
);
```

## Built-in events

### WebSocket

| Event | Fires when | Stoppable | Carries |
|---|---|---|---|
| `Nour\WebSocket\Events\HandshakeEvent` | Inbound WS upgrade, before accept | yes (rejection) | `Request`, `ip`, `apiKey`; setters for `userId`, `token`, `userData`; `reject(code, msg)` |
| `Nour\WebSocket\Events\ConnectionOpenedEvent` | After handshake accepted + welcome frame sent | no | `socketId`, `workerId`, `ip`, `userId`, `token`, `userData` |
| `Nour\WebSocket\Events\MessageReceivedEvent` | Client sent a frame, before SocketMain dispatch | yes (skip dispatch) | `socketId`, raw frame, parsed data; `setData()` to mutate |
| `Nour\WebSocket\Events\ConnectionClosedEvent` | After socket removed from store | no | `socketId`, `workerId`, `userId`, `token`, `userData` |

See [08-websocket.md](08-websocket.md).

### HTTP

| Event | Fires when | Stoppable | Carries |
|---|---|---|---|
| `Nour\Events\Http\RequestReceivedEvent` | Top of `DefaultHttpRequestHandler::handle()` | yes (skip the rest) | `Request`, `Response`, `ip`; `setAttribute/getAttribute` for listener-to-listener data |
| `Nour\Events\Http\RequestHandledEvent` | Every exit point of the handler | no | `Request`, `Response`, `ip`, `durationMs`, `?status` |

`status` is `null` when the terminal handler wrote the response
(Swoole doesn't expose a getter); it's an int when the framework
itself wrote it (block, OPTIONS, fingerprint-error).

`RequestHandledEvent` fires even when a `RequestReceivedEvent`
listener stopped propagation — observability tools see every
request.

### Webhooks

| Event | Fires when | Stoppable | Carries |
|---|---|---|---|
| `Nour\Events\Http\WebhookProcessedEvent` | After a webhook handler returns or throws | no | `path`, `method`, `handlerClass`, `payload`, `headers`, `result?`, `error?`, `durationMs` |

Doesn't fire for 404 (path not found) or 405 (method not allowed) —
those didn't reach a handler.

### Timers

| Event | Fires when | Stoppable | Carries |
|---|---|---|---|
| `Nour\Events\TimerTickedEvent` | After each `Timer::tick` callback completes | no | `path`, `intervalMs`, `durationMs`, `?error` |

Useful for timer-health monitoring — count ticks, alert when one
goes longer than half its interval, surface throws.

## Listener placement

Where you register matters:

| Where | Visibility | Use for |
|---|---|---|
| `Bootstrap::register()` | App-level | App-wide listeners. Most common spot. |
| Provider `register()` | Provider-level | Plugin behavior; registered before the host's `Bootstrap`. |
| Provider `boot()` | Provider-level, post-init | When you need to read other contracts that providers contributed. |

A listener registered in any of these places runs for the rest of
the worker's life. Because each worker registers independently, a
worker reload doesn't lose listeners — but it doesn't share them
either.

## Custom events

Any object is a valid event. Easiest path: extend
`Nour\Events\Event` for free `stopPropagation()` semantics.

```php
namespace App\Events;

use Nour\Events\Event;

final class UserSignedUpEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
    ) {}
}
```

Fire it:

```php
App::events()->dispatch(new UserSignedUpEvent($id, $email));
```

Listen for it:

```php
App::events()->addListener(
    UserSignedUpEvent::class,
    fn (UserSignedUpEvent $e) => SendWelcomeEmail::queue($e->userId, $e->email)
);
```

Using readonly properties on the event is conventional — if you
want listeners to mutate, expose explicit setters (matches how
`HandshakeEvent` works).

## Performance notes

- Dispatching with no listeners is ~50 ns. Don't worry about
  always-firing events that nobody listens to.
- Each listener adds one virtual call. 50+ listeners on one event
  type would be unusual; if you have that many, you've probably
  collapsed something that should be a queue / worker.
- Listeners cannot be async. If you need fire-and-forget work,
  enqueue into a Redis list and let a timer drain it (see
  [07-databases.md](07-databases.md) for `Queue`).

## Removing listeners

```php
$listener = static fn (RequestReceivedEvent $e) => /* … */;
App::events()->addListener(RequestReceivedEvent::class, $listener);
// later:
App::events()->removeListener(RequestReceivedEvent::class, $listener);
```

Identity-based removal — pass the same callable instance you
registered. Closures stored in a variable work; inline closures
do not.

## Debugging

When something doesn't fire, the usual suspects:

1. **Listener registered AFTER dispatch.** Bootstrap registers
   listeners; if your dispatch runs before Bootstrap, the listener
   doesn't exist yet. Most app code runs after Bootstrap, so this
   is rare in practice.
2. **Wrong event class.** PHP namespaces: a typo doesn't error,
   it just registers against a class nobody dispatches to.
3. **Listener thrown.** Check `error_log`; throws are swallowed but
   logged.
4. **Wrong worker.** Each Swoole worker has its own dispatcher
   instance. If you registered via `docker exec ... php` (CLI), the
   listener exists in NO worker. Always register in
   `Bootstrap::register()` so every worker picks it up.
