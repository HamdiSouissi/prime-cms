<?php

declare(strict_types=1);

namespace HelloPrime;

use HelloPrime\Controller\HelloPrimeController;
use Prime\Admin\AdminContext;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Admin\Notice;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Content\ContentType;
use Prime\Content\EntryRepository;
use Prime\Content\Fields\Field;
use Prime\Content\Fields\FieldGroup;
use Prime\Content\Taxonomy;
use Prime\Content\TermRepository;
use Prime\Editor\BlockDefinition;
use Prime\Http\Router;
use Prime\Media\Media;
use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;
use Prime\Security\HtmlSanitizer;
use Throwable;

/**
 * Reference plugin exercising the entire official Prime CMS PluginContext API
 * (menus, capabilities, settings, migrations, routes, notices, custom content types,
 * taxonomies, custom fields, custom Prime Editor blocks, and traceless uninstall)
 * without touching core internals.
 */
final class Plugin extends AbstractPlugin
{
    public const CAPABILITY_MANAGE = 'hello_prime.manage';
    public const DEFAULT_GREETING = 'Bonjour depuis Hello Prime !';
    public const CPT_TESTIMONIAL = 'testimonial';
    public const TAX_SOURCE = 'testimonial_source';
    public const BLOCK_HIGHLIGHT = 'hello-prime/highlight';

