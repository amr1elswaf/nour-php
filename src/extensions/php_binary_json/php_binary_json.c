/* php_binary_json.c - Lua cjson clone for PHP with raw binary support */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "Zend/zend_smart_str.h"
#include "Zend/zend_operators.h"
#include "Zend/zend_alloc.h"
#include "Zend/zend_string.h"
#include "php_binary_json.h"

/* رفض الإصدارات القديمة من PHP */
#if PHP_VERSION_ID < 80300
    #error "binary_json requires PHP 8.3 or later"
#endif

/* تعريفات الدوال المساعدة */
static zend_string* bj_encode_value(zval *data, int depth, size_t *memory_used);
static int bj_encode_string(smart_str *buf, zend_string *str, size_t *memory_used);
static int bj_encode_array(smart_str *buf, zend_array *arr, int depth, size_t *memory_used);

/* parser context */
typedef struct {
    const char *json;
    size_t len;
    size_t pos;
    int depth;
    zend_bool assoc;
    size_t memory_used;
} bj_parse_context;

/* parser helper functions */
static bj_parse_result_t bj_parse_value(bj_parse_context *ctx, zval *result);
static bj_parse_result_t bj_parse_string(bj_parse_context *ctx, zval *result);
static bj_parse_result_t bj_parse_number(bj_parse_context *ctx, zval *result);
static bj_parse_result_t bj_parse_object(bj_parse_context *ctx, zval *result);
static bj_parse_result_t bj_parse_array(bj_parse_context *ctx, zval *result);
static int bj_skip_whitespace(bj_parse_context *ctx);
static int bj_parse_unicode_escape(bj_parse_context *ctx, unsigned char *output, size_t *output_len);

/* memory management */
static void* bj_safe_emalloc(size_t size, bj_parse_context *ctx);
static void bj_safe_efree(void *ptr);
static int bj_check_memory_limit(bj_parse_context *ctx, size_t additional);

/* UTF-8 helpers */
static size_t bj_utf8_encode(unsigned int codepoint, unsigned char *buffer);

/* تعريفات معاملات الدوال */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_binary_json_encode, 0, 1, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, flags, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_binary_json_decode, 0, 1, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, json, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, assoc, _IS_BOOL, 0, "true")
ZEND_END_ARG_INFO()

/* تعريف الدوال الرئيسية */
PHP_FUNCTION(binary_json_encode)
{
    zval *data;
    zend_long flags = 0;
    
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "z|l", &data, &flags) == FAILURE) {
        RETURN_NULL();
    }
    
    size_t memory_used = 0;
    zend_string *result = bj_encode_value(data, 0, &memory_used);
    if (result) {
        RETVAL_STR(result);
    } else {
        RETURN_NULL();
    }
}

PHP_FUNCTION(binary_json_decode)
{
    char *json;
    size_t json_len;
    zend_bool assoc = 1;
    
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s|b", &json, &json_len, &assoc) == FAILURE) {
        RETURN_NULL();
    }
    
    if (json_len == 0) {
        RETURN_NULL();
    }
    
    /* Initialize parser context */
    bj_parse_context ctx = {
        .json = json,
        .len = json_len,
        .pos = 0,
        .depth = 0,
        .assoc = assoc,
        .memory_used = 0
    };
    
    /* Skip leading whitespace */
    bj_skip_whitespace(&ctx);
    
    /* Parse JSON value */
    zval result;
    ZVAL_NULL(&result);
    
    bj_parse_result_t parse_result = bj_parse_value(&ctx, &result);
    
    if (parse_result == BJ_PARSE_SUCCESS) {
        /* Skip trailing whitespace */
        bj_skip_whitespace(&ctx);
        
        /* Check if we consumed all input */
        if (ctx.pos == ctx.len) {
            ZVAL_COPY_VALUE(return_value, &result);
        } else {
            /* Extra characters after JSON */
            zval_ptr_dtor(&result);
            RETURN_NULL();
        }
    } else {
        /* Clean up if result was partially created */
        if (Z_TYPE(result) != IS_NULL) {
            zval_ptr_dtor(&result);
        }
        php_error_docref(NULL, E_WARNING, "JSON decode failed with error code: %d", parse_result);
        RETURN_NULL();
    }
}

