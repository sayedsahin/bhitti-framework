<?php

declare(strict_types=1);

namespace Bhitti\Validation;

final class Validator
{
    private array $data;
    private array $errors = [];
    private array $nullable = [];
    private array $validatedFields = [];
    private array $typeHints = [];
    private bool $stopOnFirstFailure = false;
    private const BOOLEAN_VALUES = [true, false, 0, 1, '0', '1', 'true', 'false'];

    public static function make(array $data): self
    {
        return new self($data);
    }

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /* ===============================
       CONFIG
    =============================== */

    public function bail(): self
    {
        $this->stopOnFirstFailure = true;
        return $this;
    }

    /* ===============================
       INTERNAL HELPERS
    =============================== */

    private function fields(string|array $fields): array
    {
        $fields = is_array($fields)? $fields : [$fields];

        foreach ($fields as $field) {
            $this->validatedFields[$field] = true;
        }

        return $fields;
    }

    private function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    private function value(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    private function isNullable(string $field): bool
    {
        return isset($this->nullable[$field]);
    }

    private function error(string $field, string $message): void
    {
        $this->errors[$field][] = $message;

        if ($this->stopOnFirstFailure) {
            throw new ValidationException($this->errors);
        }
    }

    /**
     * Determine the comparable "size" of a value for min/max checks.
     *
     * - array           -> element count
     * - numeric field   -> the numeric value itself
     * - everything else -> string length (multibyte-safe)
     *
     * A field is treated as numeric when it was declared with int(), or when
     * its value is an int/float or a numeric string.
     */
    private function sizeOf(string $field, mixed $value): int|float
    {
        if (is_array($value)) {
            return count($value);
        }

        if (($this->typeHints[$field] ?? null) === 'numeric') {
            return (float) $value;
        }

        return mb_strlen((string) $value);
    }

    /**
     * Human-readable unit for min/max messages, matching sizeOf()'s measure.
     */
    private function unitOf(string $field, mixed $value): string
    {
        if (is_array($value)) {
            return 'items';
        }

        if (is_int($value) || is_float($value)) {
            return '';
        }

        $hint = $this->typeHints[$field] ?? null;

        if ($hint !== 'string' && is_string($value) && is_numeric($value)) {
            return '';
        }

        return 'characters';
    }

    /* ===============================
       STATUS
    =============================== */

    public function fails(): bool
    {
        return ! empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validated(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        $validated = [];

        foreach (array_keys($this->validatedFields) as $field) {
            if ($this->has($field)) {
                $validated[$field] = $this->data[$field];
            }
        }

        return $validated;
    }

    /* ===============================
       RULES
    =============================== */

    public function nullable(string|array $fields): self
    {
        foreach ($this->fields($fields) as $field) {
            $this->nullable[$field] = true;
        }
        return $this;
    }

    public function required(string|array $fields): self
    {
        foreach ($this->fields($fields) as $field) {
            $value = $this->value($field);
            if (
                !$this->has($field)
                || $value === null
                || $value === ''
                || (is_string($value) && trim($value) === '')
                || (is_array($value) && $value === [])
            ) {
                $this->error($field, 'This field is required.');
            }
        }
        return $this;
    }

    public function string(string|array $fields): self
    {
        foreach ($this->fields($fields) as $field) {
            $v = $this->value($field);

            $this->typeHints[$field] = 'string';

            if ($v === null && $this->isNullable($field)) continue;

            if (! is_string($v)) {
                $this->error($field, 'Must be a string.');
            }
        }
        return $this;
    }

    public function int(string|array $fields): self
    {
        foreach ($this->fields($fields) as $field) {
            $v = $this->value($field);

            $this->typeHints[$field] = 'numeric';

            if ($v === null && $this->isNullable($field)) continue;

            if (filter_var($v, FILTER_VALIDATE_INT) === false) {
                $this->error($field, 'Must be an integer.');
            }
        }
        return $this;
    }

    public function bool(string|array $fields): self
    {
        foreach ($this->fields($fields) as $field) {
            $v = $this->value($field);

            if ($v === null && $this->isNullable($field)) continue;

            if (!in_array($v, self::BOOLEAN_VALUES, true)) {
                $this->error($field, 'Must be boolean.');
            }
        }
        return $this;
    }

    public function email(string|array $fields): self
    {
        foreach ($this->fields($fields) as $field) {
            $v = $this->value($field);

            if ($v === null && $this->isNullable($field)) continue;

            if (! is_string($v) || ! filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $this->error($field, 'Invalid email.');
            }
        }
        return $this;
    }

    public function min(string|array $fields, int|float $min): self
    {
        foreach ($this->fields($fields) as $field) {
            $v = $this->value($field);

            if ($v === null && $this->isNullable($field)) continue;

            if ($this->sizeOf($field, $v) < $min) {
                $this->error($field, trim("Minimum {$min} " . $this->unitOf($field, $v)) . '.');
            }
        }
        return $this;
    }

    public function max(string|array $fields, int|float $max): self
    {
        foreach ($this->fields($fields) as $field) {
            $v = $this->value($field);

            if ($v === null && $this->isNullable($field)) continue;

            if ($this->sizeOf($field, $v) > $max) {
                $this->error($field, trim("Maximum {$max} " . $this->unitOf($field, $v)) . '.');
            }
        }
        return $this;
    }

    public function between(string|array $fields, int|float $min, int|float $max): self
    {
        return $this->min($fields, $min)->max($fields, $max);
    }

    public function in(string|array $fields, array $allowed): self
    {
        foreach ($this->fields($fields) as $field) {
            $v = $this->value($field);

            if ($v === null && $this->isNullable($field)) continue;

            if (! in_array($v, $allowed, true)) {
                $this->error($field, 'Invalid value.');
            }
        }
        return $this;
    }

    public function confirmed(string $field): self
	{
		$this->validatedFields[$field] = true;

		$confirm = $field . '_confirmation';

		if ($this->value($field) !== $this->value($confirm)) {
			$this->error($field, 'Confirmation mismatch.');
		}

		return $this;
	}

    public function sometimes(string $field, callable $callback): self
    {
        if ($callback($this->data)) {
            return $this->required($field);
        }
        return $this;
    }

    public function custom(callable $callback): self
    {
        $callback($this);
        return $this;
    }
}
