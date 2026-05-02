<?php

namespace Nour\Database\redis\Structures;

use Exception;
use Nour\Database\redis\Abstract\RedisStructureBase;
use Nour\Database\redis\Traits\ScriptManagerTrait;
use Redis;
use Swoole\Coroutine;

final class SocketRooms extends RedisStructureBase
{
    use ScriptManagerTrait;

    // مسارات خاصة بالسوكيتات والغرف
    protected const SOCKET_PREFIX = ":socket:";
    protected const ROOM_PREFIX = ":room:";
    protected const USER_PREFIX = ":user:";
    protected const HEARTBEAT_TRACKER = ":heartbeat:tracker";
    protected const HEARTBEAT_HISTORY = ":heartbeat:history";

    // إعدادات Heartbeat
    protected const HEARTBEAT_WARNING_THRESHOLD = 60;   // ثانية واحدة
    protected const HEARTBEAT_DISCONNECT_THRESHOLD = 90; // ثانية ونصف
    protected const HEARTBEAT_CLEANUP_INTERVAL = 300;    // 5 دقائق

    // إعدادات الحدود للسوكيتات
    protected const MAX_SOCKETS_PER_USER = 10;
    protected const MAX_ROOMS_PER_SOCKET = 50;

    /**
     * تعريف سكربتات SocketRooms
     */
    protected static function defineScripts(): void
    {


        self::$scripts = [
            'check_token' => <<<'LUA'
                local key = KEYS[1]
                local token = ARGV[1]
                local timestamp = tonumber(ARGV[2])  -- الوقت الحالي من PHP
                
                local token_key = key .. ':token:' .. token
                local HEARTBEAT_TIMEOUT = 30  -- 30 ثانية لتحديد النشاط


                -- 1. التحقق من وجود اتصال نشط بنفس التوكن باستخدام مؤشر التوكن
                local existing_socket_id = redis.call('GET', token_key)
                

                if existing_socket_id then
                    -- التحقق من أن الاتصال لا يزال نشطًا
                    local existing_key = key .. ':socket:' .. existing_socket_id
                    
                    local existing_socket_data = redis.call('HMGET', existing_key,'last_heartbeat', 'worker_id', 'nour_id', 'ip')
                    
                    local last_heartbeat = existing_socket_data[1] 
                    local existing_worker_id = existing_socket_data[2]
                    local existing_nour_id = existing_socket_data[3]
                    local existing_ip = existing_socket_data[4]

                    if last_heartbeat and type(last_heartbeat) == 'string' and tonumber(last_heartbeat) >= (timestamp - HEARTBEAT_TIMEOUT) then   
                        local result = {
                            success = 'founded',
                            existing_connection = {
                                socket_id = existing_socket_id,
                                worker_id = tonumber(existing_worker_id),
                                nour_id = existing_nour_id,
                                ip = existing_ip or '',
                                token = token
                            }
                        }
                        
                        return cjson.encode(result)

                    end
                end
                return "unfinded"
            LUA,

            'add_socket' => <<<'LUA'
                local key = KEYS[1]
                local socket_id = ARGV[1]
                local nour_id = ARGV[2]
                local token = ARGV[3]
                local ip = ARGV[4]
                local worker_id = ARGV[5]
                local timestamp = tonumber(ARGV[6])
                
                -- تعريف مفاتيح Redis
                local socket_key = key .. ':socket:' .. socket_id
                local user_key = key .. ':user:' .. nour_id
                local heartbeat_key = key .. ':heartbeat:tracker'
                local online_key = key .. ':online:users'
                local token_key = key .. ':token:' .. token
                local ip_index_key = key .. ':ip_index:' .. ip
                
                -- ⏰ الثوابت الزمنية
                local SOCKET_TTL = 3600  -- ساعة واحدة
                local HEARTBEAT_TIMEOUT = 30  -- 30 ثانية لتحديد النشاط
                local MAX_USER_SOCKETS = 10  -- الحد الأقصى للاتصالات لكل مستخدم
                
                -- 1. التحقق من وجود اتصال نشط بنفس التوكن باستخدام مؤشر التوكن
                local existing_socket_id = redis.call('GET', token_key)
                

                if existing_socket_id then
                    -- التحقق من أن الاتصال لا يزال نشطًا
                    local existing_key = key .. ':socket:' .. existing_socket_id
                    
                    local existing_socket_data = redis.call('HMGET', existing_key,'last_heartbeat', 'worker_id', 'nour_id', 'ip', 'status')
                    
                    local last_heartbeat = existing_socket_data[1] 
                    local existing_worker_id = existing_socket_data[2]
                    local existing_nour_id = existing_socket_data[3]
                    local existing_ip = existing_socket_data[4]
                    


                    if last_heartbeat and type(last_heartbeat) == 'string' and tonumber(last_heartbeat) >= (timestamp - HEARTBEAT_TIMEOUT) then                -- هناك اتصال نشط بنفس التوكن، رفض الاتصال الجديد
                        local result = {
                            success = false,
                            message = 'connection_rejected',
                            reason = 'duplicate_active_connection',
                            error_code = 'TOKEN_ALREADY_IN_USE',
                            existing_connection = {
                                socket_id = existing_socket_id,
                                worker_id = tonumber(existing_worker_id),
                                nour_id = existing_nour_id,
                                ip = existing_ip or '',
                                token = token
                            },
                            timestamp = timestamp
                        }
                        
                        return cjson.encode(result)

                    end
                end
                
                -- 2. التحقق من الحد الأقصى لاتصالات المستخدم
                local user_sockets = redis.call('SMEMBERS', user_key .. ':sockets')
                local sockets_to_remove = {}
                
                if #user_sockets >= MAX_USER_SOCKETS then

                    local result = {
                        success = false,
                        message = 'connection_rejected',
                        reason = 'MAX_USER_SOCKETS',
                        error_code = 'MAX_USER_SOCKETS'
                        }
                    return cjson.encode(result)

                end

                -- 4. تخزين بيانات السوكيت الجديد
                redis.call('HMSET', socket_key,
                    'nour_id', nour_id,
                    'token', token,
                    'ip', ip,
                    'worker_id', worker_id,
                    'connected_at', timestamp,
                    'last_heartbeat', timestamp,
                    'status', 'alive',
                    'rooms', cjson.encode({}),
                    'version', '2.0'
                )
                
                -- تعيين TTL
                redis.call('EXPIRE', socket_key, SOCKET_TTL)
                
                -- 5. تحديث المؤشرات
                redis.call('SET', token_key, socket_id, 'EX', SOCKET_TTL)  -- مؤشر التوكن
                redis.call('SADD', user_key .. ':sockets', socket_id)      -- قائمة سوكيتات المستخدم
                redis.call('SADD', ip_index_key, socket_id)                -- مؤشر IP
                redis.call('EXPIRE', ip_index_key, SOCKET_TTL)
                
                -- 6. تحديث تتبع Heartbeat
                redis.call('ZADD', heartbeat_key, timestamp, socket_id)
                
                -- 7. تحديث حالة المستخدم المتصل
                redis.call('HSET', online_key, nour_id, timestamp)
                
                -- 8. إرجاع النتيجة
                local result = {
                    success = true,
                    message = 'socket_added_successfully',
                    socket_id = socket_id,
                    socket_info = {
                        nour_id = nour_id,
                        worker_id = tonumber(worker_id),
                        ip = ip,
                        status = 'alive',
                        connected_at = timestamp
                    },
                    policy_enforced = {
                        max_connections_per_user = MAX_USER_SOCKETS,
                        one_token_per_user = true,
                        removed_connections = #sockets_to_remove
                    },
                    timestamp = timestamp
                }
                
                return cjson.encode(result)
            LUA,

            'remove_socket' => <<<'LUA'
                local key = KEYS[1]
                local socket_id = ARGV[1]
                local reason = ARGV[2] or 'manual'
                
                local socket_key = key .. ':socket:' .. socket_id
                
                -- التحقق من وجود السوكيت
                if redis.call('EXISTS', socket_key) == 0 then
                    return 'unfinded'
                end
                
                local data = redis.call('HMGET', socket_key, 'nour_id', 'token', 'ip')
                local nour_id = data[1]
                local token   = data[2]
                local ip      = data[3]

                
                -- 1. حذف مؤشر التوكن
                if token ~= false and token ~= '' then
                    redis.call('DEL', key .. ':token:' .. token)
                end
                
                -- 2. حذف مؤشر IP
                if ip and ip ~= '' then
                    local ip_index_key = key .. ':ip_index:' .. ip
                    redis.call('SREM', ip_index_key, socket_id)
                    
                    -- تنظيف مؤشر IP إذا أصبح فارغاً
                    local ip_members = redis.call('SCARD', ip_index_key)
                    if ip_members == 0 then
                        redis.call('DEL', ip_index_key)
                    end
                end
                
                -- 3. حذف من قائمة سوكيتات المستخدم
                if nour_id then
                    local user_key = key .. ':user:' .. nour_id
                    redis.call('SREM', user_key .. ':sockets', socket_id)
                    
                    -- تحديث حالة الاتصال إذا لم يتبقى سوكيتات
                    local remaining = redis.call('SCARD', user_key .. ':sockets')
                    if remaining == 0 then
                        redis.call('HDEL', key .. ':online:users', nour_id)
                    end
                end
                
                -- 4. حذف من تتبع Heartbeat
                redis.call('ZREM', key .. ':heartbeat:tracker', socket_id)
                
                -- 5. حذف السوكيت
                redis.call('DEL', socket_key)
                
                return 'removed'
            LUA,

            'update_heartbeat' => <<<'LUA'
                local key = KEYS[1]
                local socket_id = ARGV[1]
                local timestamp = tonumber(ARGV[2])
                
                local socket_key = key .. ':socket:' .. socket_id
                
                -- التحقق من وجود السوكيت
                if redis.call('EXISTS', socket_key) == 0 then
                    return cjson.encode({
                        success = false,
                        message = 'socket_not_found',
                        error_code = 'SOCKET_EXPIRED'
                    })
                end
                
                -- تحديث آخر heartbeat
                local old_heartbeat = tonumber(redis.call('HGET', socket_key, 'last_heartbeat') or 0)
                redis.call('HSET', socket_key, 'last_heartbeat', timestamp)
                
                -- تحديث حالة السوكيت بناءً على الفارق الزمني
                local time_diff = timestamp - old_heartbeat
                local socket_status = 'alive'
                
                redis.call('HSET', socket_key,
                    'status', socket_status
                )
                
                -- إعادة تعيين TTL
                redis.call('EXPIRE', socket_key, 3600)
                
                -- تحديث تتبع Heartbeat
                redis.call('ZADD', key .. ':heartbeat:tracker', timestamp, socket_id)
                
                -- تحديث حالة المستخدم المتصل
                local nour_id = redis.call('HGET', socket_key, 'nour_id')
                if nour_id then
                    redis.call('HSET', key .. ':online:users', nour_id, timestamp)
                end
                
                return cjson.encode({
                    success = true,
                    message = 'heartbeat_updated',
                    socket_id = socket_id,
                    status = socket_status,
                    time_since_last = time_diff,
                    timestamp = timestamp
                })
            LUA,

            'add_to_room' => <<<'LUA'
            local key = KEYS[1]
            local socket_id = ARGV[1]
            local room_name = ARGV[2]
            
            local socket_key = key .. ':socket:' .. socket_id
            local room_key = key .. ':room:' .. room_name .. ':members'
            
            -- التحقق من وجود السوكيت
            if redis.call('EXISTS', socket_key) == 0 then
                return 'socket_not_found'
            end
            
            -- ⏰ إعادة تعيين TTL للسوكيت
            redis.call('EXPIRE', socket_key, 3600)
            
            -- التحقق من الحد الأقصى للغرف
            local socket_rooms_json = redis.call('HGET', socket_key, 'rooms')
            local socket_rooms = cjson.decode(socket_rooms_json or '[]')
            
            if #socket_rooms >= 50 then
                -- حذف أقدم غرفة (الغرفة الأولى في القائمة)
                local oldest_room = socket_rooms[1]
                if oldest_room then
                    redis.call('SREM', key .. ':room:' .. oldest_room .. ':members', socket_id)
                    table.remove(socket_rooms, 1)
                end
            end
            
            -- إضافة إلى الغرفة
            redis.call('SADD', room_key, socket_id)
            redis.call('EXPIRE', room_key, 3600)
            
            -- تحديث قائمة غرف السوكيت
            table.insert(socket_rooms, room_name)
            redis.call('HSET', socket_key, 'rooms', cjson.encode(socket_rooms))
            
            return 'added'
        LUA,

            'remove_from_room' => <<<'LUA'
            local key = KEYS[1]
            local socket_id = ARGV[1]
            local room_name = ARGV[2]
            
            local socket_key = key .. ':socket:' .. socket_id
            local room_key = key .. ':room:' .. room_name .. ':members'
            
            redis.call('SREM', room_key, socket_id)
            
            -- تحديث قائمة غرف السوكيت
            local socket_rooms_json = redis.call('HGET', socket_key, 'rooms')
            if socket_rooms_json then
                local socket_rooms = cjson.decode(socket_rooms_json)
                local new_rooms = {}
                
                for _, room in ipairs(socket_rooms) do
                    if room ~= room_name then
                        table.insert(new_rooms, room)
                    end
                end
                
                redis.call('HSET', socket_key, 'rooms', cjson.encode(new_rooms))
            end
            
            -- تنظيف الغرف الفارغة
            local member_count = redis.call('SCARD', room_key)
            if member_count == 0 then
                redis.call('DEL', room_key)
            end
            
            return 'removed'
        LUA,

            'get_stale_sockets' => <<<'LUA'
            local key = KEYS[1]
            local threshold = tonumber(ARGV[1])
            local current_time = tonumber(ARGV[2])
            local limit = tonumber(ARGV[3]) or 100
            
            local heartbeat_key = key .. ':heartbeat:tracker'
            local cutoff_time = current_time - threshold
            
            -- الحصول على السوكيتات التي آخر heartbeat لها قبل cutoff_time
            local stale_sockets = redis.call('ZRANGEBYSCORE', heartbeat_key, 0, cutoff_time, 'LIMIT', 0, limit)
            
            local result = {}
            for _, socket_id in ipairs(stale_sockets) do
                local socket_key = key .. ':socket:' .. socket_id
                local nour_id = redis.call('HGET', socket_key, 'nour_id')
                local worker_id = redis.call('HGET', socket_key, 'worker_id')
                if nour_id then
                    table.insert(result, {
                        socket_id = socket_id,
                        worker_id = worker_id,
                        nour_id = nour_id,
                        last_heartbeat = redis.call('HGET', socket_key, 'last_heartbeat') or 0
                    })
                end
            end
            
            return cjson.encode(result)
        LUA,

            'cleanup_inactive_sockets' => <<<'LUA'
            local key = KEYS[1]
            local current_time = tonumber(ARGV[1])
            local disconnect_threshold = tonumber(ARGV[2])
            local warning_threshold = tonumber(ARGV[3])
            
            local heartbeat_key = key .. ':heartbeat:tracker'
            local disconnect_count = 0
            local warning_count = 0
            
            -- 1. قطع الاتصال بالسوكيتات التي تجاوزت disconnect_threshold
            local disconnect_cutoff = current_time - disconnect_threshold
            local to_disconnect = redis.call('ZRANGEBYSCORE', heartbeat_key, 0, disconnect_cutoff)
            
            for _, socket_id in ipairs(to_disconnect) do
                local socket_key = key .. ':socket:' .. socket_id
                local nour_id = redis.call('HGET', socket_key, 'nour_id')
                local ip = redis.call('HGET', socket_key, 'ip')
                
                -- 🎯 حذف من مؤشر الـ IP
                if ip then
                    local ip_index_key = key .. ':ip_index:' .. ip
                    redis.call('SREM', ip_index_key, socket_id)
                end
                
                -- حذف السوكيت
                redis.call('DEL', socket_key)
                redis.call('ZREM', heartbeat_key, socket_id)
                
                -- حذف من قائمة سوكيتات المستخدم
                if nour_id then
                    redis.call('SREM', key .. ':user:' .. nour_id .. ':sockets', socket_id)
                end
                
                -- إزالة من الغرف
                local socket_rooms_json = redis.call('HGET', socket_key, 'rooms')
                if socket_rooms_json then
                    local socket_rooms = cjson.decode(socket_rooms_json)
                    for _, room_name in ipairs(socket_rooms) do
                        redis.call('SREM', key .. ':room:' .. room_name .. ':members', socket_id)
                    end
                end
                
                disconnect_count = disconnect_count + 1
            end
            
            -- 2. إرسال تحذيرات للسوكيتات التي تجاوزت warning_threshold
            local warning_cutoff = current_time - warning_threshold
            local to_warn = redis.call('ZRANGEBYSCORE', heartbeat_key, disconnect_cutoff + 1, warning_cutoff)
            warning_count = #to_warn
            
            -- 3. تنظيف المستخدمين غير المتصلين
            local online_key = key .. ':online:users'
            local online_users = redis.call('HGETALL', online_key)
            local cleaned_users = 0
            
            for i = 1, #online_users, 2 do
                local nour_id = online_users[i]
                local last_activity = tonumber(online_users[i + 1])
                
                if last_activity and current_time - last_activity > 3600 then
                    -- التحقق من عدم وجود سوكيتات نشطة
                    local user_sockets = redis.call('SMEMBERS', key .. ':user:' .. nour_id .. ':sockets')
                    local has_active = false
                    
                    for _, socket_id in ipairs(user_sockets) do
                        local last_heartbeat = redis.call('HGET', key .. ':socket:' .. socket_id, 'last_heartbeat')
                        if last_heartbeat and tonumber(last_heartbeat) >= (current_time - 30) then
                            has_active = true
                            break
                        end
                    end
                    
                    if not has_active then
                        redis.call('HDEL', online_key, nour_id)
                        cleaned_users = cleaned_users + 1
                    end
                end
            end
            
            return cjson.encode({
                disconnected = disconnect_count,
                warnings_needed = warning_count,
                users_cleaned = cleaned_users,
                total_cleaned = disconnect_count + cleaned_users
            })
        LUA,

            'get_users_sockets_prods' => <<<'LUA'
            local key = KEYS[1]
            local user_ids = cjson.decode(ARGV[1])
            local max_users = 500
            local results = {}
            
            local process_count = math.min(#user_ids, max_users)
            
            for i = 1, process_count do
                local user_id = user_ids[i]
                local user_key = key .. ':user:' .. user_id .. ':sockets'
                
                local has_sockets = redis.call('SCARD', user_key)
                
                if has_sockets > 0 then
                    local socket_ids = redis.call('SMEMBERS', user_key)
                    local user_sockets = {}
                    
                    for j = 1, math.min(#socket_ids, 10) do
                        local socket_id = socket_ids[j]
                        local socket_key = key .. ':socket:' .. socket_id
                        
                        -- ✅ التصحيح هنا: HMGET يرجع مصفوفة
                        local socket_data = redis.call('HMGET', socket_key, 
                            'worker_id', 'ip'
                        )
                        
                        -- socket_data هو مصفوفة: {worker_id, ip}
                        local worker_id = socket_data[1]
                        local ip = socket_data[2]
                        
                        if worker_id then
                            table.insert(user_sockets, {
                                socket_id = socket_id,
                                worker_id = tonumber(worker_id),
                                ip = ip or ''
                            })
                        end
                    end
                    
                    results[tostring(user_id)] = user_sockets
                else
                    results[tostring(user_id)] = {}
                end
                
                if i % 100 == 0 then
                    redis.call('PING')
                end
            end
            
            if #user_ids > max_users then
                results['_warning'] = 'Processed ' .. max_users .. ' out of ' .. #user_ids .. ' users'
            end
            
            return cjson.encode(results)
        LUA,

            'get_socket_info' => <<<'LUA'
            local key = KEYS[1]
            local socket_id = ARGV[1]
            local current_time = tonumber(ARGV[2] or 0)
            
            local socket_key = key .. ':socket:' .. socket_id
            
            if redis.call('EXISTS', socket_key) == 0 then
                return cjson.encode({})  -- ⬅️ أعد {} مشفرة كـ JSON
            end
            
            -- 🎯 جلب البيانات الأساسية فقط
            local socket_data = redis.call('HMGET', socket_key,
                'nour_id', 'token', 'ip', 'worker_id',
                'connected_at', 'last_heartbeat', 'rooms', 'status'
            )
            
            -- استخراج البيانات مع قيم افتراضية آمنة
            local nour_id = socket_data[1] or ''
            local token = socket_data[2] or ''
            local ip = socket_data[3] or ''
            local worker_id_str = socket_data[4] or '0'
            local connected_at_str = socket_data[5] or '0'
            local last_heartbeat_str = socket_data[6] or '0'
            local rooms_json = socket_data[7] or '[]'
            local status = socket_data[8] or 'unknown'
            
            -- تحويل الأرقام بشكل آمن
            local worker_id = tonumber(worker_id_str) or 0
            local connected_at = tonumber(connected_at_str) or 0
            local last_heartbeat = tonumber(last_heartbeat_str) or 0
            
            -- فك تشفير JSON للغرف بشكل آمن
            local rooms = {}
            if rooms_json and rooms_json ~= '' and rooms_json ~= '[]' then
                local success, decoded = pcall(cjson.decode, rooms_json)
                if success then
                    rooms = decoded
                else
                    rooms = {}
                end
            end
            
            local result = {
                socket_id = socket_id,
                nour_id = nour_id,
                token = token,
                ip = ip,
                worker_id = worker_id,
                connected_at = connected_at,
                last_heartbeat = last_heartbeat,
                rooms = rooms,
                status = status,
                ttl_remaining = redis.call('TTL', socket_key) or 0
            }
            
            -- حساب الوقت منذ آخر heartbeat
            if current_time > 0 and last_heartbeat > 0 then
                result['seconds_since_heartbeat'] = current_time - last_heartbeat
                result['is_active'] = result['seconds_since_heartbeat'] < 30
            elseif current_time > 0 then
                result['seconds_since_heartbeat'] = current_time
                result['is_active'] = false
            end
            
            return cjson.encode(result)
        LUA,


            'broadcast_to_room' => <<<'LUA'
            local key = KEYS[1]
            local room_name = ARGV[1]
            local message_data = ARGV[2]
            local exclude_socket = ARGV[3]
            local timestamp = tonumber(ARGV[4])
            
            local room_key = key .. ':room:' .. room_name .. ':members'
            local members = redis.call('SMEMBERS', room_key)
            
            local result = {
                total_members = #members,
                sent_to = 0,
                failed = 0,
                socket_ids = {}
            }
            
            for _, socket_id in ipairs(members) do
                if socket_id ~= exclude_socket then
                    -- التحقق من نشاط السوكيت (آخر heartbeat قبل أقل من 5 دقائق)
                    local last_heartbeat = redis.call('HGET', key .. ':socket:' .. socket_id, 'last_heartbeat')
                    
                    if last_heartbeat and timestamp - tonumber(last_heartbeat) < 300 then
                        table.insert(result.socket_ids, socket_id)
                        result.sent_to = result.sent_to + 1
                    else
                        result.failed = result.failed + 1
                    end
                end
            end
            
            return cjson.encode(result)
        LUA,

            'get_user_sockets' => <<<'LUA'
                local key = KEYS[1]
                local nour_id = ARGV[1]
                
                local user_key = key .. ':user:' .. nour_id .. ':sockets'
                local socket_ids = redis.call('SMEMBERS', user_key)
                
                local result = {}
                
                for _, socket_id in ipairs(socket_ids) do
                    local socket_key = key .. ':socket:' .. socket_id
                    
                    -- ✅ الطريقة الصحيحة: HMGET يرجع مصفوفة
                    local socket_data = redis.call('HMGET', socket_key, 
                        'worker_id', 'ip', 'last_heartbeat'
                    )
                    
                    -- socket_data هو مصفوفة: {worker_id, ip, last_heartbeat}
                    local worker_id = socket_data[1]
                    local ip = socket_data[2]
                    local last_heartbeat = socket_data[3]
                    
                    if worker_id then
                        table.insert(result, {
                            socket_id = socket_id,
                            worker_id = worker_id,
                            ip = ip or '',
                            last_heartbeat = tonumber(last_heartbeat or 0)
                        })
                    end
                end
                
                return cjson.encode(result)
            LUA,

            'get_user_connections_num' => <<<'LUA'
            local key = KEYS[1]
            local nour_id = ARGV[1]
            
            local user_key = key .. ':user:' .. nour_id .. ':sockets'
            local socket_ids = redis.call('SMEMBERS', user_key)
            
            local active_count = 0
            local timestamp = tonumber(ARGV[2] or 0)
            local active_threshold = timestamp - 30
            
            for _, socket_id in ipairs(socket_ids) do
                local last_heartbeat = redis.call('HGET', key .. ':socket:' .. socket_id, 'last_heartbeat')
                
                if last_heartbeat and tonumber(last_heartbeat) >= active_threshold then
                    active_count = active_count + 1
                end
            end
            
            return active_count
        LUA,

            'is_user_online' => <<<'LUA'
            local key = KEYS[1]
            local nour_id = ARGV[1]
            local current_time = tonumber(ARGV[2])
            
            local user_key = key .. ':user:' .. nour_id .. ':sockets'
            local socket_ids = redis.call('SMEMBERS', user_key)
            
            if #socket_ids == 0 then
                return 0
            end
            
            local active_threshold = current_time - 30
            
            for _, socket_id in ipairs(socket_ids) do
                local last_heartbeat = redis.call('HGET', key .. ':socket:' .. socket_id, 'last_heartbeat')
                
                if last_heartbeat and tonumber(last_heartbeat) >= active_threshold then
                    return 1
                end
            end
            
            return 0
        LUA,

            'are_users_online_batch' => <<<'LUA'
            local key = KEYS[1]
            local user_ids_json = ARGV[1]
            local current_time = tonumber(ARGV[2])
            
            local user_ids = cjson.decode(user_ids_json)
            local results = {}
            local active_threshold = current_time - 30
            
            for i = 1, #user_ids do
                local user_id = user_ids[i]
                local user_key = key .. ':user:' .. user_id .. ':sockets'
                local socket_ids = redis.call('SMEMBERS', user_key)
                
                local online = 0
                
                if #socket_ids > 0 then
                    for _, socket_id in ipairs(socket_ids) do
                        local last_heartbeat = redis.call('HGET', key .. ':socket:' .. socket_id, 'last_heartbeat')
                        
                        if last_heartbeat and tonumber(last_heartbeat) >= active_threshold then
                            online = 1
                            break
                        end
                    end
                end
                
                results[user_id] = online
            end
            
            return cjson.encode(results)
        LUA,

            'find_sockets_by_ip' => <<<'LUA'
            local key = KEYS[1]
            local ip = ARGV[1]
            local limit = tonumber(ARGV[2]) or 100
            
            -- 🎯 استخدام مؤشر الـ IP للبحث السريع
            local ip_index_key = key .. ':ip_index:' .. ip
            local socket_ids = redis.call('SMEMBERS', ip_index_key)
            
            local result = {}
            local count = 0
            
            for _, socket_id in ipairs(socket_ids) do
                if count >= limit then
                    break
                end
                
                local socket_key = key .. ':socket:' .. socket_id
                if redis.call('EXISTS', socket_key) == 1 then
                    local nour_id, worker_id, last_heartbeat = 
                        redis.call('HMGET', socket_key, 'nour_id', 'worker_id', 'last_heartbeat')
                    
                    if nour_id then
                        table.insert(result, {
                            socket_id = socket_id,
                            nour_id = nour_id,
                            worker_id = tonumber(worker_id or 0),
                            last_heartbeat = tonumber(last_heartbeat or 0),
                            ip = ip
                        })
                        count = count + 1
                    end
                end
            end
            
            return cjson.encode({
                sockets = result,
                total_found = count
            })
        LUA,

            'advanced_ip_search' => <<<'LUA'
            local key = KEYS[1]
            local filters_json = ARGV[1]
            local filters = cjson.decode(filters_json)
            
            local search_ip = filters.ip
            local active_only = filters.active_only or true
            local worker_id = tonumber(filters.worker_id) or nil
            local limit = tonumber(filters.limit) or 100
            
            if not search_ip then
                return cjson.encode({error = "IP is required", sockets = {}})
            end
            
            local ip_index_key = key .. ':ip_index:' .. search_ip
            local socket_ids = redis.call('SMEMBERS', ip_index_key)
            
            local results = {}
            local current_time = tonumber(filters.current_time or ARGV[2] or 0)
            local active_threshold = current_time - 30
            
            for _, socket_id in ipairs(socket_ids) do
                local socket_key = key .. ':socket:' .. socket_id
                
                if redis.call('EXISTS', socket_key) == 1 then
                    local nour_id, socket_worker_id, last_heartbeat = 
                        redis.call('HMGET', socket_key, 'nour_id', 'worker_id', 'last_heartbeat')
                    
                    -- تطبيق الفلاتر
                    local passed_filters = true
                    
                    -- فلترة النشاط
                    if active_only and last_heartbeat and tonumber(last_heartbeat) < active_threshold then
                        passed_filters = false
                    end
                    
                    -- فلترة worker_id
                    if worker_id and tonumber(socket_worker_id or 0) ~= worker_id then
                        passed_filters = false
                    end
                    
                    if passed_filters and #results < limit then
                        table.insert(results, {
                            socket_id = socket_id,
                            nour_id = nour_id,
                            worker_id = tonumber(socket_worker_id or 0),
                            last_heartbeat = tonumber(last_heartbeat or 0),
                            ip = search_ip,
                            is_active = last_heartbeat and tonumber(last_heartbeat) >= active_threshold
                        })
                    end
                end
            end
            
            return cjson.encode({
                sockets = results,
                total_found = #results
            })
        LUA,
        ];
    }

    public static function getUserSockets(string $key, int $nourId): array
    {
        $redis = null;
        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return [];
            }

            $resultJson = static::executeScript(
                $redis,
                'get_user_sockets',
                [$key],
                [$nourId]
            );
            return binary_json_decode($resultJson, true) ?: [];
        } catch (Exception $e) {
            static::logError($e, "SocketRooms getUserSockets failed for nour_id: $nourId");
            return [];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }


    /**
     * الحصول على عدد الاتصالات النشطة للمستخدم
     */
    public static function getUserActiveConnectionsCount(string $key, int $nourId): int
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return 0;
            }

