<?php

namespace Nour\core\socket;

use Nour\Database\redis\Structures\SocketRooms;


class MessageManger
{
    /**
     * إرسال رسالة إلى مستخدم
     */
    public static function sendToUser(int $userId, array $message, bool $has_photos = false): bool
    {
        if (SocketRooms::isUserOnline($GLOBALS['socket_key'], $userId)) {
            return GlobalRegistry::sendToUser($userId, $message,$has_photos);
        } else {
            return false;
        }
    }
    /**
     * ادخال البينات بشكل مجمع
     * الصيغة تكون 
     * [
     *  ['user_id' => UserID, "message" => ['type' => Type, "data" => Array() ]]
     * ]
     * @param array $notification
     * @return array
     */
    public static function MultiNotify(array $notifications, bool $online_check = false): void
    {
        GlobalRegistry::MultiNotify($notifications,$online_check);
    }
    /**
     * إرسال إشعار بسيط
     */
    public static function notify(int $userId, string $type, array $data, bool $check_online = true): bool
    {
        $message = ['user_id'=>$userId,"message"=>["type"=>$type,'data'=>$data]];
        //var_dump($message); 
        self::MultiNotify([$message]);
        return true ;
         if ($check_online) {
            if (!SocketRooms::isUserOnline($GLOBALS['socket_key'], $userId)) {
                //echo "user is not online \n \n";
                return true;
            }
        }
        GlobalRegistry::notify($userId,$type,$data);
        return true;
        //return Queue::enqueue(self::message_queue, ['user_id' => $userId, "message" => ['type' => $type, "data" => $data]]);
    }
    /**
     * ارسال رسالة الي عدة مستخدمين
     * @param array $userIds
     * @param string $type
     * @param array $message
     * @return bool
     */
    public static function sendOneMessagesToMultiUsers(array $userIds,  array $message, bool $has_photos): bool
    {
        return GlobalRegistry::sendOneMessagesToMultiUsers($userIds,$message,$has_photos);

    }
    /**
     * البث إلى غرفة
     */
    public static function broadcast(string $roomName, array $message): int
    {

        return GlobalRegistry::broadcast($roomName, $message);
    }

    public static function chat_message(int $userId, string $type, array $data, bool $check_online = true): bool
    {
         self::MultiNotify([['user_id'=>$userId,"message"=>["type"=>$type,'data'=>$data]]]);
        return true ;
         if ($check_online) {
            if (!SocketRooms::isUserOnline($GLOBALS['socket_key'], $userId)) {
                //echo "user is not online \n \n";
                return true;
            }
        }
        GlobalRegistry::notify($userId,$type,$data);
        return true;
        //return Queue::enqueue(self::message_queue, ['user_id' => $userId, "message" => ['type' => $type, "data" => $data]]);
    }

}


/*
class MessageManger
{
    private const message_queue = "nuor:message_queue";
    private static $rooms_key = 'nuor:socket_system';
    /**
     * إرسال رسالة إلى مستخدم
     */
    /*public static function sendToUser(int $userId, array $message): bool
    {
        //$this->rooms_key = $GLOBALS['socket_key'];

        if (!SocketRooms::isUserOnline($GLOBALS['socket_key'], $userId)) {
            //echo "user is not online \n \n";
            return true;
        }
        //return GlobalRegistry::sendToUser($userId,$message);
        return Queue::enqueue(self::message_queue, ['user_id' => $userId, "message" => ['type' => 'message', "data" => $message]]);
    }
    /**
     * ادخال البينات بشكل مجمع
     * الصيغة تكون 
     * [
     *  ['user_id' => UserID, "message" => ['type' => Type, "data" => Array() ]]
     * ]
     * @param array $notification
     * @return array
     */
    /*public static function MultiNotify(array $notification): array
    {
        return Queue::enqueueMultiple(self::message_queue, $notification, 200);
    }
    /**
     * إرسال إشعار بسيط
     */
    /*public static function notify(int $userId, string $type, array $data = []): bool
    {

        if (!SocketRooms::isUserOnline($GLOBALS['socket_key'], $userId)) {
            //echo "user is not online \n \n";
            return true;
        }
        //echo "hello world !";
        //return GlobalRegistry::notify($userId,$data);
        //GlobalRegistry::notify($userId,$type,$data);

        return Queue::enqueue(self::message_queue, ['user_id' => $userId, "message" => ['type' => $type, "data" => $data]]);
    }
    /**
     * ارسال رسالة الي عدة مستخدمين
     * @param array $userIds
     * @param string $type
     * @param array $message
     * @return bool
     */
    /*public static function sendOneMessagesToMultiUsers(array $userIds, string $type, array $message, bool $check_online = true): bool
    {
        if ($check_online) {
            $is_users_online = SocketRooms::areUsersOnlineUltra($GLOBALS['socket_key'], $userIds);
            $data = [];
            foreach ($is_users_online as $id => $is_online) {
                if ($is_online) {
                    $data[] = ['user_id' => $id, "message" => ['type' => $type, "data" => $message]];
                }
            }
        }else{
            foreach ( $userIds as $id ) {
                    $data[] = ['user_id' => $id, "message" => ['type' => $type, "data" => $message]];
            }
        }

        //return GlobalRegistry::sendToUser($userId,$message);
        Queue::enqueueMultiple(self::message_queue, $data);
        return true;
    }
    /**
     * البث إلى غرفة
     */
    /*public static function broadcast(string $roomName, array $message): int
    {

        return GlobalRegistry::broadcast($roomName, $message);
    }
}
*/