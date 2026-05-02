<?php

namespace Nour\Database\redis\Structures;

use Exception;
use Nour\Database\redis\Abstract\RedisStructureBase;
use Nour\Database\redis\Contracts\KeyValueInterface;
use Nour\Database\redis\Traits\ScriptManagerTrait;
use Redis;

final class KeyValue extends RedisStructureBase implements KeyValueInterface
{
    use ScriptManagerTrait;

    /**
     * تعريف سكربتات تخزين المفاتيح
     */
    protected static function defineScripts(): void
    {
        self::$scripts = [
            'set' => <<<'LUA'
                local key = KEYS[1]
                local field = ARGV[1]
                local elementData = ARGV[2]
                local elementSize = tonumber(ARGV[3])
                local metadata = ARGV[4]
                local ttl = ARGV[5]
                
                local hashKey = key .. ':hash:' .. field
                local fieldsKey = key .. ':fields'
                local totalSizeKey = key .. ':stats:total_size'
                local sizeKey = key .. ':stats:item_sizes'
                
                -- التحقق من حدود الذاكرة قبل الإضافة
                local currentSize = tonumber(redis.call('GET', totalSizeKey) or 0)
                local maxMemoryBytes = 524288000 -- 500MB
                
                -- إذا تجاوزنا الحد، نحذف أقدم الحقول
                if currentSize + elementSize > maxMemoryBytes then
                    local allFields = redis.call('SMEMBERS', fieldsKey)
                    local removed = 0
                    local removedSize = 0
                    
                    for i, oldField in ipairs(allFields) do
                        if removedSize >= elementSize then
                            break
                        end
                        
                        local oldHashKey = key .. ':hash:' .. oldField
                        local oldSize = tonumber(redis.call('ZSCORE', sizeKey, oldField) or 0)
                        
                        -- حذف الحقل القديم
                        redis.call('DEL', oldHashKey)
                        redis.call('SREM', fieldsKey, oldField)
                        redis.call('ZREM', sizeKey, oldField)
                        
                        currentSize = math.max(0, currentSize - oldSize)
                        removedSize = removedSize + oldSize
                        removed = removed + 1
                        
                        -- تسجيل تحذير بعد أول حذف
                        if removed == 1 then
                            redis.call('LPUSH', key .. ':warnings', 'Memory limit exceeded, removed field: ' .. oldField)
                            redis.call('LTRIM', key .. ':warnings', 0, 99)
                        end
                    end
                    
                    -- تحديث الحجم الكلي
                    redis.call('SET', totalSizeKey, currentSize)
                end
                
                -- تخزين البيانات
                redis.call('HSET', hashKey, 'data', elementData, 'metadata', metadata)
                
                -- إضافة إلى مجموعة الحقول إذا لم تكن موجودة
                redis.call('SADD', fieldsKey, field)
                
                -- تتبع حجم العنصر الفردي
                redis.call('ZADD', sizeKey, elementSize, field)
                
                -- تحديث الحجم الكلي
                redis.call('INCRBY', totalSizeKey, elementSize)
                
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
                
                return field
LUA,

            'set_batch' => <<<'LUA'
                local key = KEYS[1]
                local fieldValuePairs = cjson.decode(ARGV[1])
                local maxItems = 1000000
                local maxMemoryBytes = 524288000
                
                local fieldsKey = key .. ':fields'
                local totalSizeKey = key .. ':stats:total_size'
                local sizeKey = key .. ':stats:item_sizes'
                
                -- الحصول على الأحجام الحالية
                local currentSize = tonumber(redis.call('GET', totalSizeKey) or 0)
                local currentCount = redis.call('SCARD', fieldsKey)
                
                -- حساب الحجم الإجمالي للدفعة
                local batchTotalSize = 0
                for _, pair in ipairs(fieldValuePairs) do
                    batchTotalSize = batchTotalSize + pair.size
                end
                
                -- التحقق من الحدود وحذف الحقول القديمة إذا لزم الأمر
                local removedCount = 0
                while (currentCount + #fieldValuePairs > maxItems) or (currentSize + batchTotalSize > maxMemoryBytes) do
                    local allFields = redis.call('SMEMBERS', fieldsKey)
                    if #allFields == 0 then
                        break
                    end
                    
                    local oldField = allFields[1]
                    local oldHashKey = key .. ':hash:' .. oldField
                    local oldSize = tonumber(redis.call('ZSCORE', sizeKey, oldField) or 0)
                    
                    redis.call('DEL', oldHashKey)
                    redis.call('SREM', fieldsKey, oldField)
                    redis.call('ZREM', sizeKey, oldField)
                    
                    currentSize = math.max(0, currentSize - oldSize)
                    currentCount = math.max(0, currentCount - 1)
                    removedCount = removedCount + 1
                    
                    -- تحديث بعد كل حذف
                    redis.call('SET', totalSizeKey, currentSize)
                end
                
                -- تسجيل تحذير إذا تم حذف حقول
                if removedCount > 0 then
                    redis.call('LPUSH', key .. ':warnings', 'Memory/Item limit exceeded, removed ' .. removedCount .. ' oldest fields')
                    redis.call('LTRIM', key .. ':warnings', 0, 99)
                end
                
                -- إضافة الحقول الجديدة
                local fieldsAdded = {}
                local addedSize = 0
                
                for _, pair in ipairs(fieldValuePairs) do
                    local field = pair.field
                    local hashKey = key .. ':hash:' .. field
                    
                    -- تخزين البيانات
                    redis.call('HSET', hashKey, 'data', pair.data, 'metadata', pair.metadata)
                    
                    -- إضافة إلى مجموعة الحقول
                    redis.call('SADD', fieldsKey, field)
                    
                    -- تحديث إحصائيات الحجم
                    addedSize = addedSize + pair.size
                    redis.call('ZADD', sizeKey, pair.size, field)
                    
                    table.insert(fieldsAdded, field)
                end
                
                -- تحديث الحجم الكلي
                redis.call('INCRBY', totalSizeKey, addedSize)
                
                return cjson.encode(fieldsAdded)
LUA,

            'get' => <<<'LUA'
                local key = KEYS[1]
                local field = ARGV[1]
                
                local hashKey = key .. ':hash:' .. field
                
                local data = redis.call('HGET', hashKey, 'data')
                local metadata = redis.call('HGET', hashKey, 'metadata')
                
                if not data then
                    return nil
                end
                
                -- تحويل البيانات الثنائية إلى base64 لضمان سلامة النقل
                -- local data_base64 = cjson.encode(data)
                return data .. "\n" .. metadata
LUA,

            'mget' => <<<'LUA'
                local key = KEYS[1]
                local fields = cjson.decode(ARGV[1])
                
                local results = {}
                
                for _, field in ipairs(fields) do
                    local hashKey = key .. ':hash:' .. field
                    local data = redis.call('HGET', hashKey, 'data')
                    local metadata = redis.call('HGET', hashKey, 'metadata')
                    
                    if data then
                        table.insert(results, {
                            field = field,
                            data = data,
                            metadata = metadata
                        })
                    end
                end
                
                return cjson.encode(results)
LUA,

            'delete' => <<<'LUA'
                local key = KEYS[1]
                local field = ARGV[1]
                
                local hashKey = key .. ':hash:' .. field
                local fieldsKey = key .. ':fields'
                local totalSizeKey = key .. ':stats:total_size'
                local sizeKey = key .. ':stats:item_sizes'
                
                -- الحصول على حجم العنصر
                local size = tonumber(redis.call('ZSCORE', sizeKey, field) or 0)
                
                -- حذف العنصر
                local deleted = redis.call('DEL', hashKey)
                redis.call('SREM', fieldsKey, field)
                redis.call('ZREM', sizeKey, field)
                
                if deleted == 1 then
                    -- تحديث الحجم الكلي
                    if size > 0 then
                        redis.call('DECRBY', totalSizeKey, size)
                    end
                    return 1
                else
                    return 0
                end
LUA,

            'delete_multiple' => <<<'LUA'
                local key = KEYS[1]
                local fields = cjson.decode(ARGV[1])
                
                local fieldsKey = key .. ':fields'
                local totalSizeKey = key .. ':stats:total_size'
                local sizeKey = key .. ':stats:item_sizes'
                
                local deletedCount = 0
                local totalSizeReduction = 0
                
                for _, field in ipairs(fields) do
                    local hashKey = key .. ':hash:' .. field
                    
                    -- الحصول على حجم العنصر
                    local size = tonumber(redis.call('ZSCORE', sizeKey, field) or 0)
                    
                    -- حذف العنصر
                    local deleted = redis.call('DEL', hashKey)
                    if deleted == 1 then
                        deletedCount = deletedCount + 1
                        totalSizeReduction = totalSizeReduction + size
                    end
                    
                    redis.call('SREM', fieldsKey, field)
                    redis.call('ZREM', sizeKey, field)
                end
                
                -- تحديث الحجم الكلي
                if totalSizeReduction > 0 then
                    redis.call('DECRBY', totalSizeKey, totalSizeReduction)
                end
                
                return deletedCount
LUA,

            'increment' => <<<'LUA'
                local key = KEYS[1]
                local field = ARGV[1]
                local incrementBy = tonumber(ARGV[2])
                local ttl = tonumber(ARGV[3])

                local hashKey = key .. ':hash:' .. field
                local fieldsKey = key .. ':fields'

                -- جلب القيمة الحالية
                local data = redis.call('HGET', hashKey, 'data')
                local metadata = redis.call('HGET', hashKey, 'metadata')

                if not data then
                    -- إذا لم تكن موجودة، أنشئها
                    local newValue = incrementBy
                    local serialized = cjson.encode(newValue)
                    local newMetadata = 'n:0:' .. redis.call('TIME')[1] .. ':' .. string.len(serialized)

                    redis.call('HSET', hashKey, 'data', serialized, 'metadata', newMetadata)
                    redis.call('SADD', fieldsKey, field)

                    -- تحديث حجم التخزين
                    local sizeKey = key .. ':stats:item_sizes'
                    local totalSizeKey = key .. ':stats:total_size'
                    local newSize = string.len(serialized)

                    redis.call('ZADD', sizeKey, newSize, field)
                    redis.call('INCRBY', totalSizeKey, newSize)

                    -- تعيين TTL إذا كان مطلوباً (فقط عند الإنشاء)
                    if ttl and ttl > 0 then
                        redis.call('EXPIRE', hashKey, ttl)
                    end

                    return newValue
                else
                    -- إذا كانت موجودة، فك التسلسل
                    local parts = {}
                    for part in string.gmatch(metadata, "[^:]+") do
                        table.insert(parts, part)
                    end
                    
                    local serializer = parts[1]
                    local compressed = parts[2] == '1'
                    
                    if compressed then
                        data = redis.call('HGET', hashKey, 'data')
                        -- في حالة الرقم، لا يكون مضغوطاً عادة
                    end
                    
                    local currentValue
                    if serializer == 'm' then
                        currentValue = cmsgpack.unpack(data)
                    else
                        currentValue = cjson.decode(data)
                    end
                    
                    -- التحقق إذا كان رقم
                    if type(currentValue) ~= 'number' then
                        return false
                    end
                    
                    -- زيادة القيمة
                    local newValue = currentValue + incrementBy
                    local serialized
                    if serializer == 'm' then
                        serialized = cmsgpack.pack(newValue)
                    else
                        serialized = cjson.encode(newValue)
                    end
                    
                    -- تحديث البيانات
                    redis.call('HSET', hashKey, 'data', serialized)
                    
                    -- تحديث حجم التخزين
                    local sizeKey = key .. ':stats:item_sizes'
                    local totalSizeKey = key .. ':stats:total_size'
                    local oldSize = tonumber(redis.call('ZSCORE', sizeKey, field) or 0)
                    local newSize = string.len(serialized)
                    
                    redis.call('ZADD', sizeKey, newSize, field)
                    redis.call('INCRBY', totalSizeKey, newSize - oldSize)
                    
                    return newValue
                end
LUA,

            'auto_cleanup' => <<<'LUA'
                local key = KEYS[1]
                local maxMemoryMB = tonumber(ARGV[1])
                local maxMemoryBytes = maxMemoryMB * 1024 * 1024
                
                local totalSizeKey = key .. ':stats:total_size'
                local sizeKey = key .. ':stats:item_sizes'
                local fieldsKey = key .. ':fields'
                
                -- الحصول على الحجم الكلي
                local totalSize = tonumber(redis.call('GET', totalSizeKey) or 0)
                if totalSize <= maxMemoryBytes then
                    return cjson.encode({cleaned = false, message = 'Memory within limits'})
                end
                
                local bytesToRemove = totalSize - maxMemoryBytes
                local removedFields = {}
                local removedSize = 0
                
                -- حذف العناصر الأكبر حجماً أولاً
                local items = redis.call('ZREVRANGE', sizeKey, 0, 49, 'WITHSCORES')
                
                for i = 1, #items, 2 do
                    if removedSize >= bytesToRemove then
                        break
                    end
                    
                    local field = items[i]
                    local size = tonumber(items[i+1])
                    
                    -- حذف العنصر
                    local hashKey = key .. ':hash:' .. field
                    redis.call('DEL', hashKey)
                    redis.call('SREM', fieldsKey, field)
                    redis.call('ZREM', sizeKey, field)
                    
                    table.insert(removedFields, field)
                    removedSize = removedSize + size
                end
                
                -- إذا لم نصل للحد المطلوب، احذف الحقول بشكل عشوائي
                if removedSize < bytesToRemove then
                    local allFields = redis.call('SMEMBERS', fieldsKey)
                    
                    for _, field in ipairs(allFields) do
                        if removedSize >= bytesToRemove then
                            break
                        end
                        
                        local size = tonumber(redis.call('ZSCORE', sizeKey, field) or 0)
                        
                        local hashKey = key .. ':hash:' .. field
                        redis.call('DEL', hashKey)
                        redis.call('SREM', fieldsKey, field)
                        redis.call('ZREM', sizeKey, field)
                        
                        table.insert(removedFields, field)
                        removedSize = removedSize + size
                    end
                end
                
                -- تحديث الحجم الكلي
                if #removedFields > 0 then
                    redis.call('DECRBY', totalSizeKey, removedSize)
                    
                    -- تسجيل تحذير
                    redis.call('LPUSH', key .. ':warnings', 'Auto-cleanup removed ' .. #removedFields .. ' fields, freed ' .. removedSize .. ' bytes')
                    redis.call('LTRIM', key .. ':warnings', 0, 99)
                end
                
                return cjson.encode({
                    cleaned = #removedFields > 0,
                    removed_count = #removedFields,
                    removed_bytes = removedSize,
                    removed_fields = removedFields,
                    message = #removedFields > 0 and 'Cleaned ' .. #removedFields .. ' fields' or 'No cleanup needed'
                })
LUA,

            'search' => <<<'LUA'
                local key = KEYS[1]
                local pattern = ARGV[1]
                
                local fieldsKey = key .. ':fields'
                local allFields = redis.call('SMEMBERS', fieldsKey)
                
                local results = {}
                
                for _, field in ipairs(allFields) do
                    if string.match(field, pattern) then
                        local hashKey = key .. ':hash:' .. field
                        local data = redis.call('HGET', hashKey, 'data')
                        local metadata = redis.call('HGET', hashKey, 'metadata')
                        
                        if data then
                            table.insert(results, {
                                field = field,
                                data = data,
                                metadata = metadata
                            })
                        end
                    end
                end
                
                return cjson.encode(results)
LUA
        ];
    }

    /**
     * تعيين قيمة لمفتاح
     */
    public static function set(string $key, string $field, mixed $value, ?int $ttl = null): string|bool
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
            $serialized = static::serializeData($value);
            $metadata = $serialized['metadata'];

            // تنفيذ السكربت
            $result = static::executeScript(
                $redis,
                'set',
                [$key],
                [
                    $field,
                    $serialized['data'],
                    $serialized['size'],
                    $metadata,
                    $ttl ?? ''
                ]
            );

            // تحديث عداد العمليات
            static::incrementOperationCount($key);

            return $result;
        } catch (Exception $e) {
            static::logError($e, "KeyValue set failed for key: $key, field: $field");
            return false;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * الحصول على قيمة مفتاح
     */
    public static function get(string $key, string $field): mixed
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }
            $str_data = static::executeScript($redis, 'get', [$key], [$field]);
            if (empty($str_data)) {
                    return null;
            }            
            $result = explode("\n", $str_data, 2);


            return static::unserializeData($result[0], $result[1]);
        } catch (Exception $e) {
            static::logError($e, "KeyValue get failed for key: $key, field: $field");
            return null;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * الحصول على عدة قيم في وقت واحد
     */
    public static function mget(string $key, array $fields): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return [];
            }
            $fieldsJson = binary_json_encode($fields);

