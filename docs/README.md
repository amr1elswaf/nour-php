# Nour Framework — Documentation

A small Swoole-based PHP framework for HTTP + WebSocket backends.
Each numbered file below covers one topic; read in order, or jump
straight to whatever you need.

> **Try it now**
>
> ```bash
> docker pull amr1elswaf/nour:latest
> ```
>
> The image ships from
> [Docker Hub](https://hub.docker.com/r/amr1elswaf/nour) with the
> framework, OpenSwoole, and the CLI baked in — no build step.
> See [Quick start](01-getting-started.md) for the 5-minute walkthrough.

## Quick navigation

| # | File | Topic |
|---|---|---|
| 1 | [01-getting-started.md](01-getting-started.md) | Install + first project + first handler |
| 2 | [02-configuration.md](02-configuration.md) | `setup.json` + `sitting.json` reference |
| 3 | [03-routing.md](03-routing.md) | `FilesMap.json`, handlers, the Router |
| 4 | [04-middleware.md](04-middleware.md) | PSR-15-shaped pipeline + built-ins |
| 5 | [05-events.md](05-events.md) | Dispatcher + all lifecycle events |
| 6 | [06-validation.md](06-validation.md) | `Validator::make` + 16 rules + custom |
| 7 | [07-databases.md](07-databases.md) | MySQL / Postgres / Redis pools + helpers |
| 8 | [08-websocket.md](08-websocket.md) | Handshake events, store, message dispatch |
| 9 | [09-webhooks-and-timers.md](09-webhooks-and-timers.md) | `Webhooks.json` + `Timers.json` |
| 10 | [10-plugins.md](10-plugins.md) | `ProviderInterface` + `PluginLoader` |
| 11 | [11-cli.md](11-cli.md) | `bin/nour`, 11 commands + migrations |
| 12 | [12-deployment.md](12-deployment.md) | Docker production setup |

## What Nour is

Nour is a small framework for building HTTP + WebSocket backends in
PHP on top of [OpenSwoole](https://openswoole.com/). It ships:

- Config-driven **boot** (one JSON file declares ports, services,
  and the host app's wiring class).
- A **container** with explicit contracts (no autowiring magic).
- An **event dispatcher** (PSR-14-shaped) covering HTTP requests,
  webhook handling, timer ticks, and WebSocket lifecycle.
- A **PSR-15-shaped middleware pipeline** with default middlewares
  for CORS, IP blocking, rate limiting, and request IDs.
- A **router** (`FilesMap.json`) that maps inbound `req` keys to
  handler classes — flat, fast, hot-reloadable.
- A **WebSocket layer** with pluggable socket storage (Redis or
  in-memory), handshake events, and cross-worker IPC routing.
- **Connection pools** for MySQL, PostgreSQL, and Redis, plus
  Redis-backed structures (`Queue`, `KeyValue`, `SocketRooms`).
- A **validator** with 16 built-in rules (Laravel-style declarative).
- A **service-provider system** for plugins to contribute routes,
  webhooks, and container bindings.
- A **CLI tool** (`bin/nour`) with 11 commands including a SQL
  migration runner with drift detection.

## What Nour is NOT

- An ORM / query builder. Use raw prepared SQL via
  `BaseDatabase::stmt_handle`.
- A routing DSL with URL parameters / groups / named routes. Routes
  are flat `req → handler` entries.
- A templating engine. Nour is API-only.
- A package manager hook. Plugins are listed explicitly in
  `setup.json:providers` — no Composer auto-discovery (yet).
- Mature. Version `0.2.x-dev`; expect breaking changes until `1.0`.

## When Nour fits

- You're building a real-time service (chat, presence, notifications)
  where Swoole's persistent workers + first-class WebSocket pay off.
- You want async DB pools without forklifting Laravel-Octane onto an
  existing app.
- You're comfortable writing your own SQL and reading the framework's
  source when you hit an edge case.
- The team owns the framework's source as part of the deliverable.

## When Nour is the wrong tool

- You need a CRUD app with admin panel, reports, and forms. Laravel
  will deliver in a week.
- You need `composer require some-popular-package` to "just work" —
  the ecosystem is this repo.
- Your team needs short onboarding. Nour expects readers, not users.

## Suggested reading order

1. **[01-getting-started.md](01-getting-started.md)** — get a server
   running with one handler in ~5 minutes.
2. **[02-configuration.md](02-configuration.md)** — what every key
   in `setup.json` / `sitting.json` does.
3. **[03-routing.md](03-routing.md)** — adding your own routes.
4. **[04-middleware.md](04-middleware.md)** + **[05-events.md](05-events.md)** —
   the two main extension points.
5. Pick the rest based on what you're building. WebSocket?
   Webhooks? Plugins? CLI? Each doc is independent.

## Conventions in these docs

- **File paths** are framework-relative under `src/` unless noted —
  e.g. `core/http/Main.php` lives at
  `F:/projects/nour/src/core/http/Main.php`.
- **Code samples** assume you're inside a Swoole worker (the request
  lifecycle has run). CLI-only code is called out explicitly.
- **`App`** (capitalised) is the static facade
  `Nour\Container\App`. Lowercase `app` means *your* application
  code in the `App\` namespace.
- **"Bind"** means `Container::bind(Contract, instance|factory)`.
  Bindings are per-worker.
