<?php

declare(strict_types=1);

namespace Prime\Settings;

use DateTimeImmutable;
use Prime\Hooks\Hooks;
use Prime\Validation\Validator;

/**
 * Turns raw form input into stored settings: sanitize by declared type, validate with the shared
 * `Validator` rules, skip protected secrets that were left blank, then persist and notify.
 *
 * The same object serves the admin screens, the CLI (`settings:set`) and REST endpoints, so every
 * writer obeys the registry contracts.
 */
final class SettingsFormProcessor
{
    /**
     * Rules that query other tables and therefore make no sense for a `pc_settings` row.
     *
     * @var list<string>
     */
    private const UNSUPPORTED_RULES = ['unique', 'exists', 'password'];

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly Settings $settings,
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * Persist every submitted value belonging to one section.
     *
     * @param array<string, mixed>  $input
     * @param list<string>|null     $only Restrict the write to these setting names
     *
     * @return array{saved: list<string>, errors: array<string, string>, skipped: list<string>, unchanged: list<string>}
     */
    public function process(string $section, array $input, ?array $only = null): array
    {
        $fields = $this->registry->fields($section);
        $allowed = $only === null ? null : array_flip($only);

        $saved = [];
        $errors = [];
        $skipped = [];
        $unchanged = [];
        $pending = [];

        foreach ($fields as $name => $field) {
            if ($allowed !== null && !isset($allowed[$name])) {
                continue;
            }

            if (!$field->isEditable()) {
                continue;
            }

            // A checkbox group or a color picker may legitimately be absent from the payload.
            $submitted = array_key_exists($name, $input) ? $input[$name] : null;
            $present = array_key_exists($name, $input);

            if ($field->isSensitive() && !$present) {
                $skipped[] = $name;
                continue;
            }

            $current = $field->isSensitive()
                ? $this->settings->getSecret($name)
                : $this->settings->get($name, $field->default);

            if ($field->isSensitive() && $present && $this->isBlank($submitted)) {
                $skipped[] = $name;
                continue;
            }

            $value = $this->sanitize($field, $submitted, $current);
            $error = $this->validate($field, $value, $input);

            if ($error !== null) {
                $errors[$name] = $error;
                continue;
            }

            $value = $this->hooks === null
                ? $value
                : $this->hooks->apply('settings.validate', $value, $field, $input);

            $pending[$name] = ['field' => $field, 'value' => $value, 'current' => $current];
        }

        if ($errors !== []) {
            return [
                'saved' => $saved,
                'errors' => $errors,
                'skipped' => $skipped,
                'unchanged' => $unchanged,
            ];
        }

        foreach ($pending as $name => $entry) {
            /** @var SettingsField $field */
            $field = $entry['field'];
            $value = $entry['value'];
            $current = $entry['current'];

            if ($value === $current) {
                $unchanged[] = $name;
                continue;
            }

            $veto = $this->hooks?->apply('settings.before_save', true, $name, $current, $value, $field);
            if ($veto === false) {
                $skipped[] = $name;
                continue;
            }

            if ($field->isSensitive()) {
                $this->settings->setSecret($name, is_string($value) ? $value : null);
            } else {
                $this->settings->set($name, $value, $field->autoload);
            }

            $saved[] = $name;
        }

        if ($saved !== []) {
            $this->hooks?->emit('settings.saved', $section, $saved, $errors);
        }

        return [
            'saved' => $saved,
            'errors' => $errors,
            'skipped' => $skipped,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * Coerce a raw submission into the PHP type implied by the field declaration.
     */
    public function sanitize(SettingsField $field, mixed $raw, mixed $current = null): mixed
    {
        if ($raw === null && in_array($field->type, ['checkbox', 'toggle', 'multiselect'], true)) {
            return $field->type === 'multiselect' ? [] : false;
        }

        return match ($field->type) {
            'checkbox', 'toggle' => (bool) $this->toBool($raw),
            'number' => $this->toNumber($field, $raw),
            'email' => is_string($raw) ? mb_strtolower(trim($raw)) : '',
            'url' => $this->toUrl($raw),
            'text', 'hidden', 'secret', 'color', 'date', 'datetime', 'time', 'media' => $this->toScalar($field, $raw, $current),
            'textarea', 'code' => $this->toMultiline($field, $raw),
            'radio', 'select' => $this->toChoice($field, $raw),
            'multiselect' => $this->toChoices($field, $raw),
            // A plugin may register a type of its own (a `range`, a `textarea-json`): nothing to
            // coerce here, the declared validation rules are what guards the value.
            default => $raw,
        };
    }

    /**
     * Return the first validation message for a field, or `null` when the value is acceptable.
     *
     * @param array<string, mixed> $input
     */
    public function validate(SettingsField $field, mixed $value, array $input = []): ?string
    {
        $rules = $this->rulesFor($field);

        if ($rules === []) {
            return null;
        }

        if ($field->type === 'number' && (is_int($value) || is_float($value))) {
            // Numeric bounds are compared as numbers: `Validator` sizes `min`/`max` by type and
            // would measure a float such as `0.5` as a three-character string.
            $boundError = $this->checkNumericBounds($field, $value, $rules);
            if ($boundError !== null) {
                return $boundError;
            }

            unset($rules['min'], $rules['max'], $rules['between']);

            if ($rules === []) {
                return null;
            }
        }

        if ($field->type === 'url' && is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            // `Prime\Validation\Validator` has no `url` rule, so the shape is checked here with the
            // same filter the REST endpoints use: a mistyped scheme is refused, never stored.
            return t('validation.url', [':label' => $field->label]);
        }

        $topRules = $rules;
        if ($field->type === 'multiselect') {
            // `in` constrains every entry of the list; `min`/`max` keep their array (count) meaning.
            unset($topRules['in']);
        }

        $validator = Validator::make(
            data: [$field->name => $value],
            rules: [$field->name => self::ruleSpecs($topRules)],
            labels: [$field->name => $field->label],
        );

        $errors = $validator->errors();
        if (isset($errors[$field->name])) {
            return $errors[$field->name];
        }

        if ($field->type !== 'multiselect' || !is_array($value)) {
            return null;
        }

        // Item-shape rules constrain every entry; count rules were already checked on the list.
        $itemRules = $rules;
        unset($itemRules['min'], $itemRules['max'], $itemRules['between']);

        if ($itemRules === []) {
            return null;
        }

        foreach ($value as $item) {
            $itemValidator = Validator::make(
                data: [$field->name => $item],
                rules: [$field->name => self::ruleSpecs($itemRules)],
                labels: [$field->name => $field->label],
            );

            if ($itemValidator->fails()) {
                return $itemValidator->errors()[$field->name] ?? null;
            }
        }

        return null;
    }

    /**
     * Translate the declarative rules into the `Validator` format, adding the option constraints
     * implied by `select`, `radio` and `multiselect` fields.
     *
     * @return array<string, mixed>
     */
    public function rulesFor(SettingsField $field): array
    {
        $rules = [];

        foreach ($field->rules as $rule => $value) {
            if (is_int($rule) && is_string($value)) {
                // `['required', 'max:120']` shape.
                [$rule, $value] = $this->splitRule($value);
            }

            if (in_array((string) $rule, self::UNSUPPORTED_RULES, true)) {
                continue;
            }

            $rules[(string) $rule] = is_scalar($value) ? (string) $value : '';
        }

        if (in_array($field->type, ['select', 'radio', 'multiselect'], true) && $field->options !== []) {
            $rules['in'] = implode(',', array_map(
                static fn (int|string $key): string => (string) $key,
                array_keys($field->options),
            ));
        }

        if ($field->type === 'color' && !isset($rules['regex'])) {
            $rules['regex'] = '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i';
        }

        if ($field->type === 'date' && !isset($rules['regex'])) {
            $rules['regex'] = '/^\d{4}-\d{2}-\d{2}$/';
        }

        if ($field->type === 'time' && !isset($rules['regex'])) {
            $rules['regex'] = '/^([01]\d|2[0-3]):[0-5]\d$/';
        }

        if ($field->type === 'datetime' && !isset($rules['regex'])) {
            $rules['regex'] = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/';
        }

        if ($field->type === 'email' && !isset($rules['email'])) {
            $rules['email'] = '';
        }

        if ($field->type === 'media' && !isset($rules['integer'])) {
            $rules['integer'] = '';
        }

        return $rules;
    }

    /**
     * Numeric `min`/`max`/`between` bounds of a `number` field, translated through the validation
     * catalogue so admin screens keep speaking the active language.
     *
     * @param array<string, mixed> $rules
     */
    private function checkNumericBounds(SettingsField $field, int|float $value, array $rules): ?string
    {
        $min = $rules['min'] ?? null;
        $max = $rules['max'] ?? null;

        if (is_numeric($min) && $value < (float) $min) {
            return t('validation.min_numeric', [
                ':label' => $field->label,
                ':min' => self::formatBound($min),
            ]);
        }

        if (is_numeric($max) && $value > (float) $max) {
            return t('validation.max_numeric', [
                ':label' => $field->label,
                ':max' => self::formatBound($max),
            ]);
        }

        $between = $rules['between'] ?? null;
        if (is_string($between)) {
            $bounds = array_map('trim', explode(',', $between));
            if (count($bounds) === 2 && is_numeric($bounds[0]) && is_numeric($bounds[1])
                && ($value < (float) $bounds[0] || $value > (float) $bounds[1])) {
                return t('validation.between_numeric', [
                    ':label' => $field->label,
                    ':min' => self::formatBound($bounds[0]),
                    ':max' => self::formatBound($bounds[1]),
                ]);
            }
        }

        return null;
    }

    private static function formatBound(mixed $bound): string
    {
        $float = (float) $bound;

        return (string) $float === (string) (int) $float ? (string) (int) $float : (string) $float;
    }

    /**
     * `Prime\Validation\Validator` consumes a list of rule specs, while the registry stores a map.
     *
     * @param array<string, mixed> $rules
     *
     * @return list<string>
     */
    private static function ruleSpecs(array $rules): array
    {
        $specs = [];

        foreach ($rules as $rule => $value) {
            $specs[] = $value === '' || $value === null ? (string) $rule : $rule . ':' . $value;
        }

        return $specs;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitRule(string $rule): array
    {
        $colon = strpos($rule, ':');
        if ($colon === false) {
            return [$rule, ''];
        }

        return [substr($rule, 0, $colon), substr($rule, $colon + 1)];
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function toBool(mixed $raw): bool
    {
        if (is_string($raw)) {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? ($raw !== '' && $raw !== '0' && $raw !== 'off' && $raw !== 'no');
        }

        return (bool) $raw;
    }

    /**
     * Coerce a numeric input and keep it whole when the field declares no `step`. Out-of-range values
     * are *not* clamped here: the declared `min`/`max` rules must surface an error so the operator
     * sees why their value was refused (`Settings::getInt()` clamps on read, which is enough).
     */
    private function toNumber(SettingsField $field, mixed $raw): int|float|null
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        if (is_array($raw)) {
            $raw = end($raw);
        }

        if (!is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;
        $isInteger = !isset($field->rules['step']) && (string) $value === (string) (int) $value;

        return $isInteger ? (int) $value : $value;
    }

    private function toUrl(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }

        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value) !== 1 && !str_starts_with($value, '//')) {
            $value = 'https://' . ltrim($value, '/');
        } elseif (str_starts_with($value, '//')) {
            $value = 'https:' . $value;
        }

        return $value;
    }

    private function toScalar(SettingsField $field, mixed $raw, mixed $current): mixed
    {
        if (is_array($raw)) {
            $raw = (string) end($raw);
        }

        $value = is_scalar($raw) ? trim((string) $raw) : '';

        if ($field->type === 'media') {
            $id = (int) $value;

            return $id > 0 ? $id : 0;
        }

        if ($field->type === 'color') {
            return $value === '' ? $field->default : strtolower($value);
        }

        if ($field->type === 'date' || $field->type === 'datetime' || $field->type === 'time') {
            return $this->toTemporal($field->type, $value, $current);
        }

        if ($field->type === 'text' || $field->type === 'hidden' || $field->type === 'secret') {
            if ($field->type !== 'secret') {
                // Never store markup in a single-line setting — and shorten *after* stripping, so a
                // declared `max` applies to what actually reaches the database.
                $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
            }

            $maxlength = $field->rules['maxlength'] ?? $field->rules['max'] ?? null;
            if (is_numeric($maxlength) && mb_strlen($value) > (int) $maxlength) {
                $value = mb_substr($value, 0, (int) $maxlength);
            }

            return $value;
        }

        return $value;
    }

    private function toTemporal(string $type, string $value, mixed $current): string
    {
        if ($value === '') {
            return '';
        }

        $format = match ($type) {
            'time' => 'H:i',
            'datetime' => 'Y-m-d H:i',
            default => 'Y-m-d',
        };

        $candidates = [$value, str_replace('T', ' ', $value)];

        foreach ($candidates as $candidate) {
            $date = DateTimeImmutable::createFromFormat($format, $candidate);
            if ($date === false) {
                continue;
            }

            // `getLastErrors()` is documented to answer on PHP >= 8.2 but the signature still allows
            // `false`, so the counters are only read once the array is in hand: a rollover (`25:00`,
            // `31/02/2026`) lands here instead of being silently rolled over by `createFromFormat()`.
            $issues = DateTimeImmutable::getLastErrors();
            $hasIssues = is_array($issues) && ($issues['warning_count'] > 0 || $issues['error_count'] > 0);

            // A lossless round-trip keeps `25:00` and `31/02/2026` from being silently rolled over:
            // the raw input reaches the declared `regex` rule and the screen shows why it was refused.
            if (!$hasIssues && $date->format($format) === $candidate) {
                return $date->format($format);
            }
        }

        return $value;
    }

    private function toMultiline(SettingsField $field, mixed $raw): string
    {
        $value = is_string($raw) ? $raw : (is_scalar($raw) ? (string) $raw : '');
        $value = str_replace("\r\n", "\n", $value);
        $value = preg_replace('/[ \t]+$/m', '', $value) ?? $value;
        $value = trim($value);

        if ($field->type === 'textarea') {
            $value = strip_tags($value);
        }

        $maxlength = $field->rules['maxlength'] ?? null;
        if (is_numeric($maxlength) && mb_strlen($value) > (int) $maxlength) {
            $value = mb_substr($value, 0, (int) $maxlength);
        }

        return $value;
    }

    private function toChoice(SettingsField $field, mixed $raw): string
    {
        $value = is_scalar($raw) ? trim((string) $raw) : '';

        if ($value !== '' && array_key_exists($value, $field->options)) {
            return $value;
        }

        return is_string($field->default) ? $field->default : '';
    }

    /**
     * @return list<string>
     */
    private function toChoices(SettingsField $field, mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $values = is_array($raw) ? $raw : [$raw];
        $clean = [];

        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $cast = trim((string) $value);
            if ($cast === '' || !array_key_exists($cast, $field->options)) {
                continue;
            }

            $clean[$cast] = $cast;
        }

        return array_values($clean);
    }
}