/* ========== MEMORY MANAGEMENT ========== */

static void* bj_safe_emalloc(size_t size, bj_parse_context *ctx)
{
    if (!bj_check_memory_limit(ctx, size)) {
        return NULL;
    }
    
    void *ptr = emalloc(size);
    if (ptr) {
        ctx->memory_used += size;
    }
    return ptr;
}

static void bj_safe_efree(void *ptr)
{
    if (ptr) {
        efree(ptr);
    }
}

static int bj_check_memory_limit(bj_parse_context *ctx, size_t additional)
{
    size_t total = ctx->memory_used + additional;
    return total <= BJ_MAX_ALLOC_SIZE;
}

/* ========== UTF-8 HELPER FUNCTIONS ========== */

/* ترميز Unicode code point إلى UTF-8 */
static size_t bj_utf8_encode(unsigned int codepoint, unsigned char *buffer)
{
    if (codepoint <= 0x7F) {
        buffer[0] = (unsigned char)codepoint;
        return 1;
    } else if (codepoint <= 0x7FF) {
        buffer[0] = 0xC0 | (codepoint >> 6);
        buffer[1] = 0x80 | (codepoint & 0x3F);
        return 2;
    } else if (codepoint <= 0xFFFF) {
        buffer[0] = 0xE0 | (codepoint >> 12);
        buffer[1] = 0x80 | ((codepoint >> 6) & 0x3F);
        buffer[2] = 0x80 | (codepoint & 0x3F);
        return 3;
    } else if (codepoint <= 0x10FFFF) {
        buffer[0] = 0xF0 | (codepoint >> 18);
        buffer[1] = 0x80 | ((codepoint >> 12) & 0x3F);
        buffer[2] = 0x80 | ((codepoint >> 6) & 0x3F);
        buffer[3] = 0x80 | (codepoint & 0x3F);
        return 4;
    }
    return 0;
}

/* ========== ENCODER FUNCTIONS ========== */

/* ترميز أي نوع من البيانات */
static zend_string* bj_encode_value(zval *data, int depth, size_t *memory_used)
{
    if (depth > BJ_MAX_DEPTH) {
        php_error_docref(NULL, E_WARNING, "Maximum nesting depth exceeded");
        return NULL;
    }
    
    if (*memory_used > BJ_MAX_ALLOC_SIZE) {
        php_error_docref(NULL, E_WARNING, "Memory limit exceeded during encoding");
        return NULL;
    }
    
    smart_str buf = {0};
    
    switch (Z_TYPE_P(data)) {
        case IS_STRING:
            if (bj_encode_string(&buf, Z_STR_P(data), memory_used) != 0) {
                return NULL;
            }
            break;
            
        case IS_LONG: {
            char num_buf[32];
            int len = snprintf(num_buf, sizeof(num_buf), "%ld", Z_LVAL_P(data));
            *memory_used += len;
            if (*memory_used > BJ_MAX_ALLOC_SIZE) {
                smart_str_free(&buf);
                return NULL;
            }
            smart_str_appendl(&buf, num_buf, len);
            break;
        }
            
        case IS_DOUBLE: {
            char num_buf[64];
            int len = snprintf(num_buf, sizeof(num_buf), "%.14G", Z_DVAL_P(data));
            *memory_used += len;
            if (*memory_used > BJ_MAX_ALLOC_SIZE) {
                smart_str_free(&buf);
                return NULL;
            }
            smart_str_appendl(&buf, num_buf, len);
            break;
        }
            
        case IS_TRUE:
            *memory_used += 4;
            if (*memory_used > BJ_MAX_ALLOC_SIZE) {
                smart_str_free(&buf);
                return NULL;
            }
            smart_str_appendl(&buf, "true", 4);
            break;
            
        case IS_FALSE:
            *memory_used += 5;
            if (*memory_used > BJ_MAX_ALLOC_SIZE) {
                smart_str_free(&buf);
                return NULL;
            }
            smart_str_appendl(&buf, "false", 5);
            break;
            
        case IS_NULL:
            *memory_used += 4;
            if (*memory_used > BJ_MAX_ALLOC_SIZE) {
                smart_str_free(&buf);
                return NULL;
            }
            smart_str_appendl(&buf, "null", 4);
            break;
            
        case IS_ARRAY:
            if (bj_encode_array(&buf, Z_ARRVAL_P(data), depth + 1, memory_used) != 0) {
                smart_str_free(&buf);
                return NULL;
            }
            break;
            
        case IS_OBJECT: {
            zval tmp;
            ZVAL_OBJ(&tmp, Z_OBJ_P(data));
            if (bj_encode_array(&buf, Z_OBJPROP_P(&tmp), depth + 1, memory_used) != 0) {
                smart_str_free(&buf);
                return NULL;
            }
            break;
        }
            
        default:
            *memory_used += 4;
            if (*memory_used > BJ_MAX_ALLOC_SIZE) {
                smart_str_free(&buf);
                return NULL;
            }
            smart_str_appendl(&buf, "null", 4);
            break;
    }
    
    smart_str_0(&buf);
    if (buf.s) {
        *memory_used += ZSTR_LEN(buf.s);
    }
    
    if (*memory_used > BJ_MAX_ALLOC_SIZE) {
        smart_str_free(&buf);
        return NULL;
    }
    
    return buf.s;
}

