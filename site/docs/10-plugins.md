# Plugins (Service Providers)

A plugin is a class that contributes routes, webhooks, and
container bindings to the framework's existing config-driven
loaders. Plugins are listed explicitly in `setup.json:providers` —
no Composer auto-discovery (yet).

## When to write a plugin

- You're packaging a self-contained feature (billing, oauth, admin
  panel) that should be reusable across projects.
- You want the host to opt in via a single line in `setup.json`,
  not by editing `Bootstrap.php` and `FilesMap.json`.
- A third-party library you maintain wants to ship Nour
  integration alongside its core.

When you DON'T need a plugin: app-internal features. Just put them
in `App\handlers\…` and `Bootstrap.php`. Plugins are about
*reuse*, not just structure.

## Writing a provider

Implement `Nour\Contracts\Plugin\ProviderInterface`:

```php
namespace App\Plugins\Billing;

use Nour\Container\ContainerInterface;
use Nour\Contracts\Plugin\ProviderInterface;

final class BillingProvider implements ProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Bind your contracts. Other providers haven't run yet,
        // so don't resolve from the container here.
        $container->bind(InvoiceRepository::class, new InvoiceRepository());
    }

    public function boot(ContainerInterface $container): void
    {
        // All providers AND the host's Bootstrap have run by now.
        // Container resolution is safe; routes have loaded.
    }

    public function routes(): array
    {
        return [
            [
                'file_path'      => InvoiceListHandler::class,
                'req'            => 'BILLING LIST INVOICES',
                'pre'            => 2,
                'up'             => ['Admin', 'Teacher'],
                'security_level' => 1,
            ],
            [
                'file_path'      => InvoicePayHandler::class,
                'req'            => 'BILLING PAY INVOICE',
                'pre'            => 2,
                'up'             => ['Admin', 'Teacher'],
                'security_level' => 2,
            ],
        ];
    }

    public function webhooks(): array
    {
        return [
            [
                'path'   => '/billing/myfatoorah-callback',
                'class'  => MyfatoorahCallbackHandler::class,
                'method' => 'POST',
            ],
        ];
    }

    public function timers(): array
    {
        // Currently informational — timers register at master start
        // before per-worker providers run, so contributed timers are
        // ignored. Use `data/Timers.json` directly until a future
        // session merges them.
        return [];
    }
}
```

## Registering the provider

Add the FQCN to `setup.json:providers`:

```json
{
    "providers": [
        "App\\Plugins\\Billing\\BillingProvider"
    ],
    "services": { ... }
}
```

Boot logs confirm it loaded — once per worker:

```
[PluginLoader] registered 1 provider(s): App\Plugins\Billing\BillingProvider
[Router] +2 provider route(s)
[Router] Initialized with 337 routes
[WebhookRouter] loaded 4 route(s) (incl. 1 from providers)
```

## Lifecycle

Per-worker, in order:

```
1. Bootstrap::register()                  ← host app bindings
2. PluginLoader::loadAll($providers)      ← each provider's register()
3. HTTP pipeline binding                  ← uses bound contracts
4. Router::init / WebhookRouter::init     ← merges PluginLoader::collectRoutes()
                                              + collectWebhooks()
5. GlobalRegistry init
6. PluginLoader::bootAll()                ← each provider's boot()
```

`register()` is the right place for container bindings —
everything that comes after may resolve them. `boot()` is the
right place for cross-provider work — by then the container is
fully populated.

## What providers can contribute

### Container bindings

Inside `register()`, bind interfaces to implementations:

```php
$container->bind(MyContractInterface::class, new MyImpl());

// or with a factory closure:
$container->bind(MyContractInterface::class, fn () => new MyImpl());
```

### Routes (HTTP `req` keys)

`routes()` returns a list of `FilesMap.json`-shaped entries.
Same format, same router conventions — see [03-routing.md](03-routing.md).
The merge happens AFTER the JSON file is read, so a provider with
the same `req` key as the host JSON overrides the host.

```php
public function routes(): array
{
    return [[
        'file_path'      => 'App\\Plugins\\Billing\\InvoiceListHandler', // FQCN
        'req'            => 'BILLING LIST INVOICES',
        'pre'            => 2,
        'up'             => ['Admin'],
        'security_level' => 1,
    ]];
}
```

