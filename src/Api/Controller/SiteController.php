<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Settings\Settings;

/**
 * `GET /api/v1/site` — Informations publiques du site (titre, tagline, fuseau, locale, URL).
 */
final class SiteController extends ApiController
{
    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    public function show(Request $request): Response
    {
        $data = [
            'title' => $this->settings->getString('site.title', 'Prime CMS'),
            'tagline' => $this->settings->getString('site.tagline', ''),
            'url' => $this->settings->getString('site.url', ''),
            'timezone' => $this->settings->getString('general.timezone', 'UTC'),
            'locale' => $this->settings->getString('site.locale', 'fr'),
            'date_format' => $this->settings->getString('general.date_format', 'Y-m-d'),
            'time_format' => $this->settings->getString('general.time_format', 'H:i'),
            'week_starts' => $this->settings->getInt('general.week_starts', 1),
        ];

        return $this->json(['data' => $data]);
    }
}
