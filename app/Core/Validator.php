<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Typed input validation with an allow-list philosophy: a field that has no
 * rule is not carried into validated(), so a mass-assignment can never smuggle
 * an extra column into a model.
 *
 * Rules: required, nullable, string, int, numeric, bool, email, url, slug,
 * in:a,b,c, min:n, max:n, between:a,b, regex:/.../, ip, ipv4, cidr, port,
 * hex:len, uuid, date, confirmed, different:field, same:field, array, json,
 * mac, hostname, timezone, password.
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];
    /** @var array<string,mixed> */
    private array $validated = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules field => 'required|string|max:64'
     * @param array<string,string> $labels field => human label for messages
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $labels = [],
    ) {
        $this->run();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public static function validate(array $data, array $rules, array $labels = []): array
    {
        $validator = new self($data, $rules, $labels);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        return $this->validated;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = array_filter(explode('|', $ruleString));
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            $isRequired = in_array('required', $rules, true);
            $isNullable = in_array('nullable', $rules, true);
            $isEmpty = $value === null || $value === '' || $value === [];

            if ($isEmpty) {
                if ($isRequired) {
                    $this->fail($field, 'required');
                    continue;
                }
                // An absent optional field is stored as null so callers can tell
                // "not supplied" from "supplied empty".
                if ($isNullable || !$isRequired) {
                    $this->validated[$field] = null;
                }
                continue;
            }

            $cast = $value;
            $failed = false;

            foreach ($rules as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);

                // A rule reports pass/fail and its cast value separately.
                // Comparing the return value against true would make a rule
                // that legitimately casts to boolean false look like a
                // failure — and would silently drop the cast.
                $result = $this->applyRule((string) $name, $arg, $cast, $field);

                if (!$result['ok']) {
                    $failed = true;
                    break;
                }
                if ($result['cast']) {
                    $cast = $result['value'];
                }
            }

            if (!$failed) {
                $this->validated[$field] = $cast;
            }
        }
    }

    /**
     * Apply one rule.
     *
     * @return array{ok:bool,value:mixed,cast:bool} `ok` is pass/fail; when
     *         `cast` is true, `value` replaces the field's value. Keeping
     *         these separate is what lets a rule cast to `false`, `0` or `''`
     *         without that being mistaken for a validation failure.
     */
    private function applyRule(string $name, ?string $arg, mixed $value, string $field): array
    {
        switch ($name) {
            case 'string':
                return is_string($value)
                    ? self::pass()
                    : $this->reject($field, 'must be text');

            case 'int':
                if (!is_numeric($value) || (string) (int) $value !== (string) $value) {
                    return $this->reject($field, 'must be a whole number');
                }

                return self::cast((int) $value);

            case 'numeric':
                return is_numeric($value)
                    ? self::cast($value + 0)
                    : $this->reject($field, 'must be a number');

            case 'bool':
                $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                return $bool === null
                    ? $this->reject($field, 'must be true or false')
                    : self::cast($bool);

            case 'array':
                return is_array($value) ? self::pass() : $this->reject($field, 'must be a list');

            case 'json':
                if (!is_string($value) || (json_decode($value) === null && json_last_error() !== JSON_ERROR_NONE)) {
                    return $this->reject($field, 'must be valid JSON');
                }

                return self::pass();

            case 'email':
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false || strlen($value) > 190) {
                    return $this->reject($field, 'must be a valid email address');
                }

                return self::cast(strtolower($value));

            case 'url':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
                    ? self::pass()
                    : $this->reject($field, 'must be a valid URL');

            case 'slug':
                return is_string($value) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1
                    ? self::pass()
                    : $this->reject($field, 'may contain only lowercase letters, numbers and hyphens');

            case 'hostname':
                return is_string($value) && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]{0,251}[a-zA-Z0-9])?$/', $value) === 1
                    ? self::pass()
                    : $this->reject($field, 'is not a valid hostname');

            case 'in':
                $allowed = explode(',', (string) $arg);

                return in_array((string) $value, $allowed, true)
                    ? self::pass()
                    : $this->reject($field, 'must be one of: ' . implode(', ', $allowed));

            case 'min':
                $actual = is_numeric($value) ? (float) $value : mb_strlen((string) $value);

                return $actual >= (float) $arg
                    ? self::pass()
                    : $this->reject($field, 'must be at least ' . $arg . (is_numeric($value) ? '' : ' characters'));

            case 'max':
                $actual = is_numeric($value) ? (float) $value : mb_strlen((string) $value);

                return $actual <= (float) $arg
                    ? self::pass()
                    : $this->reject($field, 'may not exceed ' . $arg . (is_numeric($value) ? '' : ' characters'));

            case 'between':
                [$lo, $hi] = array_pad(explode(',', (string) $arg), 2, '0');
                $actual = is_numeric($value) ? (float) $value : mb_strlen((string) $value);

                return $actual >= (float) $lo && $actual <= (float) $hi
                    ? self::pass()
                    : $this->reject($field, "must be between {$lo} and {$hi}");

            case 'regex':
                return is_string($value) && @preg_match((string) $arg, $value) === 1
                    ? self::pass()
                    : $this->reject($field, 'has an invalid format');

            case 'ip':
                return filter_var($value, FILTER_VALIDATE_IP) !== false
                    ? self::pass()
                    : $this->reject($field, 'must be a valid IP address');

            case 'ipv4':
                return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                    ? self::pass()
                    : $this->reject($field, 'must be a valid IPv4 address');

            case 'cidr':
                return self::isValidCidr((string) $value)
                    ? self::pass()
                    : $this->reject($field, 'must be a valid CIDR block, e.g. 10.50.0.0/16');

            case 'port':
                $port = (int) $value;

                return $port >= 1 && $port <= 65535
                    ? self::cast($port)
                    : $this->reject($field, 'must be a port between 1 and 65535');

            case 'hex':
                $length = $arg !== null ? (int) $arg : 0;
                if (!is_string($value) || preg_match('/^[0-9a-fA-F]+$/', $value) !== 1) {
                    return $this->reject($field, 'must be hexadecimal');
                }

                return $length === 0 || strlen($value) === $length
                    ? self::pass()
                    : $this->reject($field, "must be {$length} hex characters");

            case 'uuid':
                return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1
                    ? self::pass()
                    : $this->reject($field, 'must be a valid UUID');

            case 'mac':
                return is_string($value) && preg_match('/^([0-9a-fA-F]{2}[:\-]){5}[0-9a-fA-F]{2}$/', $value) === 1
                    ? self::pass()
                    : $this->reject($field, 'must be a valid MAC address');

            case 'date':
                return strtotime((string) $value) !== false
                    ? self::pass()
                    : $this->reject($field, 'must be a valid date');

            case 'timezone':
                return in_array((string) $value, \DateTimeZone::listIdentifiers(), true)
                    ? self::pass()
                    : $this->reject($field, 'must be a valid timezone');

            case 'confirmed':
                return ($this->data[$field . '_confirmation'] ?? null) === $value
                    ? self::pass()
                    : $this->reject($field, 'confirmation does not match');

            case 'same':
                return ($this->data[(string) $arg] ?? null) === $value
                    ? self::pass()
                    : $this->reject($field, 'must match ' . $this->label((string) $arg));

            case 'different':
                return ($this->data[(string) $arg] ?? null) !== $value
                    ? self::pass()
                    : $this->reject($field, 'must be different from ' . $this->label((string) $arg));

            case 'password':
                return $this->checkPassword((string) $value, $field);

            default:
                // An unknown rule name is a programming error, not user input;
                // failing open here would silently skip a real constraint.
                throw new AppException('Unknown validation rule: ' . $name);
        }
    }

    /** @return array{ok:bool,value:mixed,cast:bool} */
    private static function pass(): array
    {
        return ['ok' => true, 'value' => null, 'cast' => false];
    }

    /** @return array{ok:bool,value:mixed,cast:bool} */
    private static function cast(mixed $value): array
    {
        return ['ok' => true, 'value' => $value, 'cast' => true];
    }

    /** @return array{ok:bool,value:mixed,cast:bool} */
    private function reject(string $field, string $message): array
    {
        $this->fail($field, $message);

        return ['ok' => false, 'value' => null, 'cast' => false];
    }

    /** @return array{ok:bool,value:mixed,cast:bool} */
    private function checkPassword(string $value, string $field): array
    {
        $minLength = (int) Config::get('security.password_min_length', 10);
        if (mb_strlen($value) < $minLength) {
            return $this->reject($field, "must be at least {$minLength} characters");
        }
        $classes = 0;
        $classes += preg_match('/[a-z]/', $value);
        $classes += preg_match('/[A-Z]/', $value);
        $classes += preg_match('/[0-9]/', $value);
        $classes += preg_match('/[^a-zA-Z0-9]/', $value);
        if ($classes < 3) {
            return $this->reject($field, 'must mix upper case, lower case, numbers and symbols');
        }
        // Cheap dictionary guard; the UI additionally runs a strength meter.
        $common = ['password', '12345678', 'qwerty', 'admin123', 'letmein', 'welcome', 'iloveyou', 'changeme'];
        $lower = strtolower($value);
        foreach ($common as $bad) {
            if (str_contains($lower, $bad)) {
                return $this->reject($field, 'is too easy to guess');
            }
        }

        return self::pass();
    }

    /** IPv4 CIDR only — the overlay allocates v4 address space. */
    public static function isValidCidr(string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }
        [$ip, $bits] = explode('/', $cidr, 2);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        if (!ctype_digit($bits)) {
            return false;
        }
        $prefix = (int) $bits;

        return $prefix >= 8 && $prefix <= 30;
    }

    private function fail(string $field, string $message): void
    {
        // First error per field wins: a form shows one clear reason, not five.
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message === 'required'
                ? $this->label($field) . ' is required.'
                : $this->label($field) . ' ' . $message . '.';
        }
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }
}
