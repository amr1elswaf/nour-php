<?php

namespace Nour\core\server\Http;

use Nour\Container\App;
use Nour\Contracts\Http\RequestHandlerInterface;
use Nour\core\http\DefaultHttpRequestHandler;
use Nour\core\Timers;
use Nour\core\server\Webhook\WebhookRouter;
use Nour\Database\redis\RedisManager;
use Nour\Database\RedisDatabase;
use Nour\core\http\Router;
use Nour\helpers\BlockIp;
use Nour\helpers\ClientIp;
use Nour\Plugin\PluginLoader;
use Swoole\HTTP\Request;
use Swoole\HTTP\Response;
use Throwable;

class HttpRequestHandleLogic
{
    /**
     * Per-listener dispatcher. The shim is intentionally minimal —
     * everything app-shaped lives in:
     *
     *  - Port 9501 → {@see RequestHandlerInterface} resolved from the
     *    container (default: {@see DefaultHttpRequestHandler}). Apps
     *    bind their own handler in `data/setup.json:services.http.handler`
     *    or programmatically.
     *
     *  - Port 9502 → {@see WebhookRouter}, config-driven via
     *    `data/Webhooks.json`.
     *
     * The IP block-list gate runs BEFORE either branch so banned IPs
     * can't sneak through a non-API endpoint.
     */
    public static function onRequest(Request $request, Response $response): void
    {
        $port = $request->server['server_port'] ?? 9501;

        // Early IP block-list gate — applies to every port.
        $ip = ClientIp::fromRequest($request);
        if ((new BlockIp())->isBlocked($ip)) {
            $response->status(403);
            $response->header('Content-Type', 'application/json; charset=utf-8');
            $response->end(json_encode([
                'error' => 'IP blocked',
                'part'  => 'firewall',
                'code'  => 'ip_blocked',
            ], JSON_UNESCAPED_UNICODE));
            return;
        }

        if ($port == 9501) {
            // Phase 4: delegate to whichever RequestHandlerInterface the
            // app bound, defaulting to DefaultHttpRequestHandler. This
            // lets host apps own the per-request lifecycle (read custom
            // headers, run custom rate-limit decisions, wrap responses)
            // by implementing the interface and binding it.
            $handler = App::tryResolve(RequestHandlerInterface::class)
                ?? new DefaultHttpRequestHandler();
            $handler->handle($request, $response);
            return;
        }

        if ($port == 9502) {
            WebhookRouter::dispatch($request, $response);
            return;
        }

        // Unknown port → 404 rather than silent hang.
        $response->status(404);
        $response->end();
    }


    public static function onTask(...$args)
    {
        $server = $args[0];

        // تحديد نوع الـ parameters بناءً على إصدار Swoole
        if ($args[1] instanceof Swoole\Server\Task) {
            // إصدار حديث (Task object)
            $task = $args[1];
            $task_id = $task->id;
            $src_worker_id = $task->worker_id;
            $data = $task->data;
        } else {
            // إصدار قديم (separate parameters)
            $task_id = $args[1];
            $src_worker_id = $args[2];
            $data = $args[3];
        }

        echo "📨 Task #{$task_id} received from worker #{$src_worker_id}\n";

        if (isset($data['action']) && $data['action'] === 'redisRecovered') {
            echo "🔄 إعادة تهيئة Redis مطلوبة\n";

            try {
                RedisManager::initializeAll();
                echo "✅ تمت إعادة تهيئة Redis\n";
            } catch (Throwable $e) {
                echo "❌ فشل إعادة تهيئة Redis: " . $e->getMessage() . "\n";
            }
        }

        $server->finish("Task #{$task_id} completed");
    }

    public static function onStart($server)
    {

        echo "Swoole http server is started at http://127.0.0.1:9501\n";
        Timers::init();
        RedisDatabase::checkConnect();
        /*
    swoole_timer_tick(10000,function()use($main){
        $main->read_files();
    });
    swoole_timer_tick(10000,function()use($timers){
        $timers->monitorChanges();
    });*/


        /*
    // إضافة مراقبة الأداء
    swoole_timer_tick(5000, function() use ($server) {
        // استهلاك الذاكرة
        $memoryUsage = memory_get_usage(true); // استخدام الذاكرة بالبايت
        $memoryPeakUsage = memory_get_peak_usage(true); // أقصى استخدام للذاكرة
        echo "Memory Usage: " . formatBytes($memoryUsage) . " | Peak Memory Usage: " . formatBytes($memoryPeakUsage) . "\n";
        
        // استهلاك المعالج (CPU)
        $load = sys_getloadavg(); // استرجاع متوسط تحميل المعالج لآخر 1، 5، 15 دقيقة
        echo "CPU Load: 1m = " . $load[0] . "% | 5m = " . $load[1] . "% | 15m = " . $load[2] . "%\n";
    });*/
    }

    public static function workerStart($server, $workerId)
    {
        echo "[Worker #{$workerId}] Starting...\n";
        // ⭐ إعداد message handler
        if (method_exists($server, 'onMessage')) {
            $server->onMessage(function ($server, $message) use ($workerId) {
                $data = json_decode($message, true);
                if ($data['type'] === 'redis_recovered') {
                    echo "✅ Worker #{$workerId} تلقى إشعار بعودة Redis\n";
                    RedisManager::initializeAll();
                }
            });
        }




        try {
            echo "🔄 تهيئة Router و WebhookRouter و Redis Worker #{$workerId}\n";

            // Phase 2 / Session 5: providers contribute extra routes
            // and webhooks. PluginLoader::loadAll() ran earlier in
            // Boot::wireEvents (before this method), so collect lists
            // are populated by the time we initialize the routers.
            $extraRoutes   = PluginLoader::collectRoutes();
            $extraWebhooks = PluginLoader::collectWebhooks();

            Router::init(
                configPath:  realpath($GLOBALS['main_folder'].'/data/FilesMap.json'),
                extraRoutes: $extraRoutes,
            );

            // Phase 3: framework no longer ships a Security class. The
            // host app binds an AuthPipelineInterface in Bootstrap; if
            // it doesn't, Main::start falls back to DefaultAuthPipeline
            // (anonymous-only).

            // Webhooks are optional — boot succeeds even if Webhooks.json
            // is missing. The router responds 503 to incoming requests
            // until the file appears.
            $webhookConfig = $GLOBALS['main_folder'] . '/data/Webhooks.json';
            WebhookRouter::init($webhookConfig, $extraWebhooks);

            RedisManager::initializeAll();
        } catch (Throwable $e) {
            echo ($e->getMessage());
        }

        echo "[Worker #{$workerId}] Ready\n";
    }
}
