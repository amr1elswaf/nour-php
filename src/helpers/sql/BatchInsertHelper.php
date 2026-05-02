<?php

namespace Nour\helpers\sql;

use Swoole\Database\MysqliProxy;
use Throwable;
use Swoole\Coroutine;

class BatchInsertHelper
{
    /**
     * إدخال مجمع بأفضل أداء وآمان
     */
    public static function insert_batch(
        MysqliProxy $db,
        string $insert_sql,
        ?string $on_duplicate,
        array $data_keys,
        array $data,
        int $batch_size = 100
    ): array {
        // بداية العدادات
        $total_rows = count($data);
        $stats = [
            'total_rows' => $total_rows,
            'successful_rows' => 0,
            'failed_rows' => 0,
            'batches' => 0,
            'errors' => [],
            "failed_rows_array"=>[]

        ];
        
        if ($total_rows === 0) {
            return $stats;
        }
        
        // 1. تحديد إذا كانت المفاتيح نصية أم رقمية (بدقة)
        $is_assoc = self::isAssociativeArray($data_keys);
        
        // 2. استخراج أنواع البيانات من data_keys
        $types_string = self::extractTypesString($data_keys, $is_assoc);
        $column_count = strlen($types_string);
        
        // 3. تقسيم البيانات إلى دفعات
        $batches = array_chunk($data, $batch_size);
        $stats['batches'] = count($batches);
        
        foreach ($batches as $batch_index => $batch) {
            $batch_result = self::processBatch(
                $db,
                $insert_sql,
                $on_duplicate,
                $data_keys,
                $batch,
                $batch_index,
                $is_assoc,
                $types_string,
                $column_count
            );
            
            $stats['successful_rows'] += $batch_result['successful_rows'];
            $stats['failed_rows'] += $batch_result['failed_rows'];
            
            if (!empty($batch_result['errors'])) {
                $stats['errors'] = array_merge($stats['errors'], $batch_result['errors']);
            }
                        
            if (!empty($batch_result['failed_rows_array'])) {
                $stats['failed_rows_array'] = array_merge($stats['failed_rows_array'], $batch_result['failed_rows_array']);
            }

            
            // استراحة ذكية بين الدفعات (يمكن تعديلها)
            if (count($batch) > 50) {
                Coroutine::sleep(0.001);
            }
        }
        
        return $stats;
    }
    
    /**
     * تحديد إذا كانت المصفوفة نصية أم رقمية (بدقة)
     */
    private static function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
    
    /**
     * استخراج سلسلة الأنواع من data_keys
     */
    private static function extractTypesString(array $data_keys, bool $is_assoc): string
    {
        $types = '';
        
        if ($is_assoc) {
            // مفاتيح نصية
            foreach ($data_keys as $type) {
                $types .= $type;
            }
        } else {
            // مفاتيح رقمية
            foreach ($data_keys as $type) {
                $types .= $type;
            }
        }
        
        return $types;
    }
    
