<?php

namespace Nour\core\socket;

use Nour\Database\BaseDatabase;
use Exception;
use Nour\core\http\ApiResponse;
use Nour\core\http\Router;
use Swoole\Database\MysqliProxy;
use Swoole\Coroutine;

final class SocketMain extends BaseDatabase
{

    public static function start(array $the_data, MysqliProxy $mysql, int $socket_id, SocketManager $manger): void
    {

        if (empty($the_data['req']) || empty($the_data['data'])) {
            $manger->sendMessage($socket_id, ["type" => "error", "data" => ['error' => 'Type the main data - Request Syntax Error']]);
            return;
        }

        $requestKey = $the_data['req'];
        $data =(object) $the_data['data'];
        /*if(!($data)){
            $manger->sendMessage($socket_id, ["type" => "error", "data" => ['error' => 'Type the main data - Request Syntax Error']]);
            return;
        }*/
        
       

        try {
            $ctx = Coroutine::getContext();
            // البيانات الأساسية
            $user_data = $manger->get_from_local_sockets($socket_id);
            if (empty($user_data)) {
                $manger->sendMessage($socket_id, ['type' => 'error', "data" => ['error' => 'please reconnect']]);
                return;
            }

            // ⭐ user shape مطابق للـ HTTP path (شوف Main.php) عشان الـ Router
            // يقدر يطبّق نفس الـ auth_level + permissions + ban checks.
            // غياب 'role' يعني المستخدم مكمّلش بروفايله — يتعامل بصلاحيات
            // محدودة (المسارات اللي مش بتطلب role هتعدي، اللي بتطلبه هتترفض).
            $user = [
                'id'   => $user_data['user_id'],
                'role' => $user_data['role'] ?? null,
                'API'  => $user_data['api_key'] ?? '',
                'req'  => $the_data['req'],
            ];

            $ctx['manger']    = $manger;
            $ctx['type']      = 's';
            $ctx['data']      = $data;
            $ctx['user']      = $user;
            $ctx['mysql']     = $mysql;
            $ctx['socket_id'] = $socket_id;
            $ctx['ip']        = $user_data['ip'];
            $ctx['path']      = $requestKey;

            // ⚠ check_auth=true دلوقتي. قبل كده كان false وده كان معناه إن أي
            // WS command معلّم `up: ["Teacher"]` مثلًا يقدر يستدعيه أي طالب.
            Router::dispatch($requestKey, $user, true);

            // إذا لم يتم إرسال response، نرجع array فارغة
            return;
        } catch (ApiResponse $r) {
            return;
        } catch (\Exception $e) {
            // Router بيرمي Exception بكود 4xx (Route not found / Auth required /
            // ...) — دي رسائل آمنة للعميل. أي Exception تاني (بكود 0 أو 5xx)
            // غالبًا SQL/internal error — نسجّله ونرجّع رسالة عامة بدل ما نسرّب
            // schema details (شوف H1).
            $code = (int) $e->getCode();
            if ($code >= 400 && $code < 500) {
                $manger->sendMessage($socket_id, ["type" => "error", "data" => ['error' => $e->getMessage()]]);
            } else {
                error_log("SocketMain dispatch error [req={$requestKey}]: " . $e->getMessage());
                $manger->sendMessage($socket_id, ["type" => "error", "data" => ['error' => 'internal error']]);
            }
            return;
        }
    }
}
