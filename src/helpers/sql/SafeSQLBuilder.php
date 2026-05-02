<?php

namespace Nour\helpers\sql;

use Swoole\Database\MysqliProxy;

/**
 * @deprecated غير مستخدم في الـ codebase حاليًا. الكلاس بيستخدم
 * real_escape_string بدل prepared statements، عرضة لـ multi-byte charset
 * attacks لو الـ caller مرّر user input. استخدم BatchInsertHelper::insert_batch
 * بدلًا منه (يحط prepared statements). (M10 في الـ audit.)
 */
class SafeSQLBuilder
{
    /**
     * تنظيف قيمة للاستخدام في SQL
     */
    public static function escapeValue($value, bool $isNumeric = false): string
    {
        if ($value === null) {
            return 'NULL';
        }
        
        if ($isNumeric) {
            return (string)(int)$value;
        }
        
        // إذا كان boolean
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        
        // إذا كان رقم
        if (is_numeric($value)) {
            return (string)$value;
        }
        
        // للقيم النصية - سيتم إضافة الاقتباسات في الدوال الأخرى
        return $value;
    }
    
    /**
     * بناء قائمة VALUES آمنة للاستعلام
     */
    public static function buildValuesList(MysqliProxy $mysql, array $rows, array $columns): string
    {
        $values = [];
        
        foreach ($rows as $row) {
            $rowValues = [];
            
            foreach ($columns as $column => $config) {
                $value = $row[$column] ?? $config['default'] ?? null;
                $isNumeric = $config['type'] === 'int';
                
                $escapedValue = $mysql->real_escape_string($value);
                
                // إضافة اقتباسات للقيم النصية
                if (!$isNumeric && $escapedValue !== 'NULL') {
                    $escapedValue = "'" . $escapedValue . "'";
                }
                
                $rowValues[] = $escapedValue;
            }
            
            $values[] = '(' . implode(', ', $rowValues) . ')';
        }
        
        return implode(', ', $values);
    }
    
    /**
     * بناء استعلام INSERT مع JOIN
     */
    public static function buildInsertWithJoin(
        MysqliProxy $mysql ,
        string $table,
        array $columns,
        array $valuesRows,
        ?string $joinCondition = null
    ): string {
        $columnNames = array_keys($columns);
        $columnsList = '`' . implode('`, `', $columnNames) . '`';
        
        $valuesList = self::buildValuesList($mysql,$valuesRows, $columns);
        
        $query = "INSERT INTO {$table} ({$columnsList}) VALUES {$valuesList}";
        
        if ($joinCondition) {
            $query .= " ON DUPLICATE KEY UPDATE {$joinCondition}";
        }
        
        return $query;
    }
}