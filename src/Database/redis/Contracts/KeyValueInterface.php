<?php

namespace Nour\Database\redis\Contracts;

interface KeyValueInterface
{
    /**
     * تعيين قيمة لمفتاح
     */
    public static function set(string $key, string $field, mixed $value, ?int $ttl = null): string|bool;
    
    /**
     * الحصول على قيمة مفتاح
     */
    public static function get(string $key, string $field): mixed;
    
    /**
     * الحصول على عدة قيم في وقت واحد
     */
    public static function mget(string $key, array $fields): array;
    
    /**
     * تعيين عدة قيم في وقت واحد
     */
    public static function mset(string $key, array $fieldValuePairs, int $batchSize = 100): array;
    
    /**
     * حذف مفتاح
     */
    public static function delete(string $key, string $field): bool;
    
    /**
     * حذف عدة مفاتيح
     */
    public static function deleteMultiple(string $key, array $fields): array;
    
    /**
     * التحقق من وجود مفتاح
     */
    public static function has(string $key, string $field): bool;
    
    /**
     * مسح جميع المفاتيح
     */
    public static function clear(string $key): bool;
    
    /**
     * جلب جميع المفاتيح
     */
    public static function keys(string $key, string $pattern = '*'): array;
    
    /**
     * حجم التخزين
     */
    public static function size(string $key): int;
    
    /**
     * التحقق إذا كان فارغاً
     */
    public static function isEmpty(string $key): bool;
    
    /**
     * زيادة قيمة رقمية
     */
    public static function increment(string $key, string $field, int $value = 1, ?int $ttl = null): int|bool;

    /**
     * تقليل قيمة رقمية
     */
    public static function decrement(string $key, string $field, int $value = 1, ?int $ttl = null): int|bool;
    
    /**
     * تعيين TTL لمفتاح
     */
    public static function expire(string $key, string $field, int $ttl): bool;
    
    /**
     * الحصول على TTL المتبقي
     */
    public static function ttl(string $key, string $field): ?int;
    
    /**
     * إحصائيات التخزين
     */
    public static function getStats(string $key): array;
    
    /**
     * تنظيف ذكي بناءً على حجم الذاكرة
     */
    public static function autoCleanup(string $key, int $maxMemoryMB = 100): array;
    
    /**
     * البحث في القيم
     */
    public static function search(string $key, string $pattern): array;
    
    /**
     * فرز المفاتيح
     */
    public static function sort(string $key, ?string $by , array $options = []): array;
}