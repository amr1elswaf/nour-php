# فريم وورك Nour — التوثيق

فريم وورك PHP صغير مبني على Swoole، مخصص لباك إند يدمج HTTP +
WebSocket. كل ملف مرقّم بالأسفل بيغطي موضوع واحد؛ اقراهم بالترتيب،
أو روح مباشرة لاللي محتاجه.

> **جربه دلوقت**
>
> ```bash
> docker pull amr1elswaf/nour:latest
> ```
>
> الصورة منشورة على
> [Docker Hub](https://hub.docker.com/r/amr1elswaf/nour) ومعاها
> الفريم وورك + OpenSwoole + الـ CLI جاهزين — من غير bana خطوة بناء.
> شوف [البداية السريعة](01-getting-started.md) للشرح في 5 دقايق.

## الفهرس

| # | الملف | الموضوع |
|---|---|---|
| 1 | [01-getting-started.md](01-getting-started.md) | التثبيت + أول مشروع + handler "Hello" |
| 2 | [02-configuration.md](02-configuration.md) | كل مفتاح في `setup.json` + `sitting.json` |
| 3 | [03-routing.md](03-routing.md) | `FilesMap.json`، الـ handlers، الـ Router |
| 4 | [04-middleware.md](04-middleware.md) | الـ Pipeline + 4 middlewares + كتابة واحد جديد |
| 5 | [05-events.md](05-events.md) | الـ Dispatcher + كل lifecycle events |
| 6 | [06-validation.md](06-validation.md) | `Validator::make` + 16 قاعدة + قواعد مخصصة |
| 7 | [07-databases.md](07-databases.md) | MySQL / Postgres / Redis pools + الـ helpers |
| 8 | [08-websocket.md](08-websocket.md) | Handshake events، الـ store، dispatch |
| 9 | [09-webhooks-and-timers.md](09-webhooks-and-timers.md) | `Webhooks.json` + `Timers.json` |
| 10 | [10-plugins.md](10-plugins.md) | `ProviderInterface` + `PluginLoader` |
| 11 | [11-cli.md](11-cli.md) | `bin/nour`، 11 أمر + migrations |
| 12 | [12-deployment.md](12-deployment.md) | إعداد Docker للإنتاج |

## ايه هو Nour

Nour فريم وورك صغير لبناء HTTP + WebSocket backends في PHP فوق
[OpenSwoole](https://openswoole.com/). بيشحن:

- **Boot** مدفوع بالـ config (ملف JSON واحد بيعرّف البورتات،
  الخدمات، وكلاس الـ wiring بتاع التطبيق).
- **Container** مع contracts صريحة (مفيش autowiring سحري).
- **Event Dispatcher** (مطابق لـ PSR-14) بيغطي HTTP requests،
  webhook handling، timer ticks، و WebSocket lifecycle.
- **Middleware Pipeline مطابق لـ PSR-15** مع middlewares افتراضية
  لـ CORS، حظر الـ IPs، rate limiting، و request IDs.
- **Router** (`FilesMap.json`) بيـ map كل `req` لـ handler — مسطح،
  سريع، يدعم hot-reload.
- **WebSocket Layer** مع socket store يقدر يبقا Redis أو in-memory،
  handshake events، و IPC routing بين الـ workers.
- **Connection Pools** لـ MySQL، PostgreSQL، Redis، بالإضافة لـ
  Redis-backed structures (`Queue`، `KeyValue`، `SocketRooms`).
- **Validator** فيه 16 قاعدة جاهزة (Laravel-style declarative).
- **نظام Service Provider** للإضافات تساهم بـ routes، webhooks،
  container bindings.
- **CLI** (`bin/nour`) بـ 11 أمر تشمل SQL migration runner مع
  drift detection.

## ايه اللي مش موجود في Nour

- ORM / Query Builder. استعمل prepared SQL مباشرة عبر
  `BaseDatabase::stmt_handle`.
- Routing DSL فيه URL parameters / groups / named routes. الـ routes
  مسطحة `req → handler`.
- Templating engine. Nour API-only.
- Composer auto-discovery للإضافات. الإضافات بتتسجل صراحة في
  `setup.json:providers` — مش لسه.
- ناضج. الإصدار الحالي `0.2.x-dev`؛ توقع breaking changes لحد `1.0`.

## امتى Nour يكون مناسب

- بتبني خدمة real-time (شات، حضور، إشعارات) محتاج فيها workers
  دائمين + WebSocket من النوع الأصلي.
- عايز async DB pools من غير ما تركّب Laravel-Octane على تطبيق قائم.
- مرتاح إنك تكتب SQL بنفسك وتقرا الـ source لو ضربت في edge case.
- الفريق بيمتلك source الفريم وورك كجزء من الـ deliverable.

## امتى Nour يكون خاطئ

- عايز CRUD app مع admin panel، تقارير، فورمز. Laravel هيخلصها في أسبوع.
- عايز `composer require some-package` يشتغل تلقائي — الـ ecosystem
  هو الـ repo دا.
- الفريق محتاج onboarding سريع. Nour بيتوقع قراءات، مش مستخدمين.

## الترتيب المقترح للقراءة

1. **[01-getting-started.md](01-getting-started.md)** — تشغيل سيرفر
   مع handler واحد في 5 دقايق.
2. **[02-configuration.md](02-configuration.md)** — إيه كل مفتاح
   في الـ config.
3. **[03-routing.md](03-routing.md)** — إضافة routes خاصة بيك.
4. **[04-middleware.md](04-middleware.md)** + **[05-events.md](05-events.md)** —
   نقطتين الـ extension الرئيسيتين.
5. الباقي حسب ما بتبنيه. WebSocket؟ Webhooks؟ Plugins؟ CLI؟ كل ملف
   مستقل.

## اصطلاحات في التوثيق

- **مسارات الملفات** نسبية لـ `src/` تحت الفريم وورك. يعني
  `core/http/Main.php` يعني `F:/projects/nour/src/core/http/Main.php`.
- **أمثلة الكود** بتفترض إنك جوا Swoole worker (الـ request
  lifecycle اشتغل). كود الـ CLI بس بيتعرّف صراحة.
- **`App`** (بـ كابيتال) هي الـ static facade `Nour\Container\App`.
  `app` بـ سمول معناها كود التطبيق بتاعك في namespace `App\`.
- **"Bind"** يعني `Container::bind(Contract, instance|factory)`.
  الـ bindings per-worker.
