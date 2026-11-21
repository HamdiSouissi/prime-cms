<?php

declare(strict_types=1);

namespace PrimeStarter\Controller;

use Prime\Admin\AdminContext;
use Prime\Auth\Auth;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Plugins\PluginContext;
use PrimeStarter\Markdown;
use PrimeStarter\NoteRepository;

/**
 * Admin screens (`/admin/prime-starter`) and the public page (`/notes`) of the starter plugin.
 *
 * @phpstan-import-type NoteInput from NoteRepository
 */
final class NotesController
{
    private const ADMIN_URL = '/admin/prime-starter';

    public function __construct(
        private readonly PluginContext $plugin,
        private readonly NoteRepository $notes,
        private readonly AdminContext $admin,
        private readonly Auth $auth,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->page($request, 'prime-starter::admin/index', t('plugin::prime-starter.ui.title'), [
            'notes' => $this->notes->all(),
            'publicEnabled' => $this->publicEnabled(),
            'publicUrl' => '/notes',
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null, ['title' => '', 'body' => '', 'color' => NoteRepository::COLORS[0], 'pinned' => false, 'public' => false], []);
    }

    public function store(Request $request): Response
    {
        [$values, $errors] = NoteRepository::validate($this->input($request));
        if ($errors !== []) {
            return $this->form($request, null, $values, $errors, 422);
        }

        $this->notes->create($values, $this->auth->user($request)?->id);
        $this->admin->flashToast(t('plugin::prime-starter.ui.created'), 'success');

        return Response::redirect(self::ADMIN_URL);
    }

    public function edit(Request $request, string $id): Response
    {
        $note = $this->notes->find((int) $id);
        if ($note === null) {
            return $this->missing();
        }

        return $this->form($request, $note['id'], [
            'title' => $note['title'],
            'body' => $note['body'],
            'color' => $note['color'],
            'pinned' => $note['pinned'],
            'public' => $note['public'],
        ], []);
    }

    public function update(Request $request, string $id): Response
    {
        $note = $this->notes->find((int) $id);
        if ($note === null) {
            return $this->missing();
        }

        [$values, $errors] = NoteRepository::validate($this->input($request));
        if ($errors !== []) {
            return $this->form($request, $note['id'], $values, $errors, 422);
        }

        $this->notes->update($note['id'], $values);
        $this->admin->flashToast(t('plugin::prime-starter.ui.updated'), 'success');

        return Response::redirect(self::ADMIN_URL);
    }

    public function destroy(Request $request, string $id): Response
    {
        $note = $this->notes->find((int) $id);
        if ($note === null) {
            return $this->missing();
        }

        $this->notes->delete($note['id']);
        $this->admin->flashToast(t('plugin::prime-starter.ui.deleted', ['title' => $note['title']]), 'success');

        return Response::redirect(self::ADMIN_URL);
    }

    /**
     * `GET /notes`: the public notes inside the active theme; 404 when the page is switched off.
     */
    public function publicIndex(Request $request): Response
    {
        $title = trim((string) $this->plugin->settings()->get('public_title', 'Carnet'));
        $title = $title !== '' ? $title : 'Carnet';

        if (!$this->publicEnabled()) {
            $html = $this->plugin->views()->render('prime-starter::public', ['title' => $title, 'notes' => [], 'disabled' => true]);

            return $this->plugin->renderPage($request, $title, $html, status: 404);
        }

        $notes = array_map(static fn (array $note): array => $note + ['html' => Markdown::toHtml($note['body'])], $this->notes->all(publicOnly: true));
        $html = $this->plugin->views()->render('prime-starter::public', ['title' => $title, 'notes' => $notes, 'disabled' => false]);

        return $this->plugin->renderPage($request, $title, $html, tc('plugin::prime-starter.public.description', count($notes), ['count' => count($notes)]));
    }

    /**
     * @param NoteInput $values
     * @param array<string, string> $errors
     */
    private function form(Request $request, ?int $id, array $values, array $errors, int $status = 200): Response
    {
        $title = $id === null ? t('plugin::prime-starter.ui.new') : t('plugin::prime-starter.ui.edit');

        return $this->page($request, 'prime-starter::admin/form', $title, [
            'noteId' => $id,
            'values' => $values,
            'errors' => $errors,
            'colors' => NoteRepository::COLORS,
            'action' => $id === null ? self::ADMIN_URL . '/notes' : self::ADMIN_URL . '/notes/' . $id,
        ], $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(Request $request, string $view, string $title, array $data, int $status = 200): Response
    {
        $isIndex = $view === 'prime-starter::admin/index';
        $breadcrumbs = [
            ['label' => t('admin.nav.dashboard'), 'url' => '/admin'],
            ['label' => t('plugin::prime-starter.ui.title'), 'url' => $isIndex ? null : self::ADMIN_URL],
        ];
        if (!$isIndex) {
            $breadcrumbs[] = ['label' => $title, 'url' => null];
        }

        $html = $this->plugin->views()->render($view, $this->admin->build($request, $title, $data + [
            'pageHeading' => $title,
            'breadcrumbs' => $breadcrumbs,
        ]));

        return Response::html($html, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return [
            'title' => $request->body('title', ''),
            'body' => $request->body('body', ''),
            'color' => $request->body('color', ''),
            'pinned' => $request->body('pinned', '0'),
            'public' => $request->body('public', '0'),
        ];
    }

    private function missing(): Response
    {
        $this->admin->flashToast(t('plugin::prime-starter.ui.missing'), 'danger');

        return Response::redirect(self::ADMIN_URL);
    }

    private function publicEnabled(): bool
    {
        $value = $this->plugin->settings()->get('public_enabled', true);

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