/* ترميز السلسلة - دعم raw binary data */
static int bj_encode_string(smart_str *buf, zend_string *str, size_t *memory_used)
{
    const unsigned char *ptr = (const unsigned char *)ZSTR_VAL(str);
    size_t len = ZSTR_LEN(str);
    
    /* حساب الحجم المطلوب (أسوأ حالة) */
    size_t estimated_size = len * 6 + 2;
    *memory_used += estimated_size;
    
    if (*memory_used > BJ_MAX_ALLOC_SIZE) {
        return -1;
    }
    
    smart_str_appendc(buf, '"');
    
    for (size_t i = 0; i < len; i++) {
        unsigned char ch = ptr[i];
        
        /* Only escape characters that MUST be escaped in JSON */
        switch (ch) {
            case '"':
                smart_str_appendl(buf, "\\\"", 2);
                break;
            case '\\':
                smart_str_appendl(buf, "\\\\", 2);
                break;
            case '\b':
                smart_str_appendl(buf, "\\b", 2);
                break;
            case '\f':
                smart_str_appendl(buf, "\\f", 2);
                break;
            case '\n':
                smart_str_appendl(buf, "\\n", 2);
                break;
            case '\r':
                smart_str_appendl(buf, "\\r", 2);
                break;
            case '\t':
                smart_str_appendl(buf, "\\t", 2);
                break;
            default:
                if (ch < 32 || ch == 127) {
                    /* ASCII control characters - use \u escape */
                    char hex[7];
                    snprintf(hex, sizeof(hex), "\\u%04X", ch);
                    smart_str_appendl(buf, hex, 6);
                } else {
                    /* Raw binary: write byte as-is */
                    smart_str_appendc(buf, ch);
                }
                break;
        }
    }
    
    smart_str_appendc(buf, '"');
    return 0;
}

