<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Http\Request;
use Prime\Http\Response;
use Prime\I18n\Translator;
use Prime\Settings\Settings;
use Prime\Site\UrlGenerator;

/**
 * `GET /api/v1/site` — Informations publiques du site (titre, tagline, fuseau, locale, URL).
 */
final class SiteController extends ApiController
{
    public function __construct(
        private readonly Settings $settings,
        private readonly UrlGenerator $urls,
        private readonly Translator $translator,
    ) {
    }

    public function show(Request $request): Response
    {
        $name = $this->settings->getString('site.name', 'Prime CMS');
        $data = [
            'name' => $name,
            'tagline' => $this->settings->getString('site.tagline', ''),
            'description' => $this->settings->getString('site.description', ''),
            'url' => $this->urls->absolute('/'),
            'locale' => $this->translator->locale(),
            'timezone' => $this->settings->getString('general.timezone', 'UTC'),
            'date_format' => $this->settings->getString('general.date_format', 'Y-m-d'),
            'time_format' => $this->settings->getString('general.time_format', 'H:i'),
            'start_of_week' => $this->settings->getInt('general.start_of_week', 1),
            'api' => ['version' => 'v1', 'openapi' => $this->urls->absolute('/api/v1/openapi.json')],
        ];

        return $this->json(['data' => $data]);
    }
}
