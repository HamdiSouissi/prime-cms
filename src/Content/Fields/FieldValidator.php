<?php

declare(strict_types=1);

namespace Prime\Content\Fields;

use DateTimeImmutable;
use Prime\Database\Connection;
use Prime\Security\HtmlSanitizer;
use Prime\Validation\Validator;

/**
 * Validates and normalizes custom field payloads for an entry using Prime's core Validator.
 */
final class FieldValidator
{
    public function __construct(
        private readonly FieldRegistry $registry,
        private readonly HtmlSanitizer $sanitizer,
        private readonly ?Connection $db = null,
    ) {
    }

    /**
     * Validate and coerce submitted custom field inputs for `$contentType`.
     *
     * @param array<string, mixed> $input
     * @return array{valid: bool, errors: array<string, list<string>>, values: array<string, mixed>}
     */
    public function validate(string $contentType, array $input): array
    {
        $fields = $this->registry->fieldsForContentType($contentType);
        if ($fields === []) {
            return ['valid' => true, 'errors' => [], 'values' => []];
        }

        $validatorData = [];
        $validatorRules = [];
        $validatorLabels = [];
        $errors = [];
        $values = [];

        foreach ($fields as $name => $field) {
            $raw = $input[$name] ?? null;

            // Boolean types (`checkbox`, `toggle`): missing key from HTML form means `false`
            if ($field->type === 'checkbox' || $field->type === 'toggle') {
                $boolVal = in_array($raw, [true, 1, '1', 'true', 'on', 'yes'], true);
                if ($field->required && !$boolVal) {
                    $errors['fields.' . $name][] = sprintf('Le champ « %s » doit être activé.', $field->label);
                }
                $values[$name] = $boolVal;
                continue;
            }

            $strVal = is_scalar($raw) ? trim((string) $raw) : '';
            if ($strVal === '') {
                if ($field->required) {
                    $errors['fields.' . $name][] = sprintf('Le champ « %s » est obligatoire.', $field->label);
                }
                $values[$name] = null;
                continue;
            }

            // Build Validator rules for compatible types
            $rules = $field->rules;
            if ($field->type === 'email') {
                $rules[] = 'email';
            } elseif ($field->type === 'select' && $field->options !== []) {
                $rules[] = 'in:' . implode(',', array_keys($field->options));
            }

            if ($rules !== []) {
                $validatorData[$name] = $strVal;
                $validatorRules[$name] = $rules;
                $validatorLabels[$name] = $field->label;
            }

            // Type-specific strict validation & coercion
            switch ($field->type) {
                case 'text':
                case 'textarea':
                case 'email':
                case 'select':
                    $values[$name] = $strVal;
                    break;

                case 'number':
                    if (!is_numeric($strVal)) {
                        $errors['fields.' . $name][] = sprintf('Le champ « %s » doit être un nombre.', $field->label);
                        break;
                    }
                    $num = str_contains($strVal, '.') ? (float) $strVal : (int) $strVal;
                    if ($field->min !== null && $num < $field->min) {
                        $errors['fields.' . $name][] = sprintf(
                            'Le champ « %s » doit être supérieur ou égal à %s.',
                            $field->label,
                            (string) $field->min,
                        );
                    }
                    if ($field->max !== null && $num > $field->max) {
                        $errors['fields.' . $name][] = sprintf(
                            'Le champ « %s » doit être inférieur ou égal à %s.',
                            $field->label,
                            (string) $field->max,
                        );
                    }
                    $values[$name] = $num;
                    break;

                case 'url':
                    $safeUrl = $this->sanitizer->sanitizeResourceUrl($strVal);
                    if ($safeUrl === null) {
                        $errors['fields.' . $name][] = sprintf(
                            'Le champ « %s » doit contenir une URL valide (http, https ou relative).',
                            $field->label,
                        );
                    } else {
                        $values[$name] = $safeUrl;
                    }
                    break;

                case 'date':
                    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $strVal);
                    if ($dt === false || $dt->format('Y-m-d') !== $strVal) {
                        $errors['fields.' . $name][] = sprintf(
                            'Le champ « %s » doit être une date valide (AAAA-MM-JJ).',
                            $field->label,
                        );
                    } else {
                        $values[$name] = $strVal;
                    }
                    break;

                case 'datetime':
                    $normalizedDt = str_replace('T', ' ', $strVal);
                    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $normalizedDt)
                        ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $normalizedDt);
                    if ($parsed === false) {
                        $errors['fields.' . $name][] = sprintf(
                            'Le champ « %s » doit être une date et heure valide.',
                            $field->label,
                        );
                    } else {
                        $values[$name] = $parsed->format('Y-m-d H:i:s');
                    }
                    break;

                case 'color':
                    if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $strVal) !== 1) {
                        $errors['fields.' . $name][] = sprintf(
                            'Le champ « %s » doit être une couleur hexadécimale (#RRGGBB).',
                            $field->label,
                        );
                    } else {
                        $values[$name] = strtolower($strVal);
                    }
                    break;

                case 'entry':
                    if (!ctype_digit($strVal) || (int) $strVal <= 0) {
                        $errors['fields.' . $name][] = sprintf(
                            'Le champ « %s » doit référencer un contenu valide.',
                            $field->label,
                        );
                        break;
                    }
                    $entryId = (int) $strVal;
                    if ($this->db !== null) {
                        $qb = $this->db->table('entries')->where('id', '=', $entryId);
                        if ($field->entryTypes !== []) {
                            $qb->whereIn('type', $field->entryTypes);
                        }
                        if (!$qb->exists()) {
                            $errors['fields.' . $name][] = sprintf(
                                'Le contenu sélectionné pour « %s » est introuvable.',
                                $field->label,
                            );
                            break;
                        }
                    }
                    $values[$name] = $entryId;
                    break;
            }
        }

        if ($validatorRules !== []) {
            $validator = new Validator($validatorData, $validatorRules, [], $validatorLabels, $this->db);
            if ($validator->fails()) {
                foreach ($validator->allErrors() as $fieldName => $messages) {
                    foreach ($messages as $msg) {
                        $errors['fields.' . $fieldName][] = $msg;
                    }
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'values' => $values,
        ];
    }
}