            $timestamp = time();

            $result = static::executeScript(
                $redis,
                'get_user_connections_num',
                [$key],
                [$nourId, $timestamp]
            );

            return (int)$result;
        } catch (Exception $e) {
            static::logError($e, "SocketRooms getUserActiveConnectionsCount failed for nour_id: $nourId");
            return 0;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }
    /**
     * إضافة سوكيت جديد
     */
    public static function addSocket(
        string $key,
        int $socketId,
        int $nourId,
        string $token,
        string $ip,
        int $workerId
    ): array {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return ['success' => false, 'message' => 'Redis connection failed'];
            }

            $timestamp = time();

            $result = static::executeScript(
                $redis,
                'add_socket',
                [$key],
                [
                    $socketId,
                    $nourId,
                    $token,
                    $ip,
                    $workerId,
                    $timestamp
                ]
            );
            //echo("key is" .$key ."just ");

            return json_decode($result, true);
        } catch (Exception $e) {
            static::logError($e, "SocketRooms addSocket failed for socket: $socketId");
            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }


    public static function checkToken($key, $token):bool|array
    {
        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }

            $result = static::executeScript(
                $redis,
                'check_token',
                [$key],
                [$token,time()]
            );
            if($result === 'unfinded'){
                return false ;                
            }else{
                return json_decode($result,true);
            }

        } catch (Exception $e) {
            static::logError($e, "SocketRooms updateHeartbeat failed for socket: $socketId");
            return false;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * تحديث Heartbeat للسوكيت
     */
    public static function updateHeartbeat(string $key, int $socketId): bool
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }

            $timestamp = time();

            $result = static::executeScript(
                $redis,
                'update_heartbeat',
                [$key],
                [$socketId, $timestamp]
            );
            return $result === 'updated';
        } catch (Exception $e) {
            static::logError($e, "SocketRooms updateHeartbeat failed for socket: $socketId");
            return false;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * إضافة سوكيت إلى غرفة
     */
    public static function addToRoom(string $key, int $socketId, string $roomName): bool
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }

            $result = static::executeScript(
                $redis,
                'add_to_room',
                [$key],
                [$socketId, $roomName]
            );

            return $result === 'added';
        } catch (Exception $e) {
            static::logError($e, "SocketRooms addToRoom failed for socket: $socketId, room: $roomName");
            return false;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * إزالة سوكيت من غرفة
     */
    public static function removeFromRoom(string $key, int $socketId, string $roomName): bool
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }

            $result = static::executeScript(
                $redis,
                'remove_from_room',
                [$key],
                [$socketId, $roomName]
            );

            return $result === 'removed';
        } catch (Exception $e) {
            static::logError($e, "SocketRooms removeFromRoom failed for socket: $socketId, room: $roomName");
            return false;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * الحصول على السوكيتات المتأخرة في Heartbeat
     */
    public static function getStaleSockets(string $key, int $thresholdSeconds, int $limit = 100): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return [];
            }

            $timestamp = time();

            $resultJson = static::executeScript(
                $redis,
                'get_stale_sockets',
                [$key],
                [$thresholdSeconds, $timestamp, $limit]
            );

            return binary_json_decode($resultJson, true) ?: [];
        } catch (Exception $e) {
            static::logError($e, "SocketRooms getStaleSockets failed");
            return [];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * تنظيف السوكيتات غير النشطة
     */
    public static function cleanupInactiveSockets(string $key): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return ['cleaned' => false, 'error' => 'Redis connection failed'];
            }

            $timestamp = time();

            $resultJson = static::executeScript(
                $redis,
                'cleanup_inactive_sockets',
                [$key],
                [
                    $timestamp,
                    self::HEARTBEAT_DISCONNECT_THRESHOLD,
                    self::HEARTBEAT_WARNING_THRESHOLD
                ]
            );

            $result = binary_json_decode($resultJson, true);

            if ($result['disconnected'] > 0) {
                $message = "Disconnected {$result['disconnected']} inactive sockets";
                //static::addWarning($key, $message, $redis);
            }

            return array_merge($result, ['cleaned' => true]);
        } catch (Exception $e) {
            static::logError($e, "SocketRooms cleanupInactiveSockets failed");
            return ['cleaned' => false, 'error' => $e->getMessage()];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * الحصول على معلومات السوكيت
     */
    public static function getSocketInfo(string $key, int $socketId): ?array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return null;
            }

            $timestamp = time();

            $resultJson = static::executeScript(
                $redis,
                'get_socket_info',
                [$key],
                [$socketId, $timestamp]
            );
            $result = binary_json_decode($resultJson, true);
            return $result ?: null;
        } catch (Exception $e) {
            static::logError($e, "SocketRooms getSocketInfo failed for socket: $socketId");
            return null;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * البث إلى غرفة
     */
    public static function broadcastToRoom(
        string $key,
        string $roomName,
        $message,
        ?int $excludeSocketId = null
    ): array {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return ['total' => 0, 'sent' => 0, 'failed' => 0];
            }

            $messageData = is_string($message) ? $message : binary_json_encode($message);
            $timestamp = time();

            $resultJson = static::executeScript(
                $redis,
                'broadcast_to_room',
                [$key],
                [
                    $roomName,
                    $messageData,
                    $excludeSocketId ?? '',
                    $timestamp
                ]
            );

            return binary_json_decode($resultJson, true) ?: ['total' => 0, 'sent' => 0, 'failed' => 0];
        } catch (Exception $e) {
            static::logError($e, "SocketRooms broadcastToRoom failed for room: $roomName");
            return ['total' => 0, 'sent' => 0, 'failed' => 0];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }
    /**
     * إزالة سوكيت من النظام
     */
    public static function removeSocket(string $key, int $socketId): bool
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }

            // 2. إزالة السوكيت باستخدام سكربت LUA
            $result = static::executeScript(
                $redis,
                'remove_socket',
                [$key],
                [$socketId]
            );
            return $result === 'removed' || $result === 'unfinded';
        } catch (Exception $e) {
            static::logError($e, "SocketRooms removeSocket failed for socket: $socketId");
            return false;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }
    /**
     * الحصول على إحصائيات النظام
     */
    public static function getStats(string $key): array
    {
        $redis = null;
        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return [];
            }

            $redis->multi(Redis::PIPELINE);

            // الحصول على إحصائيات مختلفة
            $redis->zCard($key . self::HEARTBEAT_TRACKER);
            $redis->keys($key . ':room:*:members');
            $redis->hLen($key . ':online:users');

            $responses = $redis->exec();

            list($totalSockets, $rooms, $onlineUsers) = $responses;

            $totalRooms = is_array($rooms) ? count($rooms) : 0;

            // الحصول على السوكيتات التي تحتاج لتحذير
            $warningSockets = self::getStaleSockets($key, self::HEARTBEAT_WARNING_THRESHOLD, 10);
            $disconnectSockets = self::getStaleSockets($key, self::HEARTBEAT_DISCONNECT_THRESHOLD, 10);

            $memoryInfo = static::getMemoryUsage($key, $redis);

            return [
                'structure_type' => 'socket_rooms',
                'total_sockets' => (int)$totalSockets,
                'online_users' => (int)$onlineUsers,
                'total_rooms' => $totalRooms,
                'memory_usage' => $memoryInfo,
                'heartbeat_status' => [
                    'warning_threshold' => self::HEARTBEAT_WARNING_THRESHOLD,
                    'disconnect_threshold' => self::HEARTBEAT_DISCONNECT_THRESHOLD,
                    'needs_warning' => count($warningSockets),
                    'needs_disconnect' => count($disconnectSockets),
                    'last_check' => time()
                ],
                'limits' => [
                    'max_memory_mb' => static::MAX_MEMORY_MB,
                    'max_sockets_per_user' => self::MAX_SOCKETS_PER_USER,
                    'max_rooms_per_socket' => self::MAX_ROOMS_PER_SOCKET
                ]
            ];
        } catch (Exception $e) {
            static::logError($e, "SocketRooms getStats failed for key: $key");
            return [];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }


    /**
     * التحقق من أن المستخدم اونلاين (لديه سوكيت نشط واحد على الأقل)
     * @param string $key مفتاح Redis
     * @param int $nourId معرف المستخدم
     * @return bool true إذا كان اونلاين، false إذا لم يكن
     */
    public static function isUserOnline(string $key, int $nourId): bool
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }

            $timestamp = time();

            // استدعاء سكريبت LUA المحسن
            $result = static::executeScript(
                $redis,
                'is_user_online',
                [$key],
                [$nourId, $timestamp]
            );

            return (int)$result === 1;
        } catch (Exception $e) {
            static::logError($e, "SocketRooms isUserOnline failed for nour_id: $nourId");
            return false;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * التحقق من حالة الاونلاين لعدة مستخدمين (محسن للأداء)
     * @param string $key مفتاح Redis
     * @param array $nourIds مصفوفة من معرفات المستخدمين [1, 3, 4, 5]
     * @param int $batchSize حجم الدفعة الواحدة (افتراضي 100)
     * @return array مصفوفة النتائج [1 => true, 3 => false, ...]
     */
    public static function areUsersOnlineBatch(string $key, array $nourIds, int $batchSize = 100): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                // إرجاع مصفوفة افتراضية
                $defaultResult = [];
                foreach ($nourIds as $id) {
                    $defaultResult[$id] = false;
                }
                return $defaultResult;
            }

            $timestamp = time();

            // إزالة التكرارات
            $uniqueIds = array_keys(array_flip($nourIds));

            // إذا كانت المصفوفة فارغة
            if (empty($uniqueIds)) {
                return [];
            }

            // تجزئة إلى دفعات لتحسين الأداء
            $chunks = array_chunk($uniqueIds, $batchSize);
            $allResults = [];

            foreach ($chunks as $chunk) {
                // استدعاء سكريبت LUA للدفعة
                $resultJson = static::executeScript(
                    $redis,
                    'are_users_online_batch',
                    [$key],
                    [binary_json_encode($chunk), $timestamp, $batchSize]
                );

                $chunkResults = binary_json_decode($resultJson, true) ?: [];

                // دمج النتائج
                $allResults = array_merge($allResults, $chunkResults);
            }

            // تأكد من أن جميع المعرفات موجودة في النتائج
            $finalResults = [];
            foreach ($nourIds as $id) {
                $finalResults[$id] = isset($allResults[$id]) && (bool)$allResults[$id];
            }

            return $finalResults;
        } catch (Exception $e) {
            static::logError($e, "SocketRooms areUsersOnlineBatch failed for " . count($nourIds) . " users");

            // إرجاع مصفوفة افتراضية في حالة الخطأ
            $errorResult = [];
            foreach ($nourIds as $id) {
                $errorResult[$id] = false;
            }
            return $errorResult;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * نسخة محسنة أكثر للأداء (بدون تقسيم إلى دفعات في PHP)
     * @param string $key مفتاح Redis
     * @param array $nourIds مصفوفة من معرفات المستخدمين
     * @return array مصفوفة النتائج [1 => true, 3 => false, ...]
     */
    public static function areUsersOnlineUltra(string $key, array $nourIds): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                $result = [];
                foreach ($nourIds as $id) {
                    $result[$id] = false;
                }
                return $result;
            }

            $timestamp = time();

            // إزالة التكرارات
            $uniqueIds = array_keys(array_flip($nourIds));

            if (empty($uniqueIds)) {
                return [];
            }

            // استدعاء مباشر لسكريبت LUA (يدعم حتى 5000 مستخدم في مرة واحدة)
            $resultJson = static::executeScript(
                $redis,
                'are_users_online_ultra',
                [$key],
                [binary_json_encode($uniqueIds), $timestamp, 5000] // حجم كبير لمرة واحدة
            );

            $allResults = binary_json_decode($resultJson, true) ?: [];

            // بناء النتائج النهائية
            $finalResults = [];
            foreach ($nourIds as $id) {
                $finalResults[$id] = isset($allResults[$id]) && (bool)$allResults[$id];
            }
            return $finalResults;
        } catch (Exception $e) {
            static::logError($e, "SocketRooms areUsersOnlineOptimized failed");
            $result = [];
            foreach ($nourIds as $id) {
                $result[$id] = false;
            }
            return $result;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    public static function getUsersSockets(string $key, array $userIds, int $batchSize = 300): array
    {
        if (empty($userIds)) {
            return [];
        }

        $redis = null;
        $allResults = [];

        try {
            $redis = static::getRedisConnection();
            if (!$redis) {
                // إرجاع مصفوفة فارغة سريعة
                return array_fill_keys($userIds, []);
            }

            // ✅ استخدام array_flip + array_keys لإزالة التكرارات بسرعة
            $uniqueIds = array_keys(array_flip($userIds));

            // ✅ تحويل جميع الـ IDs إلى string للتأكد من التوافق مع JSON
            $uniqueIds = array_map('strval', $uniqueIds);

            // ✅ تقسيم ذكي مع مراعاة الأداء
            $chunks = array_chunk($uniqueIds, min($batchSize, 500)); // حد أقصى 500

            foreach ($chunks as $chunkIndex => $chunk) {
                // ✅ استخدام السكريبت المناسب
                $resultJson = static::executeScript(
                    $redis,
                    'get_users_sockets_prods',
                    [$key],
                    [
                        binary_json_encode($chunk),
                        (string)time() // timestamp للتحقق من النشاط
                    ]
                );

                $chunkResults = binary_json_decode($resultJson, true) ?: [];
                //echo ($chunkResults);
                // ✅ معالجة النتائج بكفاءة
                foreach ($chunkResults as $userId => $sockets) {
                    if ($userId === '_warning' || $userId === '_max_limit') {
                        continue; // تخطي معلومات التحذير
                    }

                    // ✅ تحويل الـ socket_id إلى int إذا كان رقمياً
                    $processedSockets = [];
                    foreach ($sockets as $socket) {
                        if (isset($socket['socket_id']) && is_numeric($socket['socket_id'])) {
                            $socket['socket_id'] = (int)$socket['socket_id'];
                        }
                        if (isset($socket['worker_id']) && is_numeric($socket['worker_id'])) {
                            $socket['worker_id'] = (int)$socket['worker_id'];
                        }
                        if (isset($socket['last_heartbeat']) && is_numeric($socket['last_heartbeat'])) {
                            $socket['last_heartbeat'] = (int)$socket['last_heartbeat'];
                        }
                        $processedSockets[] = $socket;
                    }

                    $allResults[(int)$userId] = $processedSockets;
                }

                // ⏱️ استراحة قصيرة جداً بين الدفعات الكبيرة فقط
                if ($chunkIndex > 0 && count($chunk) > 200) {
                    usleep(1000); // 1ms فقط
                }
            }

            // ✅ إرجاع النتائج بنفس ترتيب الإدخال مع التعامل مع الـ IDs الغير موجودة
            $finalResults = [];
            foreach ($userIds as $userId) {
                $finalResults[$userId] = $allResults[(int)$userId] ?? [];
            }

            return $finalResults;
        } catch (Exception $e) {
            // ⚡ معالجة سريعة للأخطاء
            // تسجيل خطأ خفيف إذا كان في وضع التصحيح
            error_log("getUsersSockets error: " . $e->getMessage());


            // إرجاع مصفوفة فارغة للجميع
            return array_fill_keys($userIds, []);
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }


    public static function findSocketsByIp(string $key, string $ip, array $filters = [], $page = 1, $limit = 50): array
    {
        try {
            $redis = static::getRedisConnection();
            if (!$redis) {
                return [
                    'sockets' => [],
                    'stats' => [],
                    'pagination' => [
                        'total' => 0,
                        'page' => $page,
                        'limit' => $limit,
                        'total_pages' => 0,
                        'has_next' => false,
                        'has_prev' => false
                    ],
                    'error' => 'redis_connection_failed'
                ];
            }

            // ✅ تحقق من أن الـ IP ليس فارغاً
            if (empty($ip)) {
                return [
                    'sockets' => [],
                    'stats' => [],
                    'pagination' => [
                        'total' => 0,
                        'page' => $page,
                        'limit' => $limit,
                        'total_pages' => 0,
                        'has_next' => false,
                        'has_prev' => false
                    ],
                    'error' => 'ip_required'
                ];
            }

            // ✅ إعداد الفلاتر الأساسية
            $defaultFilters = [
                'ip' => $ip,
                'active_only' => false,
                'worker_id' => null,
                'from_date' => 0,
                'to_date' => time() + 86400, // غداً
                'page' => $page,
                'limit' => min($limit, 200), // حد أقصى 200
                'current_time' => time()
            ];

            // ✅ دمج الفلاتر الممررة مع الافتراضية
            $filters = array_merge($defaultFilters, $filters);

            // ✅ تحويل القيم المناسبة
            if (isset($filters['worker_id']) && $filters['worker_id'] !== null) {
                $filters['worker_id'] = (int)$filters['worker_id'];
            }

            if (isset($filters['active_only'])) {
                $filters['active_only'] = (bool)$filters['active_only'];
            }

            // ✅ استدعاء السكريبت
            $resultJson = static::executeScript(
                $redis,
                'advanced_ip_search',
                [$key],
                [
                    binary_json_encode($filters),
                    (string)time() // timestamp الحالي
                ]
            );

            $result = binary_json_decode($resultJson, true) ?: [];

            // ✅ التعامل مع الأخطاء
            if (isset($result['error'])) {
                return [
                    'sockets' => [],
                    'stats' => [],
                    'pagination' => [
                        'total' => 0,
                        'page' => $page,
                        'limit' => $limit,
                        'total_pages' => 0,
                        'has_next' => false,
                        'has_prev' => false
                    ],
                    'error' => $result['error']
                ];
            }

            // ✅ إرجاع النتيجة مع الهيكل المناسب
            return array_merge([
                'sockets' => [],
                'stats' => [],
                'pagination' => [
                    'total' => 0,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => 0,
                    'has_next' => false,
                    'has_prev' => false
                ]
            ], $result);
        } catch (Exception $e) {
            // ⚡ معالجة الأخطاء بدون log مكلف
            return [
                'sockets' => [],
                'stats' => [],
                'pagination' => [
                    'total' => 0,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => 0,
                    'has_next' => false,
                    'has_prev' => false
                ],
                'error' => 'exception_occurred',
                'exception_message' => $e->getMessage()
            ];
        } finally {
            if (isset($redis)) {
                static::returnRedisConnection($redis);
            }
        }
    }
    /**
     * نوع الهيكل
     */
    protected static function getStructureType(): string
    {
        return 'socket_rooms';
    }
}