            $resultJson = static::executeScript($redis, 'mget', [$key], [$fieldsJson]);

            if (empty($resultJson)) {
                return [];
            }

            $results = binary_json_decode($resultJson, true);
            $data = [];

            foreach ($results as $result) {
                try {
                    $data[$result['field']] = static::unserializeData($result['data'], $result['metadata']);
                } catch (Exception $e) {
                    $data[$result['field']] = null;
                }
            }

            return $data;
        } catch (Exception $e) {
            static::logError($e, "KeyValue mget failed for key: $key");
            return [];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * تعيين عدة قيم في وقت واحد
     */
    public static function mset(string $key, array $fieldValuePairs, int $batchSize = 100): array
    {
        $allResults = [];
        $batches = static::splitIntoBatches($fieldValuePairs, $batchSize);

        foreach ($batches as $batch) {
            $results = static::processMsetBatch($key, $batch);
            $allResults = array_merge($allResults, $results);
        }

        // تحديث عداد العمليات
        static::incrementOperationCount($key);

        return $allResults;
    }

    /**
     * معالجة دفعة تعيين القيم
     */
    private static function processMsetBatch(string $key, array $fieldValuePairs): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return [];
            }
            $preparedPairs = [];
            foreach ($fieldValuePairs as $field => $value) {
                $serialized = static::serializeData($value);

                $preparedPairs[] = [
                    'field' => $field,
                    'data' => $serialized['data'],
                    'metadata' => $serialized['metadata'],
                    'size' => $serialized['size']
                ];
            }

            $pairsJson = binary_json_encode($preparedPairs);

            $fieldsJson = static::executeScript(
                $redis,
                'set_batch',
                [$key],
                [$pairsJson]
            );

            $fields = binary_json_decode($fieldsJson, true);

            return $fields ?: [];
        } catch (Exception $e) {
            static::logError($e, "KeyValue msetBatch failed for key: $key");
            return array_fill_keys(array_keys($fieldValuePairs), false);
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * حذف مفتاح
     */
    public static function delete(string $key, string $field): bool
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }
            $result = static::executeScript($redis, 'delete', [$key], [$field]);

            return $result == 1;
        } catch (Exception $e) {
            static::logError($e, "KeyValue delete failed for key: $key, field: $field");
            return false;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * حذف عدة مفاتيح
     */
    public static function deleteMultiple(string $key, array $fields): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return ['deleted' => 0, 'total' => count($fields)];
            }
            $fieldsJson = binary_json_encode($fields);

            $deletedCount = static::executeScript($redis, 'delete_multiple', [$key], [$fieldsJson]);

            return [
                'deleted' => (int)$deletedCount,
                'total' => count($fields)
            ];
        } catch (Exception $e) {
            static::logError($e, "KeyValue deleteMultiple failed for key: $key");
            return ['deleted' => 0, 'total' => count($fields), 'error' => $e->getMessage()];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * التحقق من وجود مفتاح
     */
    public static function has(string $key, string $field): bool
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }
            $hashKey = $key . ':hash:' . $field;
            return $redis->exists($hashKey) == 1;
        } catch (Exception $e) {
            static::logError($e, "KeyValue has failed for key: $key, field: $field");
            return false;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * مسح جميع المفاتيح. M9 fix: SCAN cursor-based بدل KEYS — الـ KEYS كان
     * بيقفل Redis (single-threaded) لحد ما يخلص scan الـ keyspace كاملًا.
     */
    public static function clear(string $key): bool
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }

            $pattern = $key . ':*';
            $cursor  = null;
            $batch   = 200;
            do {
                $found = $redis->scan($cursor, $pattern, $batch);
                if ($found === false) {
                    break; // iteration done
                }
                if (!empty($found)) {
                    $redis->del($found);
                }
            } while ((int) $cursor !== 0);

            return true;
        } catch (Exception $e) {
            static::logError($e, "KeyValue clear failed for key: $key");
            return false;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * جلب جميع المفاتيح
     */
    public static function keys(string $key, string $pattern = '*'): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return [];
            }
            $fieldsKey = $key . ':fields';
            $allFields = $redis->sMembers($fieldsKey);

            if ($pattern === '*') {
                return $allFields;
            }

            $filteredFields = [];
            foreach ($allFields as $field) {
                if (fnmatch($pattern, $field)) {
                    $filteredFields[] = $field;
                }
            }

            return $filteredFields;
        } catch (Exception $e) {
            static::logError($e, "KeyValue keys failed for key: $key");
            return [];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * حجم التخزين
     */
    public static function size(string $key): int
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
                        if ($redis === null) {
                return false;
            }
            $fieldsKey = $key . ':fields';
            return $redis->sCard($fieldsKey);
        } catch (Exception $e) {
            static::logError($e, "KeyValue size failed for key: $key");
            return 0;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * التحقق إذا كان فارغاً
     */
    public static function isEmpty(string $key): bool
    {
        return static::size($key) === 0;
    }

    /**
     * زيادة قيمة رقمية
     */
    public static function increment(string $key, string $field, int $value = 1, ?int $ttl = null): int|bool
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }
            $result = static::executeScript($redis, 'increment', [$key], [$field, $value, $ttl ?? 0]);

            return is_numeric($result) ? (int)$result : false;
        } catch (Exception $e) {
            static::logError($e, "KeyValue increment failed for key: $key, field: $field");
            return false;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * تقليل قيمة رقمية
     */
    public static function decrement(string $key, string $field, int $value = 1, ?int $ttl = null): int|bool
    {
        return static::increment($key, $field, -$value, $ttl);
    }

    /**
     * تعيين TTL لمفتاح
     */
    public static function expire(string $key, string $field, int $ttl): bool
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }
            $hashKey = $key . ':hash:' . $field;
            return $redis->expire($hashKey, $ttl);
        } catch (Exception $e) {
            static::logError($e, "KeyValue expire failed for key: $key, field: $field");
            return false;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * الحصول على TTL المتبقي
     */
    public static function ttl(string $key, string $field): ?int
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return null;
            }
            $hashKey = $key . ':hash:' . $field;
            $ttl = $redis->ttl($hashKey);

            return $ttl > 0 ? $ttl : -1;
        } catch (Exception $e) {
            static::logError($e, "KeyValue ttl failed for key: $key, field: $field");
            return -2;
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * البحث في القيم
     */
    public static function search(string $key, string $pattern): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return [];
            }
            $resultJson = static::executeScript($redis, 'search', [$key], [$pattern]);

            if (empty($resultJson)) {
                return [];
            }

            $results = binary_json_decode($resultJson, true);
            $data = [];

            foreach ($results as $result) {
                try {
                    $data[$result['field']] = static::unserializeData($result['data'], $result['metadata']);
                } catch (Exception $e) {
                    $data[$result['field']] = null;
                }
            }

            return $data;
        } catch (Exception $e) {
            static::logError($e, "KeyValue search failed for key: $key, pattern: $pattern");
            return [];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * فرز المفاتيح
     */
    public static function sort(string $key, ?string $by = null, array $options = []): array
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return [];
            }
            $fieldsKey = $key . ':fields';
            $allFields = $redis->sMembers($fieldsKey);

            if ($by === 'size') {
                // الفرز حسب الحجم
                $sizeKey = $key . ':stats:item_sizes';
                $sizes = $redis->zRevRange($sizeKey, 0, -1, ['withscores' => true]);

                $sorted = [];
                foreach ($sizes as $field => $size) {
                    if (in_array($field, $allFields)) {
                        $sorted[] = $field;
                    }
                }

                return $sorted;
            } else {
                // الفرز أبجدياً
                sort($allFields);
                return $allFields;
            }
        } catch (Exception $e) {
            static::logError($e, "KeyValue sort failed for key: $key");
            return [];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * إحصائيات التخزين
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

            $fieldsKey = $key . ':fields';
            $totalSizeKey = $key . ':stats:total_size';
            $sizeKey = $key . ':stats:item_sizes';
            $warningsKey = $key . ':warnings';

            $redis->sCard($fieldsKey);
            $redis->get($totalSizeKey);
            $redis->zCard($sizeKey);
            $redis->zRange($sizeKey, -1, -1, ['withscores' => true]);
            $redis->lRange($warningsKey, 0, 4);

            $responses = $redis->exec();

            list(
                $totalItems,
                $totalSize,
                $totalSizedItems,
                $largestItem,
                $recentWarnings
            ) = $responses;

            $largestSize = !empty($largestItem) ? reset($largestItem) : 0;
            $largestField = !empty($largestItem) ? key($largestItem) : null;

            $memoryInfo = static::getMemoryUsage($key, $redis);

            return [
                'structure_type' => 'key_value',
                'total_items' => (int)$totalItems,
                'memory_usage' => $memoryInfo,
                'limits' => [
                    'max_memory_mb' => static::MAX_MEMORY_MB,
                    'max_items' => static::MAX_ITEMS,
                    'warning_threshold' => static::WARNING_THRESHOLD * 100 . '%'
                ],
                'largest_item' => [
                    'field' => $largestField,
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
            static::logError($e, "KeyValue getStats failed for key: $key");
            return [];
        } finally {
            if ($redis) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * تنظيف ذكي بناءً على حجم الذاكرة
     */
    public static function autoCleanup(string $key, ?int $maxMemoryMB = null): array 
    {
        $redis = null;

        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return [];
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
            static::logError($e, "KeyValue autoCleanup failed for key: $key");
            return ['cleaned' => false, 'error' => $e->getMessage()];
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
        return 'key_value';
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