/* ترميز المصفوفة */
static int bj_encode_array(smart_str *buf, zend_array *arr, int depth, size_t *memory_used)
{
    if (depth > BJ_MAX_DEPTH) {
        smart_str_appendl(buf, "[]", 2);
        return -1;
    }
    
    uint32_t count = zend_array_count(arr);
    if (count == 0) {
        *memory_used += 2;
        if (*memory_used > BJ_MAX_ALLOC_SIZE) {
            return -1;
        }
        smart_str_appendl(buf, "[]", 2);
        return 0;
    }
    
    /* Check if array is sequential (list) or associative */
    zend_bool is_list = 1;
    zend_ulong expected_idx = 0;
    zend_ulong idx;
    zval *val;
    
    ZEND_HASH_FOREACH_NUM_KEY_VAL(arr, idx, val) {
        if (idx != expected_idx) {
            is_list = 0;
            break;
        }
        expected_idx++;
    } ZEND_HASH_FOREACH_END();
    
    *memory_used += 2; /* for '[' or '{' and closing character */
    if (*memory_used > BJ_MAX_ALLOC_SIZE) {
        return -1;
    }
    
    if (is_list) {
        /* Sequential array - use [] */
        smart_str_appendc(buf, '[');
        
        int first = 1;
        ZEND_HASH_FOREACH_VAL(arr, val) {
            if (!first) {
                *memory_used += 1; /* for comma */
                if (*memory_used > BJ_MAX_ALLOC_SIZE) {
                    return -1;
                }
                smart_str_appendc(buf, ',');
            }
            first = 0;
            
            zend_string *element = bj_encode_value(val, depth, memory_used);
            if (element) {
                smart_str_appendl(buf, ZSTR_VAL(element), ZSTR_LEN(element));
                zend_string_release(element);
            } else {
                *memory_used += 4; /* for "null" */
                if (*memory_used > BJ_MAX_ALLOC_SIZE) {
                    return -1;
                }
                smart_str_appendl(buf, "null", 4);
            }
        } ZEND_HASH_FOREACH_END();
        
        smart_str_appendc(buf, ']');
    } else {
        /* Associative array - use {} */
        smart_str_appendc(buf, '{');
        
        int first = 1;
        zend_string *key;
        
        ZEND_HASH_FOREACH_STR_KEY_VAL(arr, key, val) {
            if (!first) {
                *memory_used += 1; /* for comma */
                if (*memory_used > BJ_MAX_ALLOC_SIZE) {
                    return -1;
                }
                smart_str_appendc(buf, ',');
            }
            first = 0;
            
            /* Encode key */
            if (key) {
                if (bj_encode_string(buf, key, memory_used) != 0) {
                    return -1;
                }
            } else {
                /* Numeric key */
                char num_key[32];
                int key_len = snprintf(num_key, sizeof(num_key), "\"%ld\"", idx);
                *memory_used += key_len;
                if (*memory_used > BJ_MAX_ALLOC_SIZE) {
                    return -1;
                }
                smart_str_appendl(buf, num_key, key_len);
            }
            
            *memory_used += 1; /* for colon */
            if (*memory_used > BJ_MAX_ALLOC_SIZE) {
                return -1;
            }
            smart_str_appendc(buf, ':');
            
            /* Encode value */
            zend_string *element = bj_encode_value(val, depth, memory_used);
            if (element) {
                smart_str_appendl(buf, ZSTR_VAL(element), ZSTR_LEN(element));
                zend_string_release(element);
            } else {
                *memory_used += 4; /* for "null" */
                if (*memory_used > BJ_MAX_ALLOC_SIZE) {
                    return -1;
                }
                smart_str_appendl(buf, "null", 4);
            }
        } ZEND_HASH_FOREACH_END();
        
        smart_str_appendc(buf, '}');
    }
    
    return 0;
}

/* ========== PARSER FUNCTIONS ========== */

/* تحليل escape sequence من نوع \uXXXX */
static int bj_parse_unicode_escape(bj_parse_context *ctx, unsigned char *output, size_t *output_len)
{
    if (ctx->pos + 4 > ctx->len) {
        return 0;
    }
    
    /* تحويل hex إلى Unicode code point */
    unsigned int codepoint = 0;
    for (int i = 0; i < 4; i++) {
        char c = ctx->json[ctx->pos + i];
        unsigned int digit;
        
        if (c >= '0' && c <= '9') {
            digit = c - '0';
        } else if (c >= 'a' && c <= 'f') {
            digit = 10 + (c - 'a');
        } else if (c >= 'A' && c <= 'F') {
            digit = 10 + (c - 'A');
        } else {
            return 0;
        }
        
        codepoint = (codepoint << 4) | digit;
    }
    
    ctx->pos += 4;
    
    /* ترميز code point إلى UTF-8 */
    *output_len = bj_utf8_encode(codepoint, output);
    return (*output_len > 0);
}

/* تخطي المسافات البيضاء */
static int bj_skip_whitespace(bj_parse_context *ctx)
{
    while (ctx->pos < ctx->len) {
        char c = ctx->json[ctx->pos];
        if (c == ' ' || c == '\t' || c == '\n' || c == '\r') {
            ctx->pos++;
        } else {
            break;
        }
    }
    return ctx->pos < ctx->len;
}

