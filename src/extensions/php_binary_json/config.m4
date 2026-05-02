PHP_ARG_ENABLE(binary_json, whether to enable binary_json support,
[  --enable-binary_json       Enable binary JSON support with Lua cjson compatibility])

if test "$PHP_BINARY_JSON" != "no"; then
    
    AC_MSG_CHECKING([PHP version])
    
    # التحقق من إصدار PHP
    if test "$PHP_VERSION_ID" -lt "80300"; then
        AC_MSG_ERROR([binary_json requires PHP 8.3 or later])
    fi
    
    AC_MSG_RESULT([$PHP_VERSION])
    
    # إعدادات التحذير
    if test "$PHP_DEBUG" = "1"; then
        CFLAGS="$CFLAGS -Wall -Wextra -Werror -O0 -g"
    else
        CFLAGS="$CFLAGS -Wall -Wextra -O2"
    fi
    
    # إنشاء الامتداد
    PHP_NEW_EXTENSION(binary_json, php_binary_json.c, $ext_shared)
    
    # إضافة include path
    PHP_ADD_INCLUDE([$ext_srcdir])
    
    # تسجيل ملف الرأس للتثبيت
    PHP_INSTALL_HEADERS([ext/binary_json], [php_binary_json.h])
    
    AC_MSG_RESULT([Binary JSON extension enabled])
fi