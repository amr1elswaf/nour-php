# Validation

Laravel-style declarative validator with 16 built-in rules,
extensible via `Validator::extend()`. Replaces ad-hoc input checks
sprinkled across handlers with a single readable call.

## The shape

```php
use Nour\Validation\Validator;

$result = Validator::make($data, [
    'email'    => 'required|string|email',
    'password' => 'required|string|min:8|max:64',
    'role'     => 'required|in:user,admin,teacher',
    'age'      => 'nullable|integer|between:18,120',
]);

if ($result->failed()) {
    error($result->firstError(), 400, 'validation', 'invalid_input');
}

// proceed — every field has been validated
$email = $data['email'];
```

The first argument is the data map (associative or `stdClass`-cast
to array). The second is a `field => rule_string` map — rules are
pipe-separated.

## How rule strings parse

```
'required|string|min:8|max:64'

→ [['required', ''], ['string', ''], ['min', '8'], ['max', '64']]
```

Each rule has a name and a raw `params` string (everything after
the first colon). Rules parse params themselves — `min` casts to
int, `between` splits on comma, `regex` takes the whole pattern.

## The `nullable` flow control

`nullable` is NOT a regular rule — it's a flow-control marker. When
the field's value is `null` AND the rule list contains `nullable`,
the validator skips the remaining rules for that field.

```php
'age' => 'nullable|integer|between:18,120'
```

| `age` value | Result |
|---|---|
| `null` | passes (nullable short-circuits) |
| `25` | passes (integer + between OK) |
| `'twenty'` | fails (integer rule) |
| `5` | fails (between rule) |

Order matters — put `nullable` FIRST. `'integer|nullable'` would
validate `integer` against `null` (fails) and never reach `nullable`.

## Bail-on-first