    /**
     * معالجة دفعة واحدة
     */
    private static function processBatch(
        MysqliProxy $db,
        string $insert_sql,
        ?string $on_duplicate,
        array $data_keys,
        array $batch,
        int $batch_index,
        bool $is_assoc,
        string $types_string,
        int $column_count
    ): array {
        $result = [
            'successful_rows' => 0,
            'failed_rows' => 0,
            'errors' => [],
            "failed_rows_array"=>[]
        ];
        
        $valid_rows = [];
        $all_values = [];
        
        // 1. تجميع الصفوف والقيم الصالحة
        foreach ($batch as $row_index => $row) {
            $row_values = [];
            $valid = true;
            
            if ($is_assoc) {
                // مفاتيح نصية
                foreach ($data_keys as $key => $type) {
                    if (!array_key_exists($key, $row)) {
                        $result['errors'][] = sprintf(
                            "Batch %d, Row %d: Missing key '%s'",
                            $batch_index,
                            $row_index,
                            $key
                        );
                        $result['failed_rows_array'][] = $row;
                        $valid = false;
                        break;
                    }
                    $row_values[] = $row[$key];
                }
            } else {
                // مفاتيح رقمية
                if (count($row) < count($data_keys)) {
                    $result['errors'][] = sprintf(
                        "Batch %d, Row %d: Column count mismatch (expected %d, got %d)",
                        $batch_index,
                        $row_index,
                        count($data_keys),
                        count($row)
                    );
                     $result['failed_rows_array'][] = $row;
                    $valid = false;
                } else {
                    // ⚠ نأخذ أول N قيم بس (N = column_count). لو الصف فيه مفاتيح
                    // زيادة (مثلاً metadata من Redis Queue قديم)، نتجاهلها بدل ما
                    // ندي لـ bind_param عدد قيم مغلوط.
                    $row_values = array_slice(array_values($row), 0, $column_count);
                }
            }
            
            if ($valid) {
                $valid_rows[] = $row;
                // استخدام push مباشر بدلاً من array_merge
                foreach ($row_values as $value) {
                    $all_values[] = $value;
                }
            } else {
                $result['failed_rows']++;
            }
        }
        
        if (empty($valid_rows)) {
            return $result;
        }
        
        // 2. بناء SQL بشكل صحيح وآمن
        $placeholders = [];
        $row_count = count($valid_rows);
        
        // placeholder لصف واحد
        $single_placeholder = '(' . implode(', ', array_fill(0, $column_count, '?')) . ')';
        
        // إنشاء placeholders لكل الصفوف
        for ($i = 0; $i < $row_count; $i++) {
            $placeholders[] = $single_placeholder;
        }
        // بناء SQL مباشرة (بدون str_replace)
        $sql = $insert_sql . ' VALUES ' . implode(', ', $placeholders);
        
        if ($on_duplicate !== null) {
            $sql .= ' ' . trim($on_duplicate);
        }
        // 3. تنفيذ الاستعلام
        $types = str_repeat($types_string, $row_count);
        
        try {
            // تحضير الـ statement جديد لكل دفعة (لا cache)
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new \Exception("Failed to prepare statement: " . $db->error);
            }
            
            // ربط المعاملات
            $bind_start_time = microtime(true);
            $stmt->bind_param($types, ...$all_values);
            $bind_time = microtime(true) - $bind_start_time;
            
            // تنفيذ
            $execute_start_time = microtime(true);
            $success = $stmt->execute();
            $execute_time = microtime(true) - $execute_start_time;
            
            if (!$success) {
                throw new \Exception("Execute failed: " . $stmt->error);
            }
            
            // إغلاق الـ statement
            $stmt->close();
            
            // تحديث النجاحات
            $result['successful_rows'] = $row_count;
            
            // تسجيل إذا كان التنفيذ بطيئاً
            if ($execute_time > 0.5) {
                error_log(sprintf(
                    "Slow batch insert: bind=%.3fs, execute=%.3fs, rows=%d",
                    $bind_time,
                    $execute_time,
                    $row_count
                ));
            }
            
        } catch (Throwable $e) {
            //echo ($e->getMessage());
            $result['errors'][] = sprintf(
                "Batch %d failed: %s",
                $batch_index,
                $e->getMessage()
            );

            // تحديث العدادات: الفشل فقط للصفوف التي لم تنجح
            $result['failed_rows'] += $row_count;

            // ⭐ FIX (H5): قبل كده الـ rows اللي بتفشل في execute كانت بتختفي
            // صامتة — failed_rows_array كان فاضي والـ caller (Timer) ما كانش
            // قادر يعمل requeue. دلوقتي بنحفظهم عشان الـ caller يقرر يعيدهم
            // للـ queue أو يـ alert.
            foreach ($valid_rows as $row) {
                $result['failed_rows_array'][] = $row;
            }

            // إعادة المحاولة للصفوف الفاشلة (stub — الـ caller هو المسؤول الفعلي)
            self::handleBatchFailure($valid_rows, $batch_index);
        }
        