/* تحليل قيمة JSON */
static bj_parse_result_t bj_parse_value(bj_parse_context *ctx, zval *result)
{
    if (!bj_skip_whitespace(ctx)) {
        return BJ_PARSE_UNEXPECTED_CHAR;
    }
    
    char c = ctx->json[ctx->pos];
    
    switch (c) {
        case '"':
            return bj_parse_string(ctx, result);
        case 'n':
            if (ctx->pos + 3 < ctx->len && 
                memcmp(ctx->json + ctx->pos, "null", 4) == 0) {
                ZVAL_NULL(result);
                ctx->pos += 4;
                return BJ_PARSE_SUCCESS;
            }
            break;
        case 't':
            if (ctx->pos + 3 < ctx->len && 
                memcmp(ctx->json + ctx->pos, "true", 4) == 0) {
                ZVAL_TRUE(result);
                ctx->pos += 4;
                return BJ_PARSE_SUCCESS;
            }
            break;
        case 'f':
            if (ctx->pos + 4 < ctx->len && 
                memcmp(ctx->json + ctx->pos, "false", 5) == 0) {
                ZVAL_FALSE(result);
                ctx->pos += 5;
                return BJ_PARSE_SUCCESS;
            }
            break;
        case '[':
            return bj_parse_array(ctx, result);
        case '{':
            return bj_parse_object(ctx, result);
        default:
            if ((c >= '0' && c <= '9') || c == '-' || c == '+' || c == '.') {
                return bj_parse_number(ctx, result);
            }
            break;
    }
    
    return BJ_PARSE_UNEXPECTED_CHAR;
}

/* تحليل سلسلة مع دعم raw binary ومعالجة UTF-8 بشكل صحيح */
static bj_parse_result_t bj_parse_string(bj_parse_context *ctx, zval *result)
{
    size_t start = ++ctx->pos;  /* Skip opening quote */
    size_t str_len = 0;
    
    /* First pass: calculate length */
    while (ctx->pos < ctx->len && ctx->json[ctx->pos] != '"') {
        if (ctx->json[ctx->pos] == '\\') {
            ctx->pos++;  /* Skip escape char */
            if (ctx->pos >= ctx->len) {
                return BJ_PARSE_UNEXPECTED_CHAR;
            }
            
            if (ctx->json[ctx->pos] == 'u') {
                ctx->pos++;  /* Skip 'u' */
                str_len += BJ_UTF8_MAX_LEN; /* أقصى طول لرمز UTF-8 */
                if (ctx->pos + 3 >= ctx->len) {
                    return BJ_PARSE_UNEXPECTED_CHAR;
                }
                ctx->pos += 3; /* Skip hex digits (يتم التحقق منها لاحقاً) */
            } else {
                /* Other escape sequences add 1 character */
                str_len++;
            }
        } else {
            str_len++;
        }
        
        if (ctx->pos >= ctx->len) {
            break;
        }
        ctx->pos++;
    }
    
    if (ctx->pos >= ctx->len) {
        return BJ_PARSE_UNEXPECTED_CHAR;
    }
    
    /* Allocate buffer */
    char *buffer = bj_safe_emalloc(str_len + 1, ctx);
    if (!buffer) {
        return BJ_PARSE_MEMORY_ERROR;
    }
    
    size_t write_pos = 0;
    
    /* Reset pos and copy data */
    ctx->pos = start;
    while (ctx->pos < ctx->len && ctx->json[ctx->pos] != '"') {
        if (ctx->json[ctx->pos] == '\\') {
            ctx->pos++;
            if (ctx->pos >= ctx->len) {
                bj_safe_efree(buffer);
                return BJ_PARSE_UNEXPECTED_CHAR;
            }
            
            /* Handle escape sequences */
            switch (ctx->json[ctx->pos]) {
                case '"': buffer[write_pos++] = '"'; break;
                case '\\': buffer[write_pos++] = '\\'; break;
                case '/': buffer[write_pos++] = '/'; break;
                case 'b': buffer[write_pos++] = '\b'; break;
                case 'f': buffer[write_pos++] = '\f'; break;
                case 'n': buffer[write_pos++] = '\n'; break;
                case 'r': buffer[write_pos++] = '\r'; break;
                case 't': buffer[write_pos++] = '\t'; break;
                case 'u': {
                    ctx->pos++;  /* Skip 'u' */
                    unsigned char utf8_buffer[BJ_UTF8_MAX_LEN];
                    size_t utf8_len = 0;
                    
                    if (!bj_parse_unicode_escape(ctx, utf8_buffer, &utf8_len)) {
                        bj_safe_efree(buffer);
                        return BJ_PARSE_INVALID_UTF8;
                    }
                    
                    memcpy(buffer + write_pos, utf8_buffer, utf8_len);
                    write_pos += utf8_len;
                    continue; /* Already incremented pos in bj_parse_unicode_escape */
                }
                default:
                    /* Raw byte after backslash - treat as literal */
                    buffer[write_pos++] = ctx->json[ctx->pos];
                    break;
            }
        } else {
            /* Raw binary byte - copy as-is */
            buffer[write_pos++] = ctx->json[ctx->pos];
        }
        ctx->pos++;
    }
    
    if (ctx->pos >= ctx->len || ctx->json[ctx->pos] != '"') {
        bj_safe_efree(buffer);
        return BJ_PARSE_UNEXPECTED_CHAR;
    }
    
    buffer[write_pos] = '\0';
    ctx->pos++;  /* Skip closing quote */
    
    ZVAL_STRINGL(result, buffer, write_pos);
    bj_safe_efree(buffer);
    return BJ_PARSE_SUCCESS;
}

