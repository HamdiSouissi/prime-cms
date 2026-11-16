<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Admin\TranslatesAdminCopy;
use Prime\Auth\Capabilities;
use Prime\Auth\Exception\PolicyException;
use Prime\Auth\Role;
use Prime\Auth\RoleRepository;
use Prime\Database\Connection;
use Prime\Http\Exception\ForbiddenException;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Validation\Validator;
use Prime\View\View;

/**
 * CRUD controller for `/admin/roles` (`roles.manage` capability).
 */
final class RoleController
{
    use TranslatesAdminCopy;

    public function __construct(
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly RoleRepository $roles,
        private readonly Capabilities $capabilities,
        private readonly Connection $connection,
    ) {
    }

    public function index(Request $request): Response
    {
        $allRoles = $this->roles->all();

        $data = $this->context->build($request, $this->t('admin.ctrl_text_role.roles_et_permissions'), [
            'roles' => $allRoles,
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_role.tableau_de_bord'), 'url' => '/admin'],
                ['label' => $this->t('admin.shared.utilisateurs'), 'url' => '/admin/users'],
                ['label' => 'Rôles', 'url' => null],
            ],
        ]);

        return Response::html($this->view->render('admin::roles/index', $data));
    }

    public function create(Request $request): Response
    {
        $data = $this->context->build($request, $this->t('admin.ctrl_text_role.creer_un_role'), [
            'mode' => 'create',
            'role' => null,
            'isReadOnly' => false,
            'groupedCapabilities' => $this->capabilities->grouped(),
            'old' => [
                'name' => '',
                'slug' => '',
                'description' => '',
                'capabilities' => ['dashboard.view'],
            ],
            'errors' => [],
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_role.tableau_de_bord'), 'url' => '/admin'],
                ['label' => 'Rôles', 'url' => '/admin/roles'],
                ['label' => 'Créer', 'url' => null],
            ],
        ]);

        return Response::html($this->view->render('admin::roles/form', $data));
    }

    public function store(Request $request): Response
    {
        $name = trim((string) $request->body('name', ''));
        $rawSlug = trim((string) $request->body('slug', ''));
        $slug = $rawSlug !== '' ? RoleRepository::slugify($rawSlug) : RoleRepository::slugify($name);
        $description = trim((string) $request->body('description', ''));
        $selectedCaps = $this->parseCapabilities($request->body('capabilities', []));

        $validator = Validator::make(
            data: [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
            ],
            rules: [
                'name' => 'required|string|between:2,100',
                'slug' => ['required', 'string', 'between:2,64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:roles,slug'],
                'description' => 'nullable|string|max:500',
            ],
            messages: [
                'slug.regex' => 'Le slug doit contenir uniquement des lettres minuscules, chiffres et tirets (ex. « moderateur »).',
                'slug.unique' => $this->t('admin.ctrl_text_role.ce_slug_de_role_existe_deja'),
            ],
            labels: [
                'name' => $this->t('admin.ctrl_text_role.nom_du_role'),
                'slug' => 'Identifiant (slug)',
                'description' => 'Description',
            ],
            connection: $this->connection,
        );

        $errors = $validator->errors();
        $validCaps = $this->capabilities->filterValid($selectedCaps);

        if ($errors !== []) {
            $data = $this->context->build($request, $this->t('admin.ctrl_text_role.creer_un_role'), [
                'mode' => 'create',
                'role' => null,
                'isReadOnly' => false,
                'groupedCapabilities' => $this->capabilities->grouped(),
                'old' => [
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                    'capabilities' => $validCaps,
                ],
                'errors' => $errors,
                'breadcrumbs' => [
                    ['label' => $this->t('admin.ctrl_text_role.tableau_de_bord'), 'url' => '/admin'],
                    ['label' => 'Rôles', 'url' => '/admin/roles'],
                    ['label' => 'Créer', 'url' => null],
                ],
            ]);

            return Response::html($this->view->render('admin::roles/form', $data), 422);
        }

        $role = $this->roles->create($slug, $name, $description, $validCaps);
        $this->context->flashToast(sprintf($this->t('admin.ctrl_text_role.role_s_cree_avec_succes'), $role->name), 'success');

        return Response::redirect('/admin/roles', 302);
    }

    public function edit(Request $request, string $id): Response
    {
        $role = $this->findRoleOr404($id);
        $isReadOnly = $role->slug === Role::ADMINISTRATOR_SLUG;

        $data = $this->context->build($request, 'Modifier le rôle ' . $role->name, [
            'mode' => 'edit',
            'role' => $role,
            'isReadOnly' => $isReadOnly,
            'groupedCapabilities' => $this->capabilities->grouped(),
            'old' => [
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description ?? '',
                'capabilities' => $role->capabilities,
            ],
            'errors' => [],
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_role.tableau_de_bord'), 'url' => '/admin'],
                ['label' => 'Rôles', 'url' => '/admin/roles'],
                ['label' => $role->name, 'url' => null],
            ],
        ]);

        return Response::html($this->view->render('admin::roles/form', $data));
    }

    public function update(Request $request, string $id): Response
    {
        $role = $this->findRoleOr404($id);

        if ($role->slug === Role::ADMINISTRATOR_SLUG) {
            throw new ForbiddenException($this->t('admin.ctrl_text_role.le_role_administrateur_est_en_lecture_seule_et_ne_peut_pas_e'));
        }

        $name = trim((string) $request->body('name', ''));
        $description = trim((string) $request->body('description', ''));
        $selectedCaps = $this->capabilities->filterValid($this->parseCapabilities($request->body('capabilities', [])));

        $validator = Validator::make(
            data: [
                'name' => $name,
                'description' => $description,
            ],
            rules: [
                'name' => 'required|string|between:2,100',
                'description' => 'nullable|string|max:500',
            ],
            labels: [
                'name' => $this->t('admin.ctrl_text_role.nom_du_role'),
                'description' => 'Description',
            ],
        );

        $errors = $validator->errors();
        if ($errors !== []) {
            $data = $this->context->build($request, 'Modifier le rôle ' . $role->name, [
                'mode' => 'edit',
                'role' => $role,
                'isReadOnly' => false,
                'groupedCapabilities' => $this->capabilities->grouped(),
                'old' => [
                    'name' => $name,
                    'slug' => $role->slug,
                    'description' => $description,
                    'capabilities' => $selectedCaps,
                ],
                'errors' => $errors,
                'breadcrumbs' => [
                    ['label' => $this->t('admin.ctrl_text_role.tableau_de_bord'), 'url' => '/admin'],
                    ['label' => 'Rôles', 'url' => '/admin/roles'],
                    ['label' => $role->name, 'url' => null],
                ],
            ]);

            return Response::html($this->view->render('admin::roles/form', $data), 422);
        }

        try {
            $updated = $this->roles->update($role->id, $name, $description, $selectedCaps);
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_role.role_s_mis_a_jour'), $updated->name), 'success');
        } catch (PolicyException $exception) {
            $this->context->flashToast($exception->getMessage(), 'danger');
        }

        return Response::redirect('/admin/roles', 302);
    }

    public function delete(Request $request, string $id): Response
    {
        $role = $this->findRoleOr404($id);

        try {
            $this->roles->delete($role->id);
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_role.le_role_s_a_ete_supprime'), $role->name), 'success');
        } catch (PolicyException $exception) {
            $this->context->flashToast($exception->getMessage(), 'danger');
        }

        return Response::redirect('/admin/roles', 302);
    }

    private function findRoleOr404(string $id): Role
    {
        if (!ctype_digit($id) || (int) $id < 1) {
            throw new NotFoundException($this->t('admin.ctrl_text_role.role_introuvable'));
        }

        $role = $this->roles->find((int) $id);
        if ($role === null) {
            throw new NotFoundException($this->t('admin.ctrl_text_role.role_introuvable'));
        }

        return $role;
    }

    /**
     * @return list<string>
     */
    private function parseCapabilities(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $caps = [];
        foreach ($raw as $cap) {
            if (is_string($cap) && $cap !== '') {
                $caps[] = trim($cap);
            }
        }

        return $caps;
    }
}