For provider-supplied routes, `file_path` should be the
already-FQCN form — provider classes don't live under
`App\handlers\` so the slash-path convention doesn't apply.

### Webhooks

`webhooks()` returns `Webhooks.json`-shaped entries. Merged after
the JSON file:

```php
public function webhooks(): array
{
    return [[
        'path'   => '/billing/callback',
        'class'  => MyfatoorahCallbackHandler::class,
        'method' => 'POST',
    ]];
}
```

Same `WebhookHandlerInterface` contract — see
[09-webhooks-and-timers.md](09-webhooks-and-timers.md).

### Event listeners

Register from `register()` or `boot()`:

```php
public function boot(ContainerInterface $container): void
{
    \Nour\Container\App::events()->addListener(
        \Nour\WebSocket\Events\HandshakeEvent::class,
        static function ($e) {
            // …
        }
    );
}
```

### Middlewares

Provider middlewares aren't auto-merged into
`setup.json:services.http.middlewares` — apps still list them
explicitly. Plugins document the FQCNs they expose; host apps
add them to the pipeline:

```json
"middlewares": [
    "App\\Plugins\\Billing\\BillingAuditMiddleware"
]
```

This is intentional: pipeline order is the host's call, not the
plugin's.

## Conflict policy

Routes / webhooks with the same key as an existing entry replace
silently:

- Host JSON loads first.
- Then providers in declared order — later providers replace
  earlier ones.

So the resolution order is `host JSON → providers (in order)`. To
override a host route from a plugin, give it the same `req` key.

Container bindings work the same way: the LAST `bind()` call wins.
Host's `Bootstrap::register()` runs BEFORE
`PluginLoader::loadAll()`, so the host's bindings are overridden by
providers. If you want the host to have final say, do those
bindings in your `Bootstrap` AFTER providers have run. But there's
no clean hook for "after providers" except the framework's own
post-init events.

## Distributing a plugin

For a project-internal plugin: ship the classes under
`lib/app/Plugins/<Name>/`. The host's existing Composer autoload
(`App\\` → `lib/app/`) picks them up.

For an external package:

1. Publish under your own namespace (`Acme\NourBilling`).
2. Provide a `composer.json` that maps the namespace.
3. Document the FQCN of the provider class.
4. Document any prerequisites (DB tables, config keys, etc.).

The host adds your provider to `setup.json:providers` and lets
PluginLoader instantiate it. There's no auto-discovery
mechanism — the explicit list is part of the framework's
"obvious-over-convenient" stance.

## A worked example

`F:/projects/nour_php/nour_php/lib/app/Plugins/Example/` ships as a
reference implementation:

```
ExampleProvider.php          ← contributes 1 route + 1 webhook
PluginHelloHandler.php       ← the route handler
PluginEchoWebhookHandler.php ← the webhook handler
```

Registered in `setup.json:providers`. After boot:

```bash
$ docker exec <container> php /opt/nour/bin/nour route:list | grep plugin
  PLUGIN HELLO    App\Plugins\Example\PluginHelloHandler    open      -    plugin

$ curl -X POST http://localhost:9501 -F 'req=PLUGIN HELLO' -F 'data={"x":1}'
{"plugin":"ExampleProvider","said":"hello","echo":{"x":1}}

$ curl http://localhost:9502/plugin/echo -X POST -d '{"hi":"plugin"}' \
       -H "Content-Type: application/json"
{"ok":true,"path":"/plugin/echo"}
```

Remove the entry from `setup.json:providers` and restart to disable.

## Limitations

- **No timers.** `timers()` is on the interface but provider-supplied
  timers aren't currently merged. Host apps register timers via
  `data/Timers.json` directly.
- **No CLI commands.** A future session will add `commands()` so
  providers can ship `nour <plugin>:<command>` extensions.
- **No Composer auto-discovery.** Apps list providers explicitly.
  This is by design — startup behavior should be readable, not
  derived from a transitive dependency tree.
- **Per-worker, no shared state.** Each Swoole worker loads
  providers independently. State you need across workers belongs
  in Redis / MySQL, not in provider properties.