Each field stops at its first failure (Laravel's "bail" mode). The
result is one error per failed field — typical UI shape.

```php
$result = Validator::make(
    ['email' => 'not-an-email', 'password' => 'short'],
    [
        'email'    => 'required|string|email',
        'password' => 'required|string|min:8',
    ],
);
$result->messages();
// → [
//     'email'    => ['email must be a valid email'],
//     'password' => ['password must be at least 8 characters'],
//   ]
```

Apps that need exhaustive errors per field (e.g. a "fix all" UX)
can call the validator field-by-field instead.

## Reading the result

```php
$result = Validator::make($data, $rules);

$result->passes();           // true | false
$result->failed();           // !passes()
$result->messages();         // array<field, list<string>>
$result->firstError();       // string | null
$result->errorsFor('email'); // list<string>
```

`firstError()` walks the errors map in insertion order — the field
declared first in the rule map tends to surface first. Predictable
ordering keeps error UX stable.

## Built-in rules

### Type rules

| Rule | Pass when | Notes |
|---|---|---|
| `required` | value is not null, not `''`, not `[]` | `0`, `'0'`, `false` are valid values. |
| `string` | `is_string($value)` | |
| `integer` | int OR numeric-string `/^-?\d+$/` | Accepts form-data integers. |
| `numeric` | `is_numeric($value)` | Includes scientific notation. |
| `boolean` | bool, `1`, `0`, `'1'`, `'0'`, `'true'`, `'false'` | |
| `array` | `is_array($value)` | |

### Bound rules

| Rule | Behavior |
|---|---|
| `min:N` | Numeric ≥ N, string mb_strlen ≥ N, array count ≥ N. |
| `max:N` | Numeric ≤ N, string mb_strlen ≤ N, array count ≤ N. |
| `between:lo,hi` | Inclusive bound on number / string length / array count. |

Pair with a type rule first (`'integer|min:1'`) so the comparison
is unambiguous on mixed-shape inputs.

### Allowlist + format rules

| Rule | Behavior |
|---|---|
| `in:a,b,c` | `(string) $value` ∈ comma-separated list. |
| `regex:/pattern/flags` | PCRE match. Pattern must include delimiters. |
| `email` | `filter_var(..., FILTER_VALIDATE_EMAIL)`. |
| `url` | `filter_var(..., FILTER_VALIDATE_URL)`. |
| `date` | `strtotime` parses to a timestamp. Permissive (`'tomorrow'` passes). |
| `phone:eg` | Egyptian mobile regex (`01010000000`, `2010xxxxxxxx`, `+201010000000`). |

### Cross-field rule

| Rule | Behavior |
|---|---|
| `same:other_field` | `$value === $allData['other_field']`. |

```php
'password'              => 'required|string|min:8',
'password_confirmation' => 'required|string|same:password',
```

## Error message format

Messages embed `:field` which the validator replaces with the
actual field name:

```
':field is required'
':field must be at least 8 characters'
```

So a rule list keyed by `email` produces `'email is required'`,
keyed by `username` produces `'username is required'`. No per-field
templating needed.

If you need translations, do them inside your rule (return a
translated string) — there's no message-bag layer to localize at.

## Custom rules

`Validator::extend(name, RuleInterface)`:

```php
use Nour\Container\App;
use Nour\Contracts\Validation\RuleInterface;
use Nour\Validation\Validator;

App::events();   // (any framework call to ensure container exists)

Validator::extend('uppercase', new class implements RuleInterface {
    public function check(mixed $v, string $params, array $allData): true|string
    {
        if (is_string($v) && strtoupper($v) === $v && $v !== '') return true;
        return ':field must be uppercase';
    }
});
```

Now use it:

```php
'code' => 'required|string|uppercase',
```

Custom rules are global per-worker — register once in
`Bootstrap::register()`.

## Common patterns

### Login

```php
$rules = [
    'email'    => 'required|string|email|max:255',
    'password' => 'required|string|min:8|max:64',
];
$result = Validator::make((array) $data, $rules);
if ($result->failed()) {
    error($result->firstError(), 400, 'validation', 'invalid_login');
}
```

### Profile update (partial — accept missing fields)

```php
$rules = [
    'name' => 'nullable|string|min:2|max:50',
    'bio'  => 'nullable|string|max:300',
    'role' => 'nullable|in:Student,Teacher',
];
```

`nullable` plus a rule list means "if the field is present, validate
it; otherwise skip." Pair with explicit checks for fields that are
required-on-create vs optional-on-update.

### Pagination cursor

```php
$rules = [
    'limit'  => 'nullable|integer|between:1,100',
    'cursor' => 'nullable|string|max:100',
];
```

### Cross-field

```php
$rules = [
    'start_date' => 'required|date',
    'end_date'   => 'required|date',
    'tag'        => 'required|in:internal,external',
];
$result = Validator::make($data, $rules);
if ($result->failed()) error($result->firstError(), 400);

if (strtotime($data['start_date']) > strtotime($data['end_date'])) {
    error('start_date must be before end_date', 400, 'date', 'invalid_range');
}
```

`same:other` is the only cross-field rule built in — for richer
checks (greater-than-other, before-other), do them after validation
in plain PHP.

## Performance

- One `Validator::make()` call: instantiates rule classes lazily
  (only the rules in your map). Cost is dominated by string
  parsing — sub-microsecond per rule on hot code.
- Rule classes are stateless singletons (per-worker). No
  per-request allocation overhead beyond the Validator's own
  bookkeeping.
- Don't pre-compile rule strings. The parse is cheap; caching adds
  complexity without measurable savings.

## What it doesn't do

- **Type coercion** — `'integer'` checks the type but doesn't cast.
  Cast yourself after validation: `$age = (int) $data['age'];`.
- **Async DB checks** — no built-in `unique:table,column` or
  `exists:table,id`. Run those queries explicitly.
- **Conditional rules** — no `'sometimes'`, `'required_if'`, etc.
  Branch in PHP if you need this.
- **Nested validation** — flat field map only. For nested
  shapes, validate the parent first then iterate.

If you hit one of these gaps, write a custom rule with
`Validator::extend()` or do the check inline. The framework
intentionally stays small.
