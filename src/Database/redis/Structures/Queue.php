<?php

namespace Nour\Database\redis\Structures;

use Exception;
use Nour\Database\redis\Abstract\RedisStructureBase;
use Nour\Database\redis\Contracts\QueueInterface;
use Nour\Database\redis\Traits\ScriptManagerTrait;
use Redis;

final class Queue extends RedisStructureBase implements QueueInterface
{
    use ScriptManagerTrait;

    /**
     */
    protected static function defineScripts(): void
    {
        self::$scripts = [
            'enqueue' => <<<'LUA'
                local key = KEYS[1]
                local elementData = ARGV[1]
                local elementSize = tonumber(ARGV[2])
                local metadata = ARGV[3]
                local ttl = ARGV[4]
                
                -- توليد ID متسلسل
                local seq = redis.call('INCR', key .. ':seq')
                local elementId = tostring(seq)
                
                local hashKey = key .. ':hash:' .. elementId
                local zsetKey = key .. ':zset'
                local queueListKey = key .. ':queue'
                local totalSizeKey = key .. ':stats:total_size'
                local sizeKey = key .. ':stats:item_sizes'
                
                -- التحقق من حدود الذاكرة قبل الإضافة
                local currentSize = tonumber(redis.call('GET', totalSizeKey) or 0)
                local maxMemoryBytes = 524288000 -- 500MB
                
                -- إذا تجاوزنا الحد، نحذف أقدم عنصر
                if currentSize + elementSize > maxMemoryBytes then
                    -- جلب أقدم عنصر
                    local oldestId = redis.call('LINDEX', queueListKey, 0)
                    if not oldestId then
                        oldestId = redis.call('ZRANGE', zsetKey, 0, 0)[1]
                    end
                    
                    if oldestId then
                        local oldestHashKey = key .. ':hash:' .. oldestId
                        local oldestSize = tonumber(redis.call('ZSCORE', sizeKey, oldestId) or 0)
                        
                        -- حذف العنصر القديم
                        redis.call('DEL', oldestHashKey)
                        redis.call('ZREM', zsetKey, oldestId)
                        redis.call('LREM', queueListKey, 1, oldestId)
                        redis.call('ZREM', sizeKey, oldestId)
                        
                        -- تحديث الحجم الكلي
                        currentSize = math.max(0, currentSize - oldestSize)
                        redis.call('SET', totalSizeKey, currentSize)
                        
                        -- تسجيل تحذير
                        redis.call('LPUSH', key .. ':warnings', 'Memory limit exceeded, removed oldest item: ' .. oldestId)
                        redis.call('LTRIM', key .. ':warnings', 0, 99)
                    end
                end
                
                -- تخزين البيانات
                redis.call('HSET', hashKey, 'data', elementData, 'metadata', metadata)
                
                -- إضافة إلى ZSET
                redis.call('ZADD', zsetKey, seq, elementId)
                
                -- إضافة إلى قائمة الانتظار (في النهاية)
                redis.call('RPUSH', queueListKey, elementId)
                
                -- زيادة العداد
                redis.call('INCR', key .. ':counter')
                
                -- تحديث الحجم الكلي
                redis.call('INCRBY', totalSizeKey, elementSize)
                
                -- تتبع حجم العنصر الفردي
                redis.call('ZADD', sizeKey, elementSize, elementId)
                
                -- تعيين TTL إذا كان محدداً
                if ttl ~= '' and tonumber(ttl) > 0 then
                    redis.call('EXPIRE', hashKey, ttl)
                end
                
                -- التحذير إذا اقتربنا من الحد
                local newSize = currentSize + elementSize
                local warningThreshold = maxMemoryBytes * 0.8
                if newSize > warningThreshold and newSize - elementSize <= warningThreshold then
                    redis.call('LPUSH', key .. ':warnings', 'Memory usage exceeded 80% of limit')
                    redis.call('LTRIM', key .. ':warnings', 0, 99)
                end
                
                return elementId
LUA,

            'enqueue_batch' => <<<'LUA'
                local key = KEYS[1]
                local elementsData = cjson.decode(ARGV[1])
                local maxItems = 1000000
                local maxMemoryBytes = 524288000
                
                local seqKey = key .. ':seq'
                local counterKey = key .. ':counter'
                local totalSizeKey = key .. ':stats:total_size'
                local zsetKey = key .. ':zset'
                local sizeKey = key .. ':stats:item_sizes'
                local queueListKey = key .. ':queue'
                
                -- الحصول على الأحجام الحالية
                local currentSize = tonumber(redis.call('GET', totalSizeKey) or 0)
                local currentCount = tonumber(redis.call('GET', counterKey) or 0)
                
                -- حساب الحجم الإجمالي للدفعة
                local batchTotalSize = 0
                for _, element in ipairs(elementsData) do
                    batchTotalSize = batchTotalSize + element.size
                end
                
                -- التحقق من الحدود وحذف العناصر القديمة إذا لزم الأمر
                local removedCount = 0
                while (currentCount + #elementsData > maxItems) or (currentSize + batchTotalSize > maxMemoryBytes) do
                    local oldestId = redis.call('LPOP', queueListKey)
                    if not oldestId then
                        break
                    end
                    
                    local oldestHashKey = key .. ':hash:' .. oldestId
                    local oldestSize = tonumber(redis.call('ZSCORE', sizeKey, oldestId) or 0)
                    
                    redis.call('DEL', oldestHashKey)
                    redis.call('ZREM', zsetKey, oldestId)
                    redis.call('ZREM', sizeKey, oldestId)
                    
                    currentSize = math.max(0, currentSize - oldestSize)
                    currentCount = math.max(0, currentCount - 1)
                    removedCount = removedCount + 1
                    
                    -- تحديث بعد كل حذف
                    redis.call('SET', totalSizeKey, currentSize)
                    redis.call('SET', counterKey, currentCount)
                end
                
                -- تسجيل تحذير إذا تم حذف عناصر
                if removedCount > 0 then
                    redis.call('LPUSH', key .. ':warnings', 'Memory/Item limit exceeded, removed ' .. removedCount .. ' oldest items')
                    redis.call('LTRIM', key .. ':warnings', 0, 99)
                end
                
                -- إضافة العناصر الجديدة
                local currentSeq = redis.call('GET', seqKey) or 0
                currentSeq = tonumber(currentSeq)
                
                local elementIds = {}
                local addedSize = 0
                
                for i, element in ipairs(elementsData) do
                    local seq = currentSeq + i
                    local elementId = tostring(seq)
                    
                    local hashKey = key .. ':hash:' .. elementId
                    
                    -- تخزين البيانات
                    redis.call('HSET', hashKey, 'data', element.data, 'metadata', element.metadata)
                    
                    -- إضافة إلى ZSET
                    redis.call('ZADD', zsetKey, seq, elementId)
                    
                    -- إضافة إلى قائمة الانتظار
                    redis.call('RPUSH', queueListKey, elementId)
                    
                    -- تحديث إحصائيات الحجم
                    addedSize = addedSize + element.size
                    redis.call('ZADD', sizeKey, element.size, elementId)
                    
                    table.insert(elementIds, elementId)
                end
                
                -- تحديث sequence
                redis.call('INCRBY', seqKey, #elementsData)
                
                -- تحديث العداد والحجم الكلي
                redis.call('INCRBY', counterKey, #elementsData)
                redis.call('INCRBY', totalSizeKey, addedSize)
                
                return cjson.encode(elementIds)
LUA,

            'dequeue' => <<<'LUA'
                local key = KEYS[1]
                
                local zsetKey = key .. ':zset'
                local queueListKey = key .. ':queue'
                local counterKey = key .. ':counter'
                local totalSizeKey = key .. ':stats:total_size'
                local sizeKey = key .. ':stats:item_sizes'
                
                -- الحصول على أقدم عنصر من قائمة الانتظار
                local elementId = redis.call('LPOP', queueListKey)
                
                if not elementId then
                    -- إذا كانت القائمة فارغة، حاول من ZSET
                    local oldestElements = redis.call('ZRANGE', zsetKey, 0, 0, 'WITHSCORES')
                    if #oldestElements == 0 then
                        return ''
                    end
                    elementId = oldestElements[1]
                end
                
                local hashKey = key .. ':hash:' .. elementId
                
                -- جلب البيانات
                local elementData = redis.call('HGET', hashKey, 'data')
                local metadata = redis.call('HGET', hashKey, 'metadata')
                
                if not elementData then
                    return ''
                end
                
                -- حذف العنصر من الأنظمة الأخرى
                redis.call('DEL', hashKey)
                redis.call('ZREM', zsetKey, elementId)
                
                -- تحديث العداد
                local currentCount = tonumber(redis.call('GET', counterKey) or 0)
                redis.call('SET', counterKey, math.max(0, currentCount - 1))
                
                -- تحديث الحجم الكلي
                local itemSize = redis.call('ZSCORE', sizeKey, elementId)
                if itemSize then
                    redis.call('DECRBY', totalSizeKey, tonumber(itemSize))
                    redis.call('ZREM', sizeKey, elementId)
                end
                
                return cjson.encode({
                    id = elementId,
                    data = elementData,
                    metadata = metadata,
                    dequeued_at = redis.call('TIME')[1]
                })
LUA,

            'dequeue_multiple' => <<<'LUA'
                local key = KEYS[1]
                local limit = tonumber(ARGV[1]) or 50
                
                local zsetKey = key .. ':zset'
                local queueListKey = key .. ':queue'
                local counterKey = key .. ':counter'
                local totalSizeKey = key .. ':stats:total_size'
                local sizeKey = key .. ':stats:item_sizes'
                
                local elements = {}
                local removedCount = 0
                local totalRemovedSize = 0
                
                for i = 1, limit do
                    -- الحصول على أقدم عنصر من قائمة الانتظار
                    local elementId = redis.call('LPOP', queueListKey)
                    
                    if not elementId then
                        -- إذا كانت القائمة فارغة، حاول من ZSET
                        local oldestElements = redis.call('ZRANGE', zsetKey, 0, 0, 'WITHSCORES')
                        if #oldestElements == 0 then
                            break
                        end
                        elementId = oldestElements[1]
                    end
                    
                    local hashKey = key .. ':hash:' .. elementId
                    
                    -- جلب البيانات
                    local elementData = redis.call('HGET', hashKey, 'data')
                    local metadata = redis.call('HGET', hashKey, 'metadata')
                    
                    if not elementData then
                        break
                    end
                    
                    -- تخزين العنصر للعودة
                    table.insert(elements, {
                        id = elementId,
                        data = elementData,
                        metadata = metadata,
                        dequeued_at = redis.call('TIME')[1]
                    })
                    
                    -- حذف العنصر من الأنظمة الأخرى
                    redis.call('DEL', hashKey)
                    redis.call('ZREM', zsetKey, elementId)
                    
                    -- تحديث إحصائيات الحجم
                    local itemSize = redis.call('ZSCORE', sizeKey, elementId)
                    if itemSize then
                        totalRemovedSize = totalRemovedSize + tonumber(itemSize)
                        redis.call('ZREM', sizeKey, elementId)
                    end
                    
                    removedCount = removedCount + 1
                end
                
                -- تحديث العداد والحجم الكلي
                if removedCount > 0 then
                    local currentCount = tonumber(redis.call('GET', counterKey) or 0)
                    redis.call('SET', counterKey, math.max(0, currentCount - removedCount))
                    
                    if totalRemovedSize > 0 then
                        redis.call('DECRBY', totalSizeKey, totalRemovedSize)
                    end
                end
                
                return cjson.encode(elements)
LUA,

            'blocking_dequeue' => <<<'LUA'
                local key = KEYS[1]
                local timeout = tonumber(ARGV[1]) or 0
                
                local queueListKey = key .. ':queue'
                local zsetKey = key .. ':zset'
                local counterKey = key .. ':counter'
                local totalSizeKey = key .. ':stats:total_size'
                local sizeKey = key .. ':stats:item_sizes'
                
                -- Blocking pop من قائمة الانتظار
                local result = redis.call('BLPOP', queueListKey, timeout)
                
                if not result then
                    return ''
                end
                
                local elementId = result[2]
                
                -- الحصول على البيانات
                local hashKey = key .. ':hash:' .. elementId
                local elementData = redis.call('HGET', hashKey, 'data')
                local metadata = redis.call('HGET', hashKey, 'metadata')
                
                if not elementData then
                    return ''
                end
                
                -- حذف العنصر من الأنظمة الأخرى
                redis.call('DEL', hashKey)
                redis.call('ZREM', zsetKey, elementId)
                
                -- تحديث العداد
                local currentCount = tonumber(redis.call('GET', counterKey) or 0)
                redis.call('SET', counterKey, math.max(0, currentCount - 1))
                
                -- تحديث الحجم الكلي
                local itemSize = redis.call('ZSCORE', sizeKey, elementId)
                if itemSize then
                    redis.call('DECRBY', totalSizeKey, tonumber(itemSize))
                    redis.call('ZREM', sizeKey, elementId)
                end
                
                return cjson.encode({
                    id = elementId,
                    data = elementData,
                    metadata = metadata,
                    dequeued_at = redis.call('TIME')[1]
                })
LUA,

            'delete_elements' => <<<'LUA'
                local key = KEYS[1]
                local elementIds = cjson.decode(ARGV[1])
                
                local zsetKey = key .. ':zset'
                local queueListKey = key .. ':queue'
                local counterKey = key .. ':counter'
                local totalSizeKey = key .. ':stats:total_size'
                local sizeKey = key .. ':stats:item_sizes'
                
                local deletedCount = 0
                local totalSizeReduction = 0
                
                for _, elementId in ipairs(elementIds) do
                    -- حذف الـ hash
                    local hashKey = key .. ':hash:' .. elementId
                    redis.call('DEL', hashKey)
                    
                    -- حذف من ZSET
                    local removed = redis.call('ZREM', zsetKey, elementId)
                    if removed > 0 then
                        deletedCount = deletedCount + 1
                    end
                    
                    -- حذف من قائمة الانتظار
                    redis.call('LREM', queueListKey, 0, elementId)
                    
                    -- الحصول على حجم العنصر وحذفه
                    local itemSize = redis.call('ZSCORE', sizeKey, elementId)
                    if itemSize then
                        totalSizeReduction = totalSizeReduction + tonumber(itemSize)
                        redis.call('ZREM', sizeKey, elementId)
                    end
                end
                
                -- تحديث العداد
                if deletedCount > 0 then
                    local currentCount = tonumber(redis.call('GET', counterKey) or 0)
                    redis.call('SET', counterKey, math.max(0, currentCount - deletedCount))
                end
                
                -- تحديث الحجم الكلي
                if totalSizeReduction > 0 then
                    redis.call('DECRBY', totalSizeKey, totalSizeReduction)
                end
                
                return cjson.encode(elementIds)
LUA,

            'auto_cleanup' => <<<'LUA'
                local key = KEYS[1]
                local maxMemoryMB = tonumber(ARGV[1])
                local maxMemoryBytes = maxMemoryMB * 1024 * 1024
                
                local totalSizeKey = key .. ':stats:total_size'
                local sizeKey = key .. ':stats:item_sizes'
                local zsetKey = key .. ':zset'
                local queueListKey = key .. ':queue'
                local counterKey = key .. ':counter'
                
                -- الحصول على الحجم الكلي
                local totalSize = tonumber(redis.call('GET', totalSizeKey) or 0)
                if totalSize <= maxMemoryBytes then
                    return cjson.encode({cleaned = false, message = 'Memory within limits'})
                end
                
                local bytesToRemove = totalSize - maxMemoryBytes
                local removedIds = {}
                local removedSize = 0
                
                -- حذف العناصر الأكبر حجماً أولاً
                local items = redis.call('ZREVRANGE', sizeKey, 0, 49, 'WITHSCORES')
                
                for i = 1, #items, 2 do
                    if removedSize >= bytesToRemove then
                        break
                    end
                    
                    local elementId = items[i]
                    local size = tonumber(items[i+1])
                    
                    -- حذف العنصر
                    local hashKey = key .. ':hash:' .. elementId
                    redis.call('DEL', hashKey)
                    redis.call('ZREM', zsetKey, elementId)
                    redis.call('LREM', queueListKey, 0, elementId)
                    redis.call('ZREM', sizeKey, elementId)
                    
                    table.insert(removedIds, elementId)
                    removedSize = removedSize + size
                end
                
                -- إذا لم نصل للحد المطلوب، احذف العناصر الأقدم
                if removedSize < bytesToRemove then
                    local queueItems = redis.call('LRANGE', queueListKey, 0, 99)
                    
                    for _, elementId in ipairs(queueItems) do
                        if removedSize >= bytesToRemove then
                            break
                        end
                        
                        local size = tonumber(redis.call('ZSCORE', sizeKey, elementId) or 0)
                        
                        local hashKey = key .. ':hash:' .. elementId
                        redis.call('DEL', hashKey)
                        redis.call('ZREM', zsetKey, elementId)
                        redis.call('LREM', queueListKey, 0, elementId)
                        redis.call('ZREM', sizeKey, elementId)
                        
                        table.insert(removedIds, elementId)
                        removedSize = removedSize + size
                    end
                end
                
                -- تحديث العداد والحجم الكلي
                if #removedIds > 0 then
                    local currentCount = tonumber(redis.call('GET', counterKey) or 0)
                    redis.call('SET', counterKey, math.max(0, currentCount - #removedIds))
                    redis.call('DECRBY', totalSizeKey, removedSize)
                    
                    -- تسجيل تحذير
                    redis.call('LPUSH', key .. ':warnings', 'Auto-cleanup removed ' .. #removedIds .. ' items, freed ' .. removedSize .. ' bytes')
                    redis.call('LTRIM', key .. ':warnings', 0, 99)
                end
                
                return cjson.encode({
                    cleaned = #removedIds > 0,
                    removed_count = #removedIds,
                    removed_bytes = removedSize,
                    removed_ids = removedIds,
                    message = #removedIds > 0 and 'Cleaned ' .. #removedIds .. ' items' or 'No cleanup needed'
                })
LUA
        ];
    }

    /**
     * إضافة عنصر إلى الطابور مع التحقق من الحدود
     */
    public static function enqueue(string $key, mixed $element, ?int $ttl = null): bool
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }
            // التحقق من الحدود قبل الإضافة
            $memoryCheck = static::checkMemoryLimits($key, $redis);

            if ($memoryCheck['needs_warning']) {
                static::addWarning($key, "Memory usage at {$memoryCheck['percent_used']}% of limit", $redis);
            }

            // تسلسل البيانات
            $serialized = static::serializeData($element);
            $metadata = $serialized['metadata'];

            // تنفيذ السكربت
            $elementId = static::executeScript(
                $redis,
                'enqueue',
                [$key],
                [
                    $serialized['data'],
                    $serialized['size'],
                    $metadata,
                    $ttl ?? ''
                ]
            );

            // تحديث عداد العمليات
            static::incrementOperationCount($key);

            return $elementId;
        } catch (Exception $e) {
            static::logError($e, "Queue enqueue failed for key: $key");
            return false;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * إزالة عنصر من الطابور
     */
    public static function dequeue(string $key, int $timeout = 0): mixed
    {
        if ($timeout > 0) {
            return static::blockingDequeue($key, $timeout);
        }

        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }
            $resultJson = static::executeScript($redis, 'dequeue', [$key], []);

            if (empty($resultJson)) {
                return null;
            }

            $result = binary_json_decode($resultJson, true);
            $element = static::unserializeData($result['data'], $result['metadata']);

            // ⚠ لا نحقن __redis_id/__dequeued_at في $element لأن:
            // 1. مفيش consumer بيستخدمهم.
            // 2. لو الـ payload عبارة عن مصفوفة رقمية (مثل [state, link_id, date])
            //    فالـ مفاتيح المحقونة بتزود حجم الصف فبتفسد ال bind_param في
            //    BatchInsertHelper (شوفنا الباغ ده في AttendanceTimer).
            // لو في يوم احتجنا metadata نرجعها كـ wrapper { data, redis_id, dequeued_at }.

            return $element;
        } catch (Exception $e) {
            static::logError($e, "Queue dequeue failed for key: $key");
            return null;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * مشاهدة العنصر الأمامي
     */
    public static function peek(string $key): mixed
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }
            $queueListKey = $key . ':queue';
            $elementId = $redis->lIndex($queueListKey, 0);

            if (!$elementId) {
                $zsetKey = $key . self::ZSET_PATH;
                $elementIds = $redis->zRange($zsetKey, 0, 0);
                if (empty($elementIds)) {
                    return null;
                }
                $elementId = $elementIds[0];
            }

            $hashKey = $key . self::HASH_PATH . ':' . $elementId;
            $data = $redis->hMGet($hashKey, ['data', 'metadata']);

            if (empty($data) || !isset($data['data']) || !isset($data['metadata'])) {
                return null;
            }

            return static::unserializeData($data['data'], $data['metadata']);
        } catch (Exception $e) {
            static::logError($e, "Queue peek failed for key: $key");
            return null;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * إضافة عناصر متعددة
     */
    public static function enqueueMultiple(string $key, array $elements, int $batchSize = 100): array
    {
        $allResults = [];
        $batches = static::splitIntoBatches($elements, $batchSize);

        foreach ($batches as $batch) {
            $results = static::processEnqueueBatch($key, $batch);
            $allResults = array_merge($allResults, $results);
        }

        // تحديث عداد العمليات
        static::incrementOperationCount($key);

        return $allResults;
    }

    /**
     * معالجة دفعة الإضافة
     */
    private static function processEnqueueBatch(string $key, array $elements): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return [];
            }
            $preparedElements = [];
            foreach ($elements as $index => $element) {
                $serialized = static::serializeData($element);

                $preparedElements[] = [
                    'data' => $serialized['data'],
                    'metadata' => $serialized['metadata'],
                    'size' => $serialized['size']
                ];
            }

            $elementsJson = binary_json_encode($preparedElements);
            //echo("\n batch enqueue :" . $elementsJson." \n");
            //$ll = binary_json_decode($elementsJson, true);
            //var_dump($ll);
            //echo $elementsJson;
            $elementIdsJson = static::executeScript(
                $redis,
                'enqueue_batch',
                [$key],
                [$elementsJson]
            );

            $elementIds = binary_json_decode($elementIdsJson, true);
            //echo $elementIdsJson;
            return $elementIds ?: [];
        } catch (Exception $e) {
            static::logError($e, "Queue enqueueBatch failed for key: $key");
            return array_fill(0, count($elements), false);
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * إزالة عناصر متعددة للمعالجة - نسخة محسنة باستخدام سكربت واحد
     */
    public static function dequeueMultiple(string $key, int $limit = 50): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return [];
            }
            // استخدام سكربت LUA واحد لسحب عدة عناصر دفعة واحدة
            $resultJson = static::executeScript(
                $redis,
                'dequeue_multiple',
                [$key],
                [$limit]
            );

            if (empty($resultJson)) {
                return [];
            }

            $results = binary_json_decode($resultJson, true);
            $elements = [];

            foreach ($results as $result) {
                try {
                    $element = static::unserializeData($result['data'], $result['metadata']);
                    // ⚠ مفيش حقن __redis_id/__dequeued_at — شوف التعليق في dequeue()
                    $elements[] = $element;
                } catch (Exception $e) {
                    continue;
                }
            }

            return $elements;
        } catch (Exception $e) {
            static::logError($e, "Queue dequeueMultiple failed for key: $key");
            return [];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * إزالة عناصر متعددة - النسخة القديمة للتوافق
     * @deprecated استخدام dequeueMultiple() بدلاً منها
     */
    public static function dequeueMultipleLegacy(string $key, int $limit = 50): array
    {
        trigger_error(__METHOD__ . ' is deprecated, use dequeueMultiple() instead', E_USER_DEPRECATED);

        $elements = [];
        for ($i = 0; $i < $limit; $i++) {
            $element = static::dequeue($key);
            if ($element === null) {
                break;
            }
            $elements[] = $element;
        }
        return $elements;
    }

    /**
     * حجم الطابور
     */
    public static function size(string $key): ?int
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return null;
            }
            $queueListKey = $key . ':queue';
            return $redis->lLen($queueListKey);
        } catch (Exception $e) {
            static::logError($e, "Queue size failed for key: $key");
            return 0;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * التحقق إذا كان الطابور فارغاً
     */
    public static function isEmpty(string $key): bool
    {
        return static::size($key) === 0;
    }

    /**
     * حذف عناصر محددة بالأيدي
     */
    public static function deleteByIds(string $key, array $elementIds): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return [];
            }
            $elementIdsJson = json_encode($elementIds, JSON_THROW_ON_ERROR);

