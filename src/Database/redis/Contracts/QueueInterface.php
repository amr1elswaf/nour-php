<?php

namespace Nour\Database\redis\Contracts;

interface QueueInterface
{
    /**
     * إضافة عنصر إلى الطابور (FIFO)
     */
    public static function enqueue(string $key, mixed $element, ?int $ttl = null): bool;
    
    /**
     * إزالة عنصر من الطابور (FIFO)
     */
    public static function dequeue(string $key, int $timeout = 0): mixed;
    
    /**
     * مشاهدة العنصر الأمامي بدون إزالته
     */
    public static function peek(string $key): mixed;
    
    /**
     * إضافة عناصر متعددة
     */
    public static function enqueueMultiple(string $key, array $elements, int $batchSize = 100): array;
    
    /**
     * إزالة عناصر متعددة للمعالجة
     */
    public static function dequeueMultiple(string $key, int $limit = 50): array;
    
    /**
     * الحجم الحالي للطابور
     */
    public static function size(string $key): ?int;
    
    /**
     * التحقق إذا كان الطابور فارغاً
     */
    public static function isEmpty(string $key): bool;
    
    /**
     * حذف عناصر محددة بالأيدي
     */
    public static function deleteByIds(string $key, array $elementIds): array;
    
    /**
     * حذف العناصر التي تمت معالجتها
     */
    public static function deleteProcessed(string $key, array $processedIds): array;
    
    /**
     * تنظيف ذكي بناءً على حجم الذاكرة
     */
    public static function autoCleanup(string $key, int $maxMemoryMB = 100): array;
    
    /**
     * جلب إحصائيات الطابور
     */
    public static function getStats(string $key): array;
    
    /**
     * Blocking dequeue مع timeout
     */
    public static function blockingDequeue(string $key, int $timeout = 10): ?array;
}