<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Settings\Settings;
use Prime\Settings\SettingsFormProcessor;
use Prime\Settings\SettingsRegistry;

/**
 * Site settings over the REST API.
 *
 * Anonymous callers read the fields declared `public`; `settings.manage` reads every
 * non-secret field. Secrets are write-only: they are never returned, only reported as
 * configured or not. Writes go through SettingsFormProcessor, with the very rules of the
 * settings screens.
 */
final class SettingsController extends ApiController
{
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly Settings $settings,
        private readonly SettingsFormProcessor $processor,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->currentUser($request);
        $manager = $user !== null && $user->can('settings.manage');

        $data = [];
        $secrets = [];
        foreach ($this->registry->fields() as $name => $field) {
            if (!$manager && !$field->public) {
                continue;
            }

            if ($field->isSensitive()) {
                if ($manager) {
                    $secrets[$name] = $this->settings->getSecret($name) !== null;
                }
                continue;
            }

            $data[$name] = $this->settings->get($name, $field->default);
        }

        $payload = ['data' => $data];
        if ($manager) {
            $payload['meta'] = ['secrets_configured' => $secrets];
        }

        return $this->json($payload, 200, ['Cache-Control' => $user !== null ? 'private, no-store' : 'public, max-age=60']);
    }

    public function update(Request $request): Response
    {
        $input = $this->body($request);
        $bySection = [];
        $unknown = [];

        foreach ($input as $name => $value) {
            $field = is_string($name) ? $this->registry->field($name) : null;
            if ($field === null || !$field->isEditable()) {
                $unknown[(string) $name] = t('api.validation.setting_unknown');
                continue;
            }
            $bySection[$field->section][] = $name;
        }

        if ($unknown !== []) {
            return $this->validationFailed($request, $unknown);
        }

        // Validate every section first so a partial write never happens.
        $errors = [];
        foreach ($bySection as $section => $names) {
            foreach ($names as $name) {
                $field = $this->registry->field($name);
                if ($field === null) {
                    continue;
                }
                $value = $this->processor->sanitize($field, $input[$name], $this->settings->get($name, $field->default));
                $error = $this->processor->validate($field, $value, $input);
                if ($error !== null) {
                    $errors[$name] = $error;
                }
            }
        }

        if ($errors !== []) {
            return $this->validationFailed($request, $errors);
        }

        foreach ($bySection as $section => $names) {
            $result = $this->processor->process($section, $input, $names);
            $errors += $result['errors'];
        }

        if ($errors !== []) {
            return $this->validationFailed($request, $errors);
        }

        return $this->index($request);
    }
}
