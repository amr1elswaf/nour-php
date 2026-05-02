#ifndef PHP_BINARY_JSON_H
#define PHP_BINARY_JSON_H

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"

#ifdef ZTS
#include "TSRM.h"
#endif

/* Module entry */
extern zend_module_entry binary_json_module_entry;
#define phpext_binary_json_ptr &binary_json_module_entry

/* Version info */
#define PHP_BINARY_JSON_VERSION "2.0.0"
#define PHP_BINARY_JSON_EXTNAME "binary_json"

/* Memory limits */
#define BJ_MAX_DEPTH 1000
#define BJ_MAX_ALLOC_SIZE (1024 * 1024 * 100) /* 100MB */
#define BJ_UTF8_MAX_LEN 4 /* Maximum UTF-8 character length */

/* Parse result codes */
typedef enum {
    BJ_PARSE_SUCCESS = 0,
    BJ_PARSE_INVALID_JSON = -1,
    BJ_PARSE_DEPTH_EXCEEDED = -2,
    BJ_PARSE_UNEXPECTED_CHAR = -3,
    BJ_PARSE_MEMORY_ERROR = -4,
    BJ_PARSE_INVALID_UTF8 = -5
} bj_parse_result_t;

/* Function declarations */
PHP_FUNCTION(binary_json_encode);
PHP_FUNCTION(binary_json_decode);

#endif /* PHP_BINARY_JSON_H */