        return $result;
    }
    
    /**
     * معالجة فشل الدفعة
     */
    private static function handleBatchFailure(array $failed_rows, int $batch_index): void
    {
        // يمكنك هنا:
        // 1. تسجيل في log
        // 2. إعادة إلى queue للمحاولة لاحقاً
        // 3. إشعار المشرف
        
        error_log(sprintf(
            "Batch %d failed, %d rows affected",
            $batch_index,
            count($failed_rows)
        ));
        
        // مثال: إعادة إلى Redis queue للمحاولة لاحقاً
        // foreach ($failed_rows as $row) {
        //     Redis::rpush('failed_inserts', json_encode($row));
        // }
    }
    
    /**
     * نسخة مبسطة للاستخدام السريع مع معالجة NULL
     */
    public static function fastInsert(
        MysqliProxy $db,
        string $table,
        array $data,
        array $update_columns = [],
        int $batch_size = 500
    ): array {
        if (empty($data)) {
            return ['successful_rows' => 0, 'failed_rows' => 0, 'batches' => 0];
        }
        
        // 1. استخراج الأعمدة من أول صف
        $first_row = reset($data);
        $columns = array_keys($first_row);
        
        // 2. تحديد أنواع البيانات مع معالجة NULL
        $data_keys = [];
        foreach ($first_row as $key => $value) {
            $data_keys[$key] = self::detectType($value);
        }
        
        // 3. معالجة البيانات لتحويل NULL
        $processed_data = [];
        foreach ($data as $row) {
            $processed_row = [];
            foreach ($columns as $col) {
                $value = $row[$col] ?? null;
                $processed_row[$col] = ($value === null) ? null : $value;
            }
            $processed_data[] = $processed_row;
        }
        
        // 4. بناء SQL
        $columns_str = implode(', ', $columns);
        $insert_sql = "INSERT INTO {$table} ({$columns_str})";
        
        $on_duplicate = null;
        if (!empty($update_columns)) {
            $updates = [];
            foreach ($update_columns as $column) {
                $updates[] = "{$column} = VALUES({$column})";
            }
            $on_duplicate = "ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
        }
        
        // 5. التنفيذ
        return self::insert_batch(
            $db,
            $insert_sql,
            $on_duplicate,
            $data_keys,
            $processed_data,
            $batch_size
        );
    }
    
    /**
     * كشف نوع البيانات مع معالجة NULL
     */
    private static function detectType($value): string
    {
        if ($value === null) {
            return 's'; // NULL كـ string وسيتم التعامل معه بشكل صحيح في bind_param
        }
        
        $type = gettype($value);
        
        $type_map = [
            'integer' => 'i',
            'double'  => 'd',
            'float'   => 'd',
            'string'  => 's',
            'boolean' => 'i', // bool كـ integer
        ];
        
        return $type_map[$type] ?? 's';
    }
    
    /**
     * دالة مساعدة للحصول على إحصائيات الأداء
     */
    public static function benchmark(
        MysqliProxy $db,
        string $table,
        int $num_rows = 10000,
        int $batch_size = 1000
    ): array {
        // إنشاء بيانات تجريبية
        $test_data = [];
        for ($i = 0; $i < $num_rows; $i++) {
            $test_data[] = [
                'name' => 'User_' . $i,
                'age' => rand(18, 60),
                'email' => 'user' . $i . '@test.com',
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        $start_time = microtime(true);
        
        $result = self::fastInsert(
            $db,
            $table,
            $test_data,
            ['name', 'age', 'email'],
            $batch_size
        );
        
        $total_time = microtime(true) - $start_time;
        
        return [
            'total_time' => $total_time,
            'rows_per_second' => $num_rows / $total_time,
            'result' => $result
        ];
    }
}