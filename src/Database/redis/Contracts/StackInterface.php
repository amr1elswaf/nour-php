<?php

namespace Nour\Database\redis\Contracts;

interface StackInterface
{
    /**
     * دفع عنصر إلى المكدس (LIFO)
     */
    public static function push(string $key, mixed $element, ?int $ttl = null): string|bool;
    
    /**
     * سحب عنصر من المكدس (LIFO)
     */
    public static function pop(string $key): mixed;
    
    /**
     * مشاهدة العنصر العلوي بدون سحبه
     */
    public static function peek(string $key): mixed;
    
    /**
     * دفع عناصر متعددة
     */
    public static function pushMultiple(string $key, array $elements, int $batchSize = 100): array;
    
    /**
     * سحب عناصر متعددة للمعالجة
     */
    public static function popMultiple(string $key, int $limit = 50): array;
    
    /**
     * الحجم الحالي للمكدس
     */
    public static function size(string $key): ?int;
    
    /**
     * التحقق إذا كان المكدس فارغاً
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
     * جلب إحصائيات المكدس
     */
    public static function getStats(string $key): array;
}