            $deletedIdsJson = static::executeScript(
                $redis,
                'delete_elements',
                [$key],
                [$elementIdsJson]
            );

            $deletedIds = binary_json_decode($deletedIdsJson, true);

            return $deletedIds ?: [];
        } catch (Exception $e) {
            static::logError($e, "Queue deleteByIds failed for key: $key");
            return [];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * حذف العناصر التي تمت معالجتها
     */
    public static function deleteProcessed(string $key, array $processedIds): array
    {
        return static::deleteByIds($key, $processedIds);
    }

    /**
     * تنظيف ذكي بناءً على حجم الذاكرة - محسن
     */
    public static function autoCleanup(string $key, ?int $maxMemoryMB = null): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return ['cleaned' => false, 'error' => 'Redis connection failed'];
            }
            $maxMemoryMB = $maxMemoryMB ?? static::MAX_MEMORY_MB;

            $resultJson = static::executeScript(
                $redis,
                'auto_cleanup',
                [$key],
                [$maxMemoryMB]
            );

            $result = binary_json_decode($resultJson, true);

            // إذا تم التنظيف، سجل تحذير
            if ($result['cleaned'] ?? false) {
                $message = "Auto-cleanup performed: {$result['message']}";
                static::addWarning($key, $message, $redis);
                error_log($message);
            }

            return $result;
        } catch (Exception $e) {
            static::logError($e, "Queue autoCleanup failed for key: $key");
            return ['cleaned' => false, 'error' => $e->getMessage()];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * جلب إحصائيات الطابور مع معلومات الحدود
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

            $queueListKey = $key . ':queue';
            $counterKey = $key . self::COUNTER;
            $totalSizeKey = $key . self::TOTAL_SIZE_KEY;
            $sizeKey = $key . self::SIZE_TRACKER;
            $warningsKey = $key . ':warnings';

            $redis->lLen($queueListKey);
            $redis->get($counterKey);
            $redis->get($totalSizeKey);
            $redis->zCard($sizeKey);
            $redis->zRange($sizeKey, -1, -1, ['withscores' => true]);
            $redis->lRange($warningsKey, 0, 4);

            $responses = $redis->exec();

            list(
                $totalItems,
                $counter,
                $totalSize,
                $totalSizedItems,
                $largestItem,
                $recentWarnings
            ) = $responses;

            $largestSize = !empty($largestItem) ? reset($largestItem) : 0;
            $largestId = !empty($largestItem) ? key($largestItem) : null;

            $memoryInfo = static::getMemoryUsage($key, $redis);

            return [
                'structure_type' => 'queue',
                'total_items' => (int)$totalItems,
                'counter' => (int)$counter,
                'memory_usage' => $memoryInfo,
                'limits' => [
                    'max_memory_mb' => static::MAX_MEMORY_MB,
                    'max_items' => static::MAX_ITEMS,
                    'warning_threshold' => static::WARNING_THRESHOLD * 100 . '%'
                ],
                'largest_item' => [
                    'id' => $largestId,
                    'size_bytes' => $largestSize,
                    'size_kb' => round($largestSize / 1024, 2)
                ],
                'avg_item_size' => $totalItems > 0 ? round($totalSize / $totalItems) : 0,
                'serializer' => static::canUseMsgPack() ? 'msgpack' : 'json',
                'compression' => static::canUseCompression() ? 'enabled' : 'disabled',
                'recent_warnings' => array_map('binary_json_decode', $recentWarnings),
                'needs_attention' => $memoryInfo['percent'] > (static::WARNING_THRESHOLD * 100)
            ];
        } catch (Exception $e) {
            static::logError($e, "Queue getStats failed for key: $key");
            return [];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * Blocking dequeue مع timeout
     */
    public static function blockingDequeue(string $key, int $timeout = 10): ?array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return null;
            }
            $resultJson = static::executeScript(
                $redis,
                'blocking_dequeue',
                [$key],
                [$timeout]
            );

            if (empty($resultJson)) {
                return null;
            }

            $result = binary_json_decode($resultJson, true);
            $element = static::unserializeData($result['data'], $result['metadata']);

            $element['__redis_id'] = $result['id'];
            $element['__dequeued_at'] = $result['dequeued_at'] ?? time();

            return $element;
        } catch (Exception $e) {
            static::logError($e, "Queue blockingDequeue failed for key: $key");
            return null;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * نوع الهيكل
     */
    protected static function getStructureType(): string
    {
        return 'queue';
    }

    /**
     * الحصول على التحذيرات
     */
    public static function getWarnings(string $key, int $limit = 10): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
                        if ($redis === null) {
                return [];
            }
            $warningsKey = $key . ':warnings';
            $warnings = $redis->lRange($warningsKey, 0, $limit - 1);

            return array_map('binary_json_decode', $warnings);
        } catch (Exception $e) {
            return [];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }
}
