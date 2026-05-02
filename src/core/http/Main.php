<?php

namespace  Nour\core\http;

use Exception;
use Nour\Container\App;
use Nour\Contracts\Auth\AuthPipelineInterface;
use Nour\Database\BaseDatabase;
use Swoole\Coroutine;
use Swoole\Database\MysqliProxy;
use Swoole\HTTP\Response;

final class Main extends BaseDatabase
{
    /**
     * Resolves the {@see AuthPipelineInterface}, falling back to a
     * `DefaultAuthPipeline` (no-op anonymous) when the host app hasn't
     * bound one. This keeps the framework usable out-of-the-box: a
     * project that doesn't need authentication just doesn't bind
     * anything and every request flows through as anonymous.
     */
    private static function authPipeline(): AuthPipelineInterface
    {
        $bound = App::tryResolve(AuthPipelineInterface::class);
        return $bound ?? new DefaultAuthPipeline();
    }

    public static function start(array $the_data, ?MysqliProxy $mysql, string $ip, Response $response, ?array $finger_print, array $headers = []): void
    {
        if (empty($the_data['req']) || empty($the_data['data'])) {

            $response->status(499);
            $response->end('Type the main data - API Syntax Error');
            return;
        }

        $requestKey = $the_data['req'];
        $rawData = $the_data['data'];

        try {
            // depth = 32 كافي لأي real payload؛ منع DoS عبر deeply-nested JSON.
            $data = json_decode($rawData, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $response->status(783);
            $response->end('Invalid JSON data');
            return;
        }

        try {
            if (empty($data)) {
                throw new Exception('Your data is empty', 401);
            }

            // ⭐ Phase 3 (v0.2): The auth pipeline is now a contract,
            // resolved through the container. Default = no-op anonymous;
            // gooabb binds GooabbAuthPipeline. Other apps can swap in
            // anything implementing AuthPipelineInterface.
            //
            // The pipeline always runs (even when API key is empty) so
            // it can decide for itself how to handle anonymous traffic
            // — some pipelines reject, some attach a guest user, the
            // default just returns user_id=0.
            $apiKey  = (string) ($the_data['API'] ?? '');
            $payload = $headers;
            $payload['fingerprint'] = $finger_print; // pipelines that need it
            $userData = self::authPipeline()->authenticate(
                $mysql,
                $apiKey,
                $ip,
                $payload,
                $requestKey
            );
            $user = [
                'id'   => (int) ($userData['user_id'] ?? 0),
                'role' => $userData['role'] ?? null,
                'API'  => $apiKey,
            ];

            // إضافة req إلى user
            $user['req'] = $requestKey;

            /*// حفظ كل البيانات في Context
            $ctx = Coroutine::getContext();
            $ctx->app = [
                "requestKey"=>$requestKey,
                "data"=>$data,
                "user"=>$user,



            ]*/
            $ctx = Coroutine::getContext();
            $ctx['type'] = 'h'; // نوع الطلب http
            // البيانات الأساسية
            $ctx['data'] = $data; //object
            $ctx['user'] = $user; // array with id, role, API, req
            $ctx['mysql'] = $mysql; // MysqliProxy
            $ctx['ip'] = $ip; // string
            $ctx['path'] = $requestKey; // string
            $ctx['response'] = $response;// Response object
            // تنفيذ الطلب
            Router::dispatch($requestKey, $user);

            // إذا لم يتم إرسال response، نرجع array فارغة
            return;
        } catch (ApiResponse $r) {
            return;
        } catch (\Exception $e) {
            json_encode(self::handleError($e, $response));
        }
    }

    private static function handleError(\Exception $e, Response $response): void
    {
        $code    = $e->getCode();
        $message = $e->getMessage();

        // الـ 4xx بيتم رميها صراحةً بـ throw new Exception(...) من Router/Security
        // ومعاهم رسائل safe-to-display (e.g., "Authentication required",
        // "Route not found: ..."). الـ 5xx أو الـ code=0 (default exceptions
        // مثل DB errors) — نسجّلها داخليًا ونرجّع generic للعميل.
        switch ($code) {
            case 400:
                self::error_r($message, $response, $code, 'validation', 'bad_request');
                return;
            case 401:
                self::error_r($message, $response, $code, 'auth', 'unauthorized');
                return;
            case 403:
                self::error_r($message, $response, $code, 'auth', 'forbidden');
                return;
            case 404:
                self::error_r($message, $response, $code, 'router', 'not_found');
                return;

            default:
                error_log("[Main::handleError] " . $message . "\n" . $e->getTraceAsString());
                $httpCode = ($code >= 500 && $code < 600) ? $code : 500;
                self::error_r('server error', $response, $httpCode, 'system', 'server_error');
                return;
        }
    }

    private static function error_r(string $msg,Response $response, int $code = 500, string $part = 'unknown',string $name = 'error'): void
    {

        $response->status($code);
        $response->end(json_encode([
            'error' => $msg,
            'part' => $part,
            'code' => $name
        ]));
    }
}