/* تحليل رقم */
static bj_parse_result_t bj_parse_number(bj_parse_context *ctx, zval *result)
{
    size_t start = ctx->pos;
    
    /* Consume number characters */
    while (ctx->pos < ctx->len) {
        char c = ctx->json[ctx->pos];
        if ((c >= '0' && c <= '9') || c == '.' || c == 'e' || c == 'E' || 
            c == '+' || c == '-' || c == 'x' || c == 'X' || 
            (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F')) {
            ctx->pos++;
        } else {
            break;
        }
    }
    
    if (ctx->pos == start) {
        return BJ_PARSE_UNEXPECTED_CHAR;
    }
    
    /* Parse number */
    char *endptr;
    double dval = strtod(ctx->json + start, &endptr);
    
    if (endptr == ctx->json + start) {
        return BJ_PARSE_UNEXPECTED_CHAR;
    }
    
    /* Check if it's an integer */
    if (dval == (zend_long)dval && strchr(ctx->json + start, '.') == NULL) {
        ZVAL_LONG(result, (zend_long)dval);
    } else {
        ZVAL_DOUBLE(result, dval);
    }
    
    return BJ_PARSE_SUCCESS;
}

/* تحليل مصفوفة مع تنظيف zval في حالات الخطأ */
static bj_parse_result_t bj_parse_array(bj_parse_context *ctx, zval *result)
{
    if (ctx->depth > BJ_MAX_DEPTH) {
        return BJ_PARSE_DEPTH_EXCEEDED;
    }
    
    ctx->pos++;  /* Skip '[' */
    ctx->depth++;
    
    array_init(result);
    
    bj_skip_whitespace(ctx);
    
    /* Check for empty array */
    if (ctx->pos < ctx->len && ctx->json[ctx->pos] == ']') {
        ctx->pos++;
        ctx->depth--;
        return BJ_PARSE_SUCCESS;
    }
    
    while (ctx->pos < ctx->len) {
        zval element;
        ZVAL_NULL(&element);
        
        bj_parse_result_t res = bj_parse_value(ctx, &element);
        
        if (res != BJ_PARSE_SUCCESS) {
            zval_ptr_dtor(result);
            ctx->depth--;
            return res;
        }
        
        add_next_index_zval(result, &element);
        
        bj_skip_whitespace(ctx);
        
        if (ctx->pos >= ctx->len) {
            zval_ptr_dtor(result);
            ctx->depth--;
            return BJ_PARSE_UNEXPECTED_CHAR;
        }
        
        if (ctx->json[ctx->pos] == ']') {
            ctx->pos++;
            ctx->depth--;
            return BJ_PARSE_SUCCESS;
        } else if (ctx->json[ctx->pos] == ',') {
            ctx->pos++;
            bj_skip_whitespace(ctx);
        } else {
            zval_ptr_dtor(result);
            ctx->depth--;
            return BJ_PARSE_UNEXPECTED_CHAR;
        }
    }
    
    zval_ptr_dtor(result);
    ctx->depth--;
    return BJ_PARSE_UNEXPECTED_CHAR;
}

/* تحليل كائن مع تنظيف zval في حالات الخطأ */
static bj_parse_result_t bj_parse_object(bj_parse_context *ctx, zval *result)
{
    if (ctx->depth > BJ_MAX_DEPTH) {
        return BJ_PARSE_DEPTH_EXCEEDED;
    }
    
    ctx->pos++;  /* Skip '{' */
    ctx->depth++;
    
    if (ctx->assoc) {
        array_init(result);
    } else {
        object_init(result);
    }
    
    bj_skip_whitespace(ctx);
    
    /* Check for empty object */
    if (ctx->pos < ctx->len && ctx->json[ctx->pos] == '}') {
        ctx->pos++;
        ctx->depth--;
        return BJ_PARSE_SUCCESS;
    }
    
    while (ctx->pos < ctx->len) {
        /* Parse key */
        zval key_zval;
        ZVAL_NULL(&key_zval);
        
        if (ctx->json[ctx->pos] != '"') {
            zval_ptr_dtor(result);
            ctx->depth--;
            return BJ_PARSE_UNEXPECTED_CHAR;
        }
        
        bj_parse_result_t key_res = bj_parse_string(ctx, &key_zval);
        if (key_res != BJ_PARSE_SUCCESS) {
            zval_ptr_dtor(result);
            ctx->depth--;
            return key_res;
        }
        
        bj_skip_whitespace(ctx);
        
        /* Check for colon */
        if (ctx->pos >= ctx->len || ctx->json[ctx->pos] != ':') {
            zval_ptr_dtor(&key_zval);
            zval_ptr_dtor(result);
            ctx->depth--;
            return BJ_PARSE_UNEXPECTED_CHAR;
        }
        
        ctx->pos++;  /* Skip ':' */
        bj_skip_whitespace(ctx);
        
        /* Parse value */
        zval value_zval;
        ZVAL_NULL(&value_zval);
        
        bj_parse_result_t val_res = bj_parse_value(ctx, &value_zval);
        if (val_res != BJ_PARSE_SUCCESS) {
            zval_ptr_dtor(&key_zval);
            zval_ptr_dtor(result);
            ctx->depth--;
            return val_res;
        }
        
        /* Add to result */
        if (ctx->assoc) {
            add_assoc_zval(result, Z_STRVAL(key_zval), &value_zval);
        } else {
            add_property_zval(result, Z_STRVAL(key_zval), &value_zval);
        }
        
        zval_ptr_dtor(&key_zval);
        
        bj_skip_whitespace(ctx);
        
        if (ctx->pos >= ctx->len) {
            zval_ptr_dtor(result);
            ctx->depth--;
            return BJ_PARSE_UNEXPECTED_CHAR;
        }
        
        if (ctx->json[ctx->pos] == '}') {
            ctx->pos++;
            ctx->depth--;
            return BJ_PARSE_SUCCESS;
        } else if (ctx->json[ctx->pos] == ',') {
            ctx->pos++;
            bj_skip_whitespace(ctx);
        } else {
            zval_ptr_dtor(result);
            ctx->depth--;
            return BJ_PARSE_UNEXPECTED_CHAR;
        }
    }
    
    zval_ptr_dtor(result);
    ctx->depth--;
    return BJ_PARSE_UNEXPECTED_CHAR;
}

/* قائمة الدوال */
static const zend_function_entry binary_json_functions[] = {
    PHP_FE(binary_json_encode, arginfo_binary_json_encode)
    PHP_FE(binary_json_decode, arginfo_binary_json_decode)
    PHP_FE_END
};

/* تعريف الـ module */
zend_module_entry binary_json_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_BINARY_JSON_EXTNAME,
    binary_json_functions,
    NULL,  /* PHP_MINIT */
    NULL,  /* PHP_MSHUTDOWN */
    NULL,  /* PHP_RINIT */
    NULL,  /* PHP_RSHUTDOWN */
    NULL,  /* PHP_MINFO */
    PHP_BINARY_JSON_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_BINARY_JSON
ZEND_GET_MODULE(binary_json)
#endif