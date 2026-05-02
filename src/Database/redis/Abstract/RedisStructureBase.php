<?php

namespace Nour\Database\redis\Abstract;

use Exception;
use Nour\Database\RedisDatabase;
use Redis;
use RedisException;

abstract class RedisStructureBase
{
    // المسارات الأساسية
    protected const HASH_PATH = ":hash";
    protected const ZSET_PATH = ":zset";
    protected const COUNTER = ":counter";
    protected const TOTAL_SIZE_KEY = ":stats:total_size";
    protected const SEQ_GENERATOR = ":seq";
    protected const SIZE_TRACKER = ":stats:item_sizes";
    
    // حدود العمليات للحفاظ على أداء Redis
    protected const MAX_BATCH_SIZE = 100;          // أقصى حجم للدفعة
    protected const MAX_DELETE_BATCH = 500;        // أقصى حجم للحذف
    protected const MAX_FETCH_BATCH = 100;         // أقصى حجم للجلب
    protected const MAX_MEMORY_CHECK = 100;        // تحقق من الذاكرة كل 100 عملية
    
    // حدود التخزين الجديدة
    protected const MAX_MEMORY_MB = 500;           // الحد الأقصى للذاكرة بالميجابايت
    protected const MAX_MEMORY_BYTES = 524288000;  // 500 * 1024 * 1024
    protected const MAX_ITEMS = 1000000;           // الحد الأقصى لعدد العناصر
    protected const WARNING_THRESHOLD = 0.8;       // تحذير عند 80% من الحد
    
    // أنواع البيانات المدعومة
    private const TYPE_ARRAY = 'a';
    private const TYPE_STRING = 's';
    private const TYPE_INTEGER = 'i';
    private const TYPE_FLOAT = 'f';
    private const TYPE_BOOLEAN = 'b';
    private const TYPE_NULL = 'n';
    private const TYPE_OBJECT = 'o'; // تم تسلسله كمصفوفة
    
    // خصائص ثابتة للتهيئة
    protected static $useMsgPack = null;
    protected static $useCompression = null;
    protected static $initialized = false;
    protected static $operationCount = [];
    
    /**
     * التحقق من إمكانية استخدام MsgPack
     */
    protected static function canUseMsgPack(): bool
    {
        if (self::$useMsgPack === null) {
            self::$useMsgPack = extension_loaded('msgpack');
        }
        return self::$useMsgPack;
    }
    
    /**
     * التحقق من إمكانية استخدام الضغط
     */
    protected static function canUseCompression(): bool
    {
        if (self::$useCompression === null) {
            self::$useCompression = extension_loaded('zlib');
        }
        return self::$useCompression;
    }
    
    /**
     * تسلسل البيانات مع ضغط ذكي - دعم جميع أنواع البيانات
     */
    protected static function serializeData(mixed $data): array
    {
        // تحديد نوع البيانات
        $type = gettype($data);
        $typeChar = self::TYPE_STRING;
        
        switch ($type) {
            case 'array':
                $typeChar = self::TYPE_ARRAY;
                break;
            case 'string':
                $typeChar = self::TYPE_STRING;
                break;
            case 'integer':
                $typeChar = self::TYPE_INTEGER;
                $data = (string)$data; // تحويل إلى string للتخزين
                break;
            case 'double':
                $typeChar = self::TYPE_FLOAT;
                $data = (string)$data; // تحويل إلى string للتخزين
                break;
            case 'boolean':
                $typeChar = self::TYPE_BOOLEAN;
                $data = $data ? '1' : '0'; // تحويل إلى string
                break;
            case 'NULL':
                $typeChar = self::TYPE_NULL;
                $data = ''; // بيانات فارغة
                break;
            case 'object':
                $typeChar = self::TYPE_OBJECT;
                // تحويل الكائن إلى مصفوفة
                $data = get_object_vars($data);
                break;
            default:
                $typeChar = self::TYPE_STRING;
                $data = (string)$data;
        }
        
        // إذا كانت البيانات مصفوفة أو كائن محول لمصفوفة، نستخدم التسلسل العادي
        if ($typeChar === self::TYPE_ARRAY || $typeChar === self::TYPE_OBJECT) {
            if (self::canUseMsgPack()) {
                $serialized = msgpack_pack($data);
                $serializer = 'm';
            } else {
                $serialized = binary_json_encode($data);
                $serializer = 'j';
            }
        } else {
            // للأنواع البسيطة، نخزنها كـ string مباشرة
            $serializer = 's'; // s لـ string (بدون تسلسل)
            $serialized = (string)$data;
        }
        
        $originalSize = strlen($serialized);
        $compressed = false;
        
        // ضغط ذكي للبيانات الكبيرة (فقط للمصفوفات والكائنات والسلاسل الطويلة)
        if (self::canUseCompression() && $originalSize >= 10000) {
            $compressedData = gzcompress($serialized, 6);
            $compressedSize = strlen($compressedData);
            
            // فقط إذا كان الضغط يوفر 20% على الأقل
            if ($compressedSize < $originalSize * 0.8) {
                $serialized = $compressedData;
                $compressed = true;
            }
        }
        
        // ميتاداتا مخففة جداً
        // التنسيق: serializer:compressed:datatype:timestamp:original_size
        $metadata = $serializer . ':' . ($compressed ? '1' : '0') . ':' . $typeChar . ':' . time() . ':' . $originalSize;
        
        return [
            'data' => $serialized,
            'metadata' => $metadata,
            'size' => strlen($serialized),
            'compressed' => $compressed,
            'datatype' => $typeChar
        ];
    }
    
