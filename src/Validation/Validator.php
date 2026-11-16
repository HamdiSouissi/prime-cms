<?php

declare(strict_types=1);

namespace Prime\Validation;

use InvalidArgumentException;
use Prime\Database\Connection;
use Prime\I18n\Translator;
use Prime\Security\PasswordPolicy;

/**
 * Lightweight, zero-dependency form and payload validator with translatable messages.
 *
 * Default messages resolve `validation.*` translation keys when a translator is bound (see
 * {@see self::setTranslator()}) and fall back to French literals otherwise.
 *
 * Supported rules:
 * `nullable`, `required`, `string`, `email`, `min:N`, `max:N`, `between:min,max`,
 * `confirmed`, `in:a,b,c`, `regex:/pattern/flags`, `boolean`, `integer`,
 * `unique:table,col[,ignoreId]`, `exists:table,col`, `password`.
 */
final class Validator
{
    /**
     * @var array<string, list<string>>
     */
    private array $errors = [];

    private bool $evaluated = false;

    /**
     * Translations used to resolve default messages (`validation.*` keys) when set by the
     * `I18nServiceProvider`; `null` keeps the built-in French literals.
     */
    private static ?Translator $translator = null;

    private readonly PasswordPolicy $passwordPolicy;

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $messages Custom messages keyed by `field.rule` or `rule`
     * @param array<string, string> $labels Human-readable field labels in French
     * @param array{email?: string, display_name?: string, site_name?: string} $passwordContext
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages = [],
        private readonly array $labels = [],
        private readonly ?Connection $connection = null,
        ?PasswordPolicy $passwordPolicy = null,
        private readonly array $passwordContext = [],
    ) {
        $this->passwordPolicy = $passwordPolicy ?? new PasswordPolicy();
    }

    /**
     * Static factory helper.
     *
     * @param array<string, mixed> $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $messages
     * @param array<string, string> $labels
     * @param array{email?: string, display_name?: string, site_name?: string} $passwordContext
     */
    public static function make(
        array $data,
        array $rules,
        array $messages = [],
        array $labels = [],
        ?Connection $connection = null,
        ?PasswordPolicy $passwordPolicy = null,
        array $passwordContext = [],
    ): self {
        return new self(
            data: $data,
            rules: $rules,
            messages: $messages,
            labels: $labels,
            connection: $connection,
            passwordPolicy: $passwordPolicy,
            passwordContext: $passwordContext,
        );
    }

    /**
     * Inject the translator used for default validation messages (test/CLI friendly).
     */
    public static function setTranslator(?Translator $translator): void
    {
        self::$translator = $translator;
    }

    public static function translator(): ?Translator
    {
        return self::$translator;
    }

    public function passes(): bool
    {
        $this->evaluate();

        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * Return the first error message per failing field.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        $this->evaluate();

        $first = [];
        foreach ($this->errors as $field => $messages) {
            if ($messages !== []) {
                $first[$field] = $messages[0];
            }
        }

        return $first;
    }

    /**
     * Return all error messages grouped by field.
     *
     * @return array<string, list<string>>
     */
    public function allErrors(): array
    {
        $this->evaluate();

        return $this->errors;
    }

    public function firstError(string $field): ?string
    {
        $this->evaluate();

        return $this->errors[$field][0] ?? null;
    }

    /**
     * Return only keys present in the rule set from the input data.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        $this->evaluate();

        $out = [];
        foreach (array_keys($this->rules) as $field) {
            if (array_key_exists($field, $this->data)) {
                $out[$field] = $this->data[$field];
            }
        }

        return $out;
    }

    private function evaluate(): void
    {
        if ($this->evaluated) {
            return;
        }
        $this->evaluated = true;

        foreach ($this->rules as $field => $fieldRules) {
            $parsedRules = is_array($fieldRules)
                ? $fieldRules
                : array_values(array_filter(explode('|', $fieldRules), static fn (string $r): bool => $r !== ''));

            $value = $this->data[$field] ?? null;
            $isRequired = in_array('required', $parsedRules, true);
            $isEmpty = $this->isEmptyValue($value);

            if ($isEmpty && !$isRequired) {
                continue;
            }

            foreach ($parsedRules as $ruleSpec) {
                if ($ruleSpec === 'nullable') {
                    continue;
                }

                [$ruleName, $param] = $this->splitRule($ruleSpec);
                $error = $this->applyRule($field, $value, $ruleName, $param);

                if ($error !== null) {
                    $this->errors[$field][] = $error;
                    if ($ruleName === 'required') {
                        break;
                    }
                }
            }
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitRule(string $ruleSpec): array
    {
        $pos = strpos($ruleSpec, ':');
        if ($pos === false) {
            return [$ruleSpec, null];
        }

        return [substr($ruleSpec, 0, $pos), substr($ruleSpec, $pos + 1)];
    }

    private function applyRule(string $field, mixed $value, string $rule, ?string $param): ?string
    {
        return match ($rule) {
            'required' => $this->isEmptyValue($value)
                ? $this->message($field, 'required', 'Le champ « :label » est obligatoire.')
                : null,

            'string' => !is_string($value)
                ? $this->message($field, 'string', 'Le champ « :label » doit être une chaîne de caractères.')
                : null,

            'email' => $this->checkEmail($field, $value),

            'integer' => $this->checkInteger($field, $value),

            'boolean' => $this->checkBoolean($field, $value),

            'min' => $this->checkMin($field, $value, $param),

            'max' => $this->checkMax($field, $value, $param),

            'between' => $this->checkBetween($field, $value, $param),

            'confirmed' => $this->checkConfirmed($field, $value),

            'in' => $this->checkIn($field, $value, $param),

            'regex' => $this->checkRegex($field, $value, $param),

            'unique' => $this->checkUnique($field, $value, $param),

            'exists' => $this->checkExists($field, $value, $param),

            'password' => $this->checkPassword($field, $value),

            default => throw new InvalidArgumentException(sprintf('Règle de validation inconnue : [%s].', $rule)),
        };
    }

    private function checkEmail(string $field, mixed $value): ?string
    {
        if (!is_string($value)) {
            return $this->message($field, 'email', 'Saisissez une adresse e-mail valide.');
        }

        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > 191 || filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
            return $this->message($field, 'email', 'Saisissez une adresse e-mail valide.');
        }

        return null;
    }

    private function checkInteger(string $field, mixed $value): ?string
    {
        if (is_int($value)) {
            return null;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return null;
        }

        return $this->message($field, 'integer', 'Le champ « :label » doit être un nombre entier.');
    }

    private function checkBoolean(string $field, mixed $value): ?string
    {
        $valid = [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'];
        if (in_array($value, $valid, true)) {
            return null;
        }

        return $this->message($field, 'boolean', 'Le champ « :label » doit être vrai ou faux.');
    }

    private function checkMin(string $field, mixed $value, ?string $param): ?string
    {
        if ($param === null || !is_numeric($param)) {
            throw new InvalidArgumentException('La règle min requiert un paramètre numérique.');
        }

        $min = (int) $param;

        if (is_int($value) || (is_string($value) && $this->hasNumericRule($field) && is_numeric(trim($value)))) {
            if ((int) $value < $min) {
                return $this->message($field, 'min', 'Le champ « :label » doit être supérieur ou égal à :min.', [':min' => (string) $min], 'numeric');
            }

            return null;
        }

        if (is_array($value)) {
            if (count($value) < $min) {
                return $this->message($field, 'min', 'Sélectionnez au moins :min élément(s) pour « :label ».', [':min' => (string) $min], 'array');
            }

            return null;
        }

        $str = (string) $value;
        if (mb_strlen($str, 'UTF-8') < $min) {
            return $this->message($field, 'min', 'Le champ « :label » doit contenir au moins :min caractères.', [':min' => (string) $min], 'string');
        }

        return null;
    }

    private function checkMax(string $field, mixed $value, ?string $param): ?string
    {
        if ($param === null || !is_numeric($param)) {
            throw new InvalidArgumentException('La règle max requiert un paramètre numérique.');
        }

        $max = (int) $param;

        if (is_int($value) || (is_string($value) && $this->hasNumericRule($field) && is_numeric(trim($value)))) {
            if ((int) $value > $max) {
                return $this->message($field, 'max', 'Le champ « :label » ne doit pas dépasser :max.', [':max' => (string) $max], 'numeric');
            }

            return null;
        }

        if (is_array($value)) {
            if (count($value) > $max) {
                return $this->message($field, 'max', 'Le champ « :label » ne doit pas dépasser :max élément(s).', [':max' => (string) $max], 'array');
            }

            return null;
        }

        $str = (string) $value;
        if (mb_strlen($str, 'UTF-8') > $max) {
            return $this->message($field, 'max', 'Le champ « :label » ne doit pas dépasser :max caractères.', [':max' => (string) $max], 'string');
        }

        return null;
    }

    private function checkBetween(string $field, mixed $value, ?string $param): ?string
    {
        $parts = $param !== null ? explode(',', $param) : [];
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            throw new InvalidArgumentException('La règle between requiert deux bornes numériques (ex. between:2,100).');
        }

        $min = (int) $parts[0];
        $max = (int) $parts[1];

        if (is_int($value) || (is_string($value) && $this->hasNumericRule($field) && is_numeric(trim($value)))) {
            $num = (int) $value;
            if ($num < $min || $num > $max) {
                return $this->message($field, 'between', 'Le champ « :label » doit être compris entre :min et :max.', [
                    ':min' => (string) $min,
                    ':max' => (string) $max,
                ], 'numeric');
            }

            return null;
        }

        $len = mb_strlen((string) $value, 'UTF-8');
        if ($len < $min || $len > $max) {
            return $this->message($field, 'between', 'Le champ « :label » doit contenir entre :min et :max caractères.', [
                ':min' => (string) $min,
                ':max' => (string) $max,
            ], 'string');
        }

        return null;
    }

    private function checkConfirmed(string $field, mixed $value): ?string
    {
        $confirmation = $this->data[$field . '_confirmation'] ?? null;
        if ($value !== $confirmation) {
            return $this->message($field, 'confirmed', 'La confirmation du champ « :label » ne correspond pas.');
        }

        return null;
    }

    private function checkIn(string $field, mixed $value, ?string $param): ?string
    {
        $allowed = $param !== null ? explode(',', $param) : [];
        if (!in_array((string) $value, $allowed, true)) {
            return $this->message($field, 'in', 'La valeur sélectionnée pour « :label » n\'est pas valide.');
        }

        return null;
    }

    private function checkRegex(string $field, mixed $value, ?string $param): ?string
    {
        if ($param === null || $param === '') {
            throw new InvalidArgumentException('La règle regex requiert un motif régulier.');
        }

        if (!is_string($value) || @preg_match($param, $value) !== 1) {
            return $this->message($field, 'regex', 'Le format du champ « :label » est invalide.');
        }

        return null;
    }

    private function checkUnique(string $field, mixed $value, ?string $param): ?string
    {
        if ($this->connection === null) {
            throw new InvalidArgumentException('La règle unique requiert une instance de Connection.');
        }

        $parts = $param !== null ? explode(',', $param) : [];
        if (count($parts) < 2) {
            throw new InvalidArgumentException('La règle unique attend unique:table,colonne[,ignoreId].');
        }

        $table = trim($parts[0]);
        $column = trim($parts[1]);
        $ignoreId = isset($parts[2]) && trim($parts[2]) !== '' ? trim($parts[2]) : null;
        $idColumn = isset($parts[3]) && trim($parts[3]) !== '' ? trim($parts[3]) : 'id';

        $query = $this->connection->table($table)
            ->where($column, '=', is_string($value) ? trim($value) : $value);

        if ($ignoreId !== null) {
            $query->where($idColumn, '!=', ctype_digit($ignoreId) ? (int) $ignoreId : $ignoreId);
        }

        if ($query->exists()) {
            return $this->message($field, 'unique', 'Cette valeur pour « :label » est déjà utilisée.');
        }

        return null;
    }

    private function checkExists(string $field, mixed $value, ?string $param): ?string
    {
        if ($this->connection === null) {
            throw new InvalidArgumentException('La règle exists requiert une instance de Connection.');
        }

        $parts = $param !== null ? explode(',', $param) : [];
        if (count($parts) < 2) {
            throw new InvalidArgumentException('La règle exists attend exists:table,colonne.');
        }

        $table = trim($parts[0]);
        $column = trim($parts[1]);

        $found = $this->connection->table($table)
            ->where($column, '=', $value)
            ->exists();

        if (!$found) {
            return $this->message($field, 'exists', 'La valeur sélectionnée pour « :label » est introuvable.');
        }

        return null;
    }

    private function checkPassword(string $field, mixed $value): ?string
    {
        if (!is_string($value)) {
            return $this->message($field, 'password', 'Le mot de passe est invalide.');
        }

        $context = $this->passwordContext;
        if (!isset($context['email']) && isset($this->data['email']) && is_string($this->data['email'])) {
            $context['email'] = $this->data['email'];
        }
        if (!isset($context['display_name']) && isset($this->data['display_name']) && is_string($this->data['display_name'])) {
            $context['display_name'] = $this->data['display_name'];
        }

        $error = $this->passwordPolicy->validate($value, $context);
        if ($error !== null) {
            return $this->messages[$field . '.password'] ?? $error;
        }

        return null;
    }

    private function hasNumericRule(string $field): bool
    {
        $rules = $this->rules[$field] ?? [];
        $list = is_array($rules) ? $rules : explode('|', $rules);

        return in_array('integer', $list, true);
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        return is_array($value) && $value === [];
    }

    /**
     * @param array<string, string> $replacements
     */
    private function message(string $field, string $rule, string $default, array $replacements = [], ?string $variant = null): string
    {
        $template = $this->messages[$field . '.' . $rule]
            ?? $this->messages[$rule]
            ?? $this->translation($rule, $variant)
            ?? $default;

        $replacements[':label'] = $this->labels[$field] ?? $field;

        return strtr($template, $replacements);
    }

    /**
     * Resolve a translated default message (`validation.{rule}_{variant}` then `validation.{rule}`).
     */
    private function translation(string $rule, ?string $variant): ?string
    {
        $translator = self::$translator;
        if ($translator === null) {
            return null;
        }

        $keys = [];
        if ($variant !== null && $variant !== '') {
            $keys[] = 'validation.' . $rule . '_' . $variant;
        }
        $keys[] = 'validation.' . $rule;

        foreach ($keys as $key) {
            $line = $translator->translate($key);
            if ($line !== $key) {
                return $line;
            }
        }

        return null;
    }
}
