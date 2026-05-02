<?php
namespace Nour\helpers;

final class NameValidator {
    private static $offensiveNames = null;
    
    private static function loadOffensiveNames() {
        if (self::$offensiveNames === null) {
            $filePath = __DIR__ . '/config/offensive-names.json';
            if (file_exists($filePath)) {
                $content = file_get_contents($filePath);
                self::$offensiveNames = json_decode($content, true) ?: [];
            } else {
                // قائمة احتياطية
                self::$offensiveNames = [
                    "admin", "root", "null", "test", "fuck", "sex",
                    "نيك", "عرص", "خول", "شرموط", "كس", "كسمك"
                ];
            }
        }
    }
    
    public static function validate(string $firstName,string $lastName) {
        // تنظيف المدخلات
        $fname = trim($firstName);
        $lname = trim($lastName);

        // تحقق أساسي
        if (empty($fname) || empty($lname)) {
            return ["valid" => false, "error" => "Name cannot be empty"];
        }

        // M7 fix: mb_strlen عوضًا عن strlen — الأسماء العربية UTF-8 و
        // strlen("عل") = 4 (bytes) لكن mb_strlen = 2 (chars). الـ check القديم
        // كان بيقبل اسم من حرف عربي واحد ("ع").
        if (mb_strlen($fname, 'UTF-8') < 2 || mb_strlen($lname, 'UTF-8') < 2) {
            return ["valid" => false, "error" => "Name too short"];
        }

        // تحقق من الأسماء المسيئة
        self::loadOffensiveNames();
        // mb_strtolower بدل strtolower — strtolower ASCII-only فما بتأثرش
        // على العربي. الـ /i flag في الـ regex بيعمل case-insensitive للـ
        // ASCII، فالـ Arabic offensive names لازم تتخزن بالـ form الأصلي.
        $fullName = mb_strtolower($fname . ' ' . $lname, 'UTF-8');

        foreach (self::$offensiveNames as $offensive) {
            // الـ /u flag = unicode regex — مطلوب علشان \b يشتغل على Arabic.
            if (preg_match('/\b' . preg_quote($offensive, '/') . '\b/iu', $fullName)) {
                return ["valid" => false, "error" => "Please choose an appropriate name"];
            }
        }

        return ["valid" => true];
    }
}