    /**
     * فك تسلسل البيانات - دعم جميع الأنواع
     */
/**
 * فك تسلسل البيانات - دعم جميع الأنواع
 */
protected static function unserializeData(string $data, string $metadata): mixed
{
    // التحقق من صحة المدخلات
    if ($data === null || $metadata === null) {
        return null;
    }
    
    $parts = explode(':', $metadata);
    if (count($parts) < 5) {
        throw new Exception("Invalid metadata format: $metadata");
    }
    
    $serializer = $parts[0];
    $compressed = ($parts[1] ?? '0') === '1';
    $typeChar = $parts[2];
    $timestamp = $parts[3] ?? time();
    $originalSize = $parts[4] ?? 0;
    
    // فك الضغط إذا كان مضغوطاً
    if ($compressed) {
        $decompressed = @gzuncompress($data);
        if ($decompressed !== false) {
            $data = $decompressed;
        } else {
            // إذا فشل فك الضغط، استخدم البيانات كما هي
            $compressed = false;
        }
    }
    
    // معالجة البيانات بناءً على النوع
    switch ($typeChar) {
        case self::TYPE_ARRAY:
        case self::TYPE_OBJECT:
            // فك تسلسل المصفوفات والكائنات
            switch ($serializer) {
                case 'm':
                    if (static::canUseMsgPack()) {
                        try {
                            return msgpack_unpack($data);
                        } catch (Exception $e) {
                            // Fallback إلى JSON إذا فشل
                            return binary_json_decode($data,true);
                        }
                    }
                    // Fall through إلى JSON
                case 'j':
                    try {
                        return binary_json_decode($data, true);
                    } catch (Exception $e) {
                        throw new Exception("Failed to decode JSON data: " . $e->getMessage());
                    }
                default:
                    // إذا كان serializer غير معروف، افترض أنه JSON
                    try {
                        return binary_json_decode($data, true);
                    } catch (Exception $e) {
                        throw new Exception("Failed to decode data with serializer: $serializer");
                    }
            }
            
        case self::TYPE_STRING:
            // إرجاع السلسلة كما هي
            return $data;
            
        case self::TYPE_INTEGER:
            // تحويل إلى integer
            return (int)$data;
            
        case self::TYPE_FLOAT:
            // تحويل إلى float
            return (float)$data;
            
        case self::TYPE_BOOLEAN:
            // تحويل إلى boolean
            return $data === '1' || $data === 'true' || $data === 'yes';
            
        case self::TYPE_NULL:
            // إرجاع null
            return null;
            
        default:
            // النوع غير معروف، إرجاع السلسلة كما هي
            return $data;
    }
}
    
    /**
     * التحقق من حدود الذاكرة
     */
    protected static function checkMemoryLimits(string $key, Redis $redis): array
    {
        $totalSizeKey = $key . self::TOTAL_SIZE_KEY;
        $totalSize = (int)($redis->get($totalSizeKey) ?: 0);
        
        $memoryUsageMB = $totalSize / (1024 * 1024);
        $memoryPercent = ($memoryUsageMB / self::MAX_MEMORY_MB) * 100;
        
        return [
            'current_bytes' => $totalSize,
            'current_mb' => round($memoryUsageMB, 2),
            'max_mb' => self::MAX_MEMORY_MB,
            'percent_used' => round($memoryPercent, 2),
            'needs_cleanup' => $totalSize > self::MAX_MEMORY_BYTES,
            'needs_warning' => $memoryPercent > (self::WARNING_THRESHOLD * 100)
        ];
    }
    
