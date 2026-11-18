<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Api\ApiRouteRegistry;
use Prime\Api\Repository\ApiTokenRepository;
use Prime\Api\Token\ApiTokenService;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Settings\Settings;
use Prime\View\View;

/**
 * « Outils › API » (`settings.manage`): state of the REST API, every active token across
 * accounts with a confirmed revocation, and links to the OpenAPI document and the guide.
 */
final class ApiToolsController
{
    public function __construct(
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly Settings $settings,
        private readonly ApiTokenRepository $tokens,
        private readonly ApiTokenService $service,
        private readonly ApiRouteRegistry $routes,
    ) {
    }

    public function index(Request $request): Response
    {
        $title = t('admin.tools.tabs.api');
        $origins = array_values(array_filter(array_map('trim', preg_split('/\R/', $this->settings->getString('api.cors_origins', '')) ?: [])));

        return Response::html($this->view->render('admin::tools/api', $this->context->build($request, $title, [
            'activeTab' => 'api',
            'enabled' => $this->settings->getBool('api.enabled', true),
            'publicRead' => $this->settings->getBool('api.public_read', true),
            'limitAuthenticated' => $this->settings->getInt('api.rate_limit_authenticated', 120),
            'limitAnonymous' => $this->settings->getInt('api.rate_limit_anonymous', 60),
            'origins' => $origins,
            'routeCount' => $this->routes->count(),
            'activeTokens' => $this->tokens->listActive(),
            'breadcrumbs' => [
                ['label' => t('admin.nav.dashboard'), 'url' => '/admin'],
                ['label' => t('admin.tools.title'), 'url' => '/admin/tools'],
                ['label' => $title, 'url' => null],
            ],
        ])));
    }

    public function revoke(Request $request, string $id): Response
    {
        $token = $this->tokens->findById((int) $id);
        if ($token === null) {
            return Response::html('', 404);
        }

        $this->service->revoke((int) $id);
        $this->context->flashToast(t('admin.api_tokens.revoked', ['name' => (string) ($token['name'] ?? '')]), 'success');

        return Response::redirect('/admin/tools/api');
    }
}
