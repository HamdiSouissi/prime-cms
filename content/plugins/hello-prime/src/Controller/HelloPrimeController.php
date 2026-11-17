<?php

declare(strict_types=1);

namespace HelloPrime\Controller;

use DateTimeImmutable;
use DateTimeZone;
use HelloPrime\Plugin;
use Prime\Admin\AdminContext;
use Prime\Auth\Auth;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Plugins\PluginContext;

/**
 * Admin & public controller for the Hello Prime reference plugin.
 */
final class HelloPrimeController
{
    public const TABLE = 'hello_prime_greetings';

    public function __construct(
        private readonly PluginContext $plugin,
        private readonly AdminContext $adminContext,
        private readonly Auth $auth,
    ) {
    }

    public function adminIndex(Request $request): Response
    {
        $greeting = (string) $this->plugin->settings()->get('greeting', Plugin::DEFAULT_GREETING);
        $entries = $this->plugin->db()->table(self::TABLE)
            ->orderBy('id', 'DESC')
            ->limit(50)
            ->get();

        $html = $this->plugin->views()->render('hello-prime::admin', $this->adminContext->build($request, 'Hello Prime', [
            'greeting' => $greeting,
            'entries' => $entries,
            'pluginVersion' => $this->plugin->version(),
            'breadcrumbs' => [
                ['label' => 'Tableau de bord', 'url' => '/admin'],
                ['label' => 'Plugins', 'url' => '/admin/plugins'],
                ['label' => 'Hello Prime', 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    public function saveSettings(Request $request): Response
    {
        $greeting = trim((string) $request->body('greeting', ''));
        if ($greeting === '' || mb_strlen($greeting) > 160) {
            $this->adminContext->flashToast(
                'Le message d’accueil doit contenir entre 1 et 160 caractères.',
                'danger',
                'Validation échouée',
            );

            return Response::redirect('/admin/hello-prime');
        }

        $this->plugin->settings()->set('greeting', $greeting);
        $this->adminContext->flashToast('Le message d’accueil a été enregistré.', 'success', 'Réglages sauvegardés');

        return Response::redirect('/admin/hello-prime');
    }

    public function addGreeting(Request $request): Response
    {
        $message = trim((string) $request->body('message', ''));
        if ($message === '' || mb_strlen($message) > 280) {
            $this->adminContext->flashToast(
                'Le message doit contenir entre 1 et 280 caractères.',
                'danger',
                'Message invalide',
            );

            return Response::redirect('/admin/hello-prime');
        }

        $user = $this->auth->user($request);
        $author = trim((string) $request->body('author', $user !== null ? $user->displayName : 'Administrateur'));
        if ($author === '') {
            $author = 'Administrateur';
        }

        $this->plugin->db()->table(self::TABLE)->insert([
            'author' => mb_substr($author, 0, 80),
            'message' => $message,
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);

        $this->adminContext->flashToast('Nouveau message ajouté dans la base de données.', 'success', 'Message enregistré');

        return Response::redirect('/admin/hello-prime');
    }

    public function deleteGreeting(Request $request, string $id): Response
    {
        $this->plugin->db()->table(self::TABLE)->where('id', '=', (int) $id)->delete();
        $this->adminContext->flashToast('Message supprimé.', 'info');

        return Response::redirect('/admin/hello-prime');
    }

    public function publicPage(Request $request): Response
    {
        $greeting = (string) $this->plugin->settings()->get('greeting', Plugin::DEFAULT_GREETING);
        $entries = $this->plugin->db()->table(self::TABLE)
            ->orderBy('id', 'DESC')
            ->limit(20)
            ->get();

        $appName = (string) $this->plugin->app()->config()->get('app.name');

        $html = $this->plugin->views()->render('hello-prime::public', [
            'appName' => $appName,
            'greeting' => $greeting,
            'entries' => $entries,
            'pluginVersion' => $this->plugin->version(),
            'cssUrl' => $this->plugin->url('css/hello-prime.css'),
        ]);

        return Response::html($html);
    }
}