    /**
     * إضافة تحذير
     */
    protected static function addWarning(string $key, string $message, Redis $redis): void
    {
        $warningKey = $key . ':warnings';
        $warning = [
            'time' => microtime(true),
            'message' => $message,
            'memory_usage' => static::checkMemoryLimits($key, $redis)
        ];
        
        $redis->lPush($warningKey, binary_json_encode($warning));
        $redis->lTrim($warningKey, 0, 99); // احتفظ بـ 100 تحذير فقط
        
        error_log("Redis Warning [$key]: $message");
    }
    
    /**
     * تقسيم المصفوفة إلى دفعات صغيرة
     */
    protected static function splitIntoBatches(array $items, int $batchSize): array
    {
        $batchSize = min($batchSize, self::MAX_BATCH_SIZE);
        return array_chunk($items, $batchSize, true);
    }
    
    /**
     * زيادة عداد العمليات والتحقق من الذاكرة
     */
    protected static function incrementOperationCount(string $key): void
    {
        if (!isset(self::$operationCount[$key])) {
            self::$operationCount[$key] = 0;
        }
        
        self::$operationCount[$key]++;
        
        // تحقق من الذاكرة كل MAX_MEMORY_CHECK عملية
        if (self::$operationCount[$key] % self::MAX_MEMORY_CHECK === 0) {
            static::autoCleanup($key, self::MAX_MEMORY_MB);
        }
    }
    
    /**
     * إحصائيات الذاكرة
     */
    protected static function getMemoryUsage(string $key, Redis $redis): array
    {
        $totalSizeKey = $key . self::TOTAL_SIZE_KEY;
        $totalSize = $redis->get($totalSizeKey) ?: 0;
        
        return [
            'bytes' => (int)$totalSize,
            'kb' => round($totalSize / 1024, 2),
            'mb' => round($totalSize / (1024 * 1024), 2),
            'max_mb' => self::MAX_MEMORY_MB,
            'percent' => round(($totalSize / self::MAX_MEMORY_BYTES) * 100, 2)
        ];
    }
    
    /**
     * تسجيل الأخطاء
     */
    protected static function logError(Exception $e, string $context = ""): void
    {
        $logFile = __DIR__ . '/../logs/redis_errors.log';
        $errorMsg = sprintf(
            "[%s] [%s] Context: %s | File: %s:%d | Error: %s" . PHP_EOL,
            date('Y-m-d H:i:s'),
            static::class,
            $context,
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        );
        
        error_log($errorMsg);
        @file_put_contents($logFile, $errorMsg, FILE_APPEND);
    }
    
    /**
     * نوع الهيكل (للتفرقة)
     */
    abstract protected static function getStructureType(): string;
    
    /**
     * الحصول على اتصال Redis
     */
    protected static function getRedisConnection(): ? Redis
    {
        return RedisDatabase::get();
    }
    
    /**
     * إرجاع اتصال Redis
     */
    protected static function returnRedisConnection(Redis $redis): void
    {
        RedisDatabase::put($redis);
    }
    
    /**
     * التحقق من نوع البيانات المدعوم
     */
    protected static function isValidDataType(mixed $data): bool
    {
        $type = gettype($data);
        
        $supportedTypes = [
            'array', 'string', 'integer', 'double', 'boolean', 'NULL', 'object'
        ];
        
        return in_array($type, $supportedTypes, true);
    }
    
    /**
     * الحصول على نوع البيانات كحرف
     */
    protected static function getDataTypeChar(mixed $data): string
    {
        $type = gettype($data);
        
        switch ($type) {
            case 'array':
                return self::TYPE_ARRAY;
            case 'string':
                return self::TYPE_STRING;
            case 'integer':
                return self::TYPE_INTEGER;
            case 'double':
                return self::TYPE_FLOAT;
            case 'boolean':
                return self::TYPE_BOOLEAN;
            case 'NULL':
                return self::TYPE_NULL;
            case 'object':
                return self::TYPE_OBJECT;
            default:
                return self::TYPE_STRING;
        }
    }
}