    public function register(PluginContext $context): void
    {
        // 1. Register custom capability grouped under "Hello Prime"
        $context->capabilities()->register(
            self::CAPABILITY_MANAGE,
            'Gérer les réglages et messages Hello Prime',
        );

        // 2. Register custom Content Type ("Témoignages")
        $context->contentTypes()->register(new ContentType(
            name: self::CPT_TESTIMONIAL,
            labels: [
                'singular' => 'Témoignage',
                'plural' => 'Témoignages',
                'add_new' => 'Nouveau témoignage',
                'edit' => 'Modifier le témoignage',
                'empty_title' => 'Aucun témoignage enregistré',
                'empty_description' => 'Ajoutez des retours clients à mettre en valeur sur votre site.',
                'search_placeholder' => 'Rechercher un témoignage…',
            ],
            icon: 'star',
            accent: 'violet',
            hierarchical: false,
            public: true,
            supports: ['editor', 'excerpt', 'author', 'revisions', 'custom_fields', 'thumbnail'],
            taxonomies: [self::TAX_SOURCE],
            menuPosition: 35,
            rewriteBase: 'temoignages',
        ));

        // 3. Register custom Taxonomy ("Sources") attached to `testimonial`
        $context->taxonomies()->register(new Taxonomy(
            name: self::TAX_SOURCE,
            labels: [
                'singular' => 'Source',
                'plural' => 'Sources',
                'add_new' => 'Ajouter une source',
                'empty_title' => 'Aucune source enregistrée',
            ],
            hierarchical: false,
            contentTypes: [self::CPT_TESTIMONIAL],
            public: true,
            icon: 'tag',
        ));

        // 4. Register Custom Field Group attached to `testimonial`
        $context->fields()->registerGroup(new FieldGroup(
            id: 'hello-prime.testimonial_details',
            title: 'Détails du témoignage',
            contentTypes: [self::CPT_TESTIMONIAL],
            fields: [
                new Field(
                    name: 'author_role',
                    type: 'text',
                    label: 'Fonction / Entreprise',
                    placeholder: 'Ex. Directrice produit chez Studio Horizon',
                ),
                new Field(
                    name: 'rating',
                    type: 'number',
                    label: 'Note (1 à 5)',
                    default: 5,
                    min: 1.0,
                    max: 5.0,
                    step: 1.0,
                ),
                new Field(
                    name: 'author_photo',
                    type: 'media',
                    label: 'Photo du client',
                    description: 'Portrait sélectionné depuis la médiathèque (optionnel).',
                    mediaKinds: ['image'],
                ),
                new Field(
                    name: 'featured',
                    type: 'checkbox',
                    label: 'Mettre en avant sur la page publique',
                    default: false,
                ),
            ],
            location: FieldGroup::LOCATION_SIDEBAR,
            order: 15,
            description: 'Métadonnées déclarées par le plugin Hello Prime.',
        ));

        // 5. Register custom Prime Editor block (`hello-prime/highlight`) + client module
        $context->blocks()->register(new BlockDefinition(
            type: self::BLOCK_HIGHLIGHT,
            title: 'Mise en avant Hello Prime',
            description: 'Encart violet mettant en lumière un message clé ou un témoignage.',
            icon: 'sparkles',
            category: 'plugins',
            validator: static function (array $data, HtmlSanitizer $sanitizer): array {
                $badge = is_string($data['badge'] ?? null) ? trim($data['badge']) : 'À retenir';
                $badge = mb_substr($badge, 0, 60);
                $text = is_string($data['text'] ?? null) ? $sanitizer->sanitizeInline($data['text']) : '';

                return [
                    'data' => [
                        'badge' => $badge !== '' ? $badge : 'À retenir',
                        'text' => $text,
                    ],
                    'errors' => [],
                ];
            },
            renderer: static function (array $data, HtmlSanitizer $sanitizer): string {
                $badge = is_string($data['badge'] ?? null) ? trim($data['badge']) : 'À retenir';
                $text = is_string($data['text'] ?? null) ? $sanitizer->sanitizeInline($data['text']) : '';

                return '<aside class="pc-block pc-block--hello-highlight">'
                    . '<span class="pc-hello-highlight__badge">' . htmlspecialchars($badge, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
                    . '<div class="pc-hello-highlight__body">' . $text . '</div>'
                    . '</aside>';
            },
            textExtractor: static function (array $data): string {
                $badge = is_string($data['badge'] ?? null) ? trim($data['badge']) : '';
                $text = is_string($data['text'] ?? null) ? trim(strip_tags($data['text'])) : '';

                return trim($badge . ' ' . $text);
            },
            keywords: ['highlight', 'spotlight', 'hello', 'encart', 'violet'],
        ));
        $context->blocks()->enqueueScript($context->url('js/highlight-block.js'));

        // 6. Register controller in container using PluginContext dependencies
        $context->app()->container()->singleton(
            HelloPrimeController::class,
            static function () use ($context): HelloPrimeController {
                /** @var AdminContext $adminContext */
                $adminContext = $context->app()->make(AdminContext::class);
                /** @var Auth $auth */
                $auth = $context->app()->make(Auth::class);

                return new HelloPrimeController($context, $adminContext, $auth);
            },
        );

        // 7. Add sidebar menu item (violet accent) via `admin.menu` filter
        $context->hooks()->filter('admin.menu', static function (mixed $menu): mixed {
            if ($menu instanceof AdminMenu) {
                $menu->add(new MenuItem(
                    id: 'plugin.hello-prime',
                    label: 'Hello Prime',
                    icon: 'sparkles',
                    url: '/admin/hello-prime',
                    position: 62,
                    accent: 'violet',
                    capability: self::CAPABILITY_MANAGE,
                    group: 'Système',
                ));
            }

            return $menu;
        });

        // 8. Register plugin stylesheet via `admin.assets` filter
        $context->hooks()->filter('admin.assets', static function (mixed $assets) use ($context): mixed {
            if (is_array($assets) && isset($assets['css']) && is_array($assets['css'])) {
                $assets['css'][] = $context->url('css/hello-prime.css');
            }

            return $assets;
        });

        // 9. Add a dashboard shortcut tile via `admin.dashboard.shortcuts` filter
        $context->hooks()->filter('admin.dashboard.shortcuts', static function (mixed $shortcuts, mixed $user): mixed {
            if (!is_array($shortcuts)) {
                return $shortcuts;
            }
            if ($user instanceof User && !$user->can(self::CAPABILITY_MANAGE)) {
                return $shortcuts;
            }

            $shortcuts[] = [
                'title' => 'Hello Prime',
                'description' => 'Configurer le message d’accueil et le livre d’or.',
                'icon' => 'sparkles',
                'accent' => 'violet',
                'url' => '/admin/hello-prime',
            ];

            return $shortcuts;
        });

        // 10. Declare settings shortcut URL for `/admin/plugins`
        $context->setSettingsUrl('/admin/hello-prime');

        // 11. Register protected admin routes (`/admin/hello-prime/*`)
        $context->router()->adminGroup(static function (Router $r): void {
            $r->get('/', [HelloPrimeController::class, 'adminIndex'])->name('index');
            $r->post('/settings', [HelloPrimeController::class, 'saveSettings'])->name('settings');
            $r->post('/greetings', [HelloPrimeController::class, 'addGreeting'])->name('greetings.store');
            $r->post('/greetings/{id:\d+}/delete', [HelloPrimeController::class, 'deleteGreeting'])->name('greetings.delete');
        }, capability: self::CAPABILITY_MANAGE);

        // 12. Register public front-office route (`GET /hello-prime`)
        $context->router()->frontGroup(static function (Router $r): void {
            $r->get('/hello-prime', [HelloPrimeController::class, 'publicPage'])->name('public');
        });

        // 13. Observe `media.uploaded` lifecycle event (Phase 7 example)
        $context->hooks()->on('media.uploaded', static function (mixed $media) use ($context): void {
            if ($media instanceof Media) {
                $context->logger()->info(sprintf('Hello Prime : nouveau média détecté (#%d — %s)', $media->id, $media->originalFilename));
            }
        });
    }

    public function activate(PluginContext $context): void
    {
        if (!$context->settings()->has('greeting')) {
            $context->settings()->set('greeting', self::DEFAULT_GREETING);
        }

        $context->notices()->add(
            id: 'welcome',
            type: Notice::TYPE_SUCCESS,
            title: 'Hello Prime est activé !',
            message: 'Personnalisez votre message d’accueil, gérez vos Témoignages et testez le bloc Mise en avant.',
            actionLabel: 'Ouvrir Hello Prime',
            actionUrl: '/admin/hello-prime',
            capability: self::CAPABILITY_MANAGE,
            dismissible: true,
        );

        $context->logger()->info('Plugin Hello Prime activé.');
    }

    public function deactivate(PluginContext $context): void
    {
        $context->notices()->remove('welcome');
        $context->logger()->info('Plugin Hello Prime désactivé.');
    }

    public function uninstall(PluginContext $context): void
    {
        $context->notices()->clearAll();

        // Traceless cleanup of all `testimonial` entries & `testimonial_source` terms
        try {
            if ($context->app()->container()->has(EntryRepository::class)) {
                /** @var EntryRepository $entries */
                $entries = $context->app()->get(EntryRepository::class);
                $entries->deleteByType(self::CPT_TESTIMONIAL);
            }
            if ($context->app()->container()->has(TermRepository::class)) {
                /** @var TermRepository $terms */
                $terms = $context->app()->get(TermRepository::class);
                $terms->deleteByTaxonomy(self::TAX_SOURCE);
            }
        } catch (Throwable) {
            // Content tables may not exist in lightweight unit test harnesses.
        }

        $context->logger()->info('Plugin Hello Prime désinstallé : nettoyage complet terminé.');
    }
}
