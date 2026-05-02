<?php

namespace Nour\helpers;

use ErrorException;

enum DataType: string
{
    case Int       = 'int';
    case Null      = 'null';
    case Str       = 'string';
    case Bool      = 'bool';
    case Float     = 'float';
    case Arr       = 'array';
    case Obj       = 'object';
    case Numeric   = 'numeric';
    case Scalar    = 'scalar';
    case Callable_ = 'callable';
    case Iterable_ = 'iterable';
    case Countable = 'countable';
    case Resource  = 'resource';
}

final class IsValidSomething
{
    public static function isValidEgyptPhone(string $phone): bool|int
    {
        // يدعم +20 أو 0020 أو بدون مفتاح الدولة
        return preg_match('/^(?:\+?20|0020)?0?(10|11|12|15)[0-9]{8}$/', $phone);
    }

    public static function isValidEmail(string $email): array
    {

        $result = [
            'valid' => false,
            'error' => ""
        ];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $result = [
                'valid' => false,
                'error' => 'Please enter a valid email address'
            ];
            return  $result;
        }

        if (strlen($email) > 254) {
            $result = [
                'valid' => false,
                'error' => 'Email address is too long'
            ];
            return  $result;
        }

        // التحقق من النطاق المسموح به (اختياري)
        if (!self::Email_is_allowed_email_domain($email)) {

            $result = [
                'valid' => false,
                'error' => 'Email domain is not allowed'
            ];
            return  $result;
        }


        $result = [
            'valid' => true,
            'error' => 'Email domain is not allowed'
        ];
        return $result;
    }


    /**
     * التحقق من البينات و انواعها ل طلب http
     *
     * @param object $data         الـ payload الكامل
     * @param string $key          اسم الحقل
     * @param array  $allowedTypes ['int', 'string', 'array', ...]
     * @param int|null $maxLen     سقف الـ chars/items للـ string/array (M12).
     *                             null = بدون سقف (use with caution).
     */
    public static function isValidDataInput(object $data, string $key, array $allowedTypes, ?int $maxLen = null): void
    {
        if (!property_exists($data, $key)) {
            error("Undefined property: {$key}");
        }

        $value = $data->$key;

        // ترتيب التحقق حسب الشيوع: int -> null -> string -> bool -> float -> array
        $matched = false;
        foreach ($allowedTypes as $type) {
            if (self::isType($value, $type)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            error($key . " must be one of: " . implode(', ', $allowedTypes));
        }

        // M12 fix: السقف بيحمي من memory exhaustion (مثلًا message body
        // بـ 50MB string). default null للـ backward-compat — كل callsite
        // محتاج يعدّ نفسه بسقف معقول.
        if ($maxLen !== null) {
            if (is_string($value) && mb_strlen($value, 'UTF-8') > $maxLen) {
                error("{$key} too long (max {$maxLen} chars)");
            }
            if (is_array($value) && count($value) > $maxLen) {
                error("{$key} too long (max {$maxLen} items)");
            }
        }
    }





    private static function isType($value, string $type): bool
    {
        $dt = DataType::tryFrom($type);
        if ($dt === null) return false;

        return match ($dt) {
            DataType::Int       => is_int($value),
            DataType::Null      => $value === null,
            DataType::Str       => is_string($value),
            DataType::Bool      => is_bool($value),
            DataType::Float     => is_float($value),
            DataType::Arr       => is_array($value),
            DataType::Obj       => is_object($value),
            DataType::Numeric   => is_numeric($value),
            DataType::Scalar    => is_scalar($value),
            DataType::Callable_ => is_callable($value),
            DataType::Iterable_ => is_iterable($value),
            DataType::Countable => is_countable($value),
            DataType::Resource  => is_resource($value),
        };
    }

    /**
     * التحقق من نطاق البريد الإلكتروني (يمكن تخصيصه)
     */

    public static function isValidTimeFormat(?string $last_time): bool
    {
        if ($last_time === null) return false;
        return preg_match(
            '/^(?:2[0-3]|[01][0-9]):[0-5][0-9](?::[0-5][0-9])?$/',
            $last_time
        ) === 1;
    }


    public static function isValidHexColor($color)
    {
        // يتحقق من #RGB, #RRGGBB, #RGBA, #RRGGBBAA
        return preg_match('/^#([a-fA-F0-9]{3,4}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})$/', $color) === 1;
    }


    public static function isValidDateFormat(?string $date): bool
    {
        if ($date === null) return false;
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    public static function isValidYearMonthFormat(?string $month): bool
    {
        if ($month === null) return false;
        $d = \DateTime::createFromFormat('Y-m', $month);
        return $d && $d->format('Y-m') === $month;
    }

    public static function isValidEgyptLocation(float $latitude, float $longitude)
    {
        // تأكد إن $location موجود وفيه فاصلة
        /*if (empty($location) || strpos($location, ',') === false) {
        return false;
    }
    */
        //list($latitude, $longitude) = explode(",", $location);

        // تأكد من إن القيم أرقام
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }

        $latitude = floatval($latitude);
        $longitude = floatval($longitude);

        // تأكد من إن الإحداثيات داخل الحدود العامة لأي إحداثيات على الخريطة
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return false;
        }

        // تحقق أن النقطة داخل حدود مصر
        if ($latitude >= 22 && $latitude <= 32.5 && $longitude >= 24 && $longitude <= 36) {
            return true;
        }

        return false;
    }

    public static function isValidPassword(string $password): array
    {
        $result = [
            'valid' => false,
            'error' => ""
        ];


        if (strlen($password) < 8) {
            $result['error'] = "password must be more than 8 char and less than 40 char";
            return $result;
        }
        //تحقق من ما اذا كان الباسورد طويل
        if (strlen($password) > 40) {
            $result['error'] = "password must be more than 8 char and less than 40 char";
            return $result;
        }

        if (!preg_match('/[a-z]/', $password)) {
            $result['error']  = 'password must contain at least one lowercase letter';
            return $result;
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $result['error']  = 'password must contain at least one capital letter.';
            return $result;
        }
        if (!preg_match('/\d/', $password)) {
            $result['error']  = 'password must contain at least one number.';
            return $result;
        }
        if (!preg_match('/[\W_]/', $password)) {
            $result['error']  = 'password must contain at least one character (such as !@#$% or _).';
            return $result;
        }

        // L5 fix: كان `if (empty($result['errors']))` (المفتاح غلط، وbool
        // empty على undefined key always true). كل الـ checks فوق بترجع
        // early عند الفشل، فلو وصلنا هنا الباسوورد valid.
        $result['valid'] = true;
        return $result;
    }


    //دوال مساعده 
    private static function Email_is_allowed_email_domain(string $email): bool
    {
        $disallowed_domains = [
            'tempmail.com',
            'guerrillamail.com',
            'mailinator.com',
            '10minutemail.com'
        ];
        $domain = strtolower(substr(strrchr($email, "@"), 1));
        return !in_array($domain, $disallowed_domains);
    }
}
