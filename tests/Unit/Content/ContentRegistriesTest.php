<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Content;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prime\Content\ContentType;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Exception\ContentException;
use Prime\Content\Fields\Field;
use Prime\Content\Fields\FieldGroup;
use Prime\Content\Fields\FieldRegistry;
use Prime\Content\Fields\FieldValidator;
use Prime\Content\Taxonomy;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Schema;
use Prime\Hooks\Hooks;
use Prime\Security\HtmlSanitizer;
use Prime\Tests\Support\MysqlEmulatingPdo;

final class ContentRegistriesTest extends TestCase
{
    public function testContentTypeRegistryDeclaresPageAndPostAndSupportsFilters(): void
    {
        $hooks = new Hooks();
        $registry = new ContentTypeRegistry($hooks);

        $this->assertTrue($registry->has('page'));
        $this->assertTrue($registry->has('post'));

        $page = $registry->get('page');
        $post = $registry->get('post');
        $this->assertNotNull($page);
        $this->assertNotNull($post);
        $this->assertTrue($page->hierarchical);
        $this->assertTrue($page->supports('page_attributes'));
        $this->assertSame([], $page->taxonomies);
        $this->assertSame('blocks', $page->editor);

        $this->assertFalse($post->hierarchical);
        $this->assertTrue($post->supports('excerpt'));
        $this->assertSame(['category', 'tag'], $post->taxonomies);

        $hooks->filter('content.types', static function (array $types): array {
            $types['portfolio'] = new ContentType(
                name: 'portfolio',
                labels: ['singular' => 'Projet', 'plural' => 'Portfolio'],
                accent: 'violet',
                menuPosition: 25,
            );

            return $types;
        });

        $keys = array_keys($registry->all());
        $this->assertSame(['page', 'portfolio', 'post'], $keys);
    }

    public function testContentTypeRejectsInvalidNamesAndSupports(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ContentType(name: 'Invalid-Type!', labels: ['singular' => 'Bad']);
    }

    public function testTaxonomyRegistryAndTermRepositoryLifecycle(): void
    {
        $pdo = new MysqlEmulatingPdo('id');
        $conn = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $schema = new Schema($conn);

        foreach ([
            '2026_11_15_000002_create_users_table.php',
            '2026_11_15_000010_create_entries_table.php',
            '2026_11_15_000013_create_terms_tables.php',
        ] as $file) {
            /** @var Migration $m */
            $m = require dirname(__DIR__, 3) . '/database/migrations/' . $file;
            $m->up($schema);
        }

        $hooks = new Hooks();
        $taxonomies = new TaxonomyRegistry($hooks);
        $repo = new TermRepository($conn, $taxonomies);

        $this->assertTrue($taxonomies->has('category'));
        $this->assertTrue($taxonomies->has('tag'));
        $this->assertArrayHasKey('category', $taxonomies->forContentType('post'));
        $this->assertArrayHasKey('tag', $taxonomies->forContentType('post'));

        // Seeded default category exists and cannot be deleted
        $defaultCat = $repo->findBySlug('category', 'non-classe');
        $this->assertNotNull($defaultCat);
        try {
            $repo->delete($defaultCat->id);
            $this->fail('Default category must not be deletable.');
        } catch (ContentException $e) {
            $this->assertStringContainsString('ne peut pas être supprimé', $e->getMessage());
        }

        // Hierarchical creation, duplicate slug suffixing, cycle protection, reparenting on delete
        $parent = $repo->create('category', 'Développement');
        $dup = $repo->create('category', 'Développement');
        $this->assertSame('developpement', $parent->slug);
        $this->assertSame('developpement-2', $dup->slug);

        $child = $repo->create('category', 'PHP 8', parentId: $parent->id);
        $grandChild = $repo->create('category', 'Architecture', parentId: $child->id);

        // Tree order
        $tree = $repo->flatTreeForTaxonomy('category');
        $depths = [];
        foreach ($tree as $item) {
            $depths[$item['term']->slug] = $item['depth'];
        }
        $this->assertSame(0, $depths['developpement']);
        $this->assertSame(1, $depths['php-8']);
        $this->assertSame(2, $depths['architecture']);

        // Cannot set own descendant as parent
        try {
            $repo->update($parent->id, 'Développement', parentId: $grandChild->id);
            $this->fail('Cycle in term hierarchy must be prevented.');
        } catch (ContentException $e) {
            $this->assertStringContainsString('propres sous-termes', $e->getMessage());
        }

        // Deleting intermediate term reparents grandchild to parent
        $repo->delete($child->id);
        $reparented = $repo->find($grandChild->id);
        $this->assertNotNull($reparented);
        $this->assertSame($parent->id, $reparented->parentId);

        // Term count counts strictly published entries
        $now = '2026-11-15 10:00:00';
        $pubId = $conn->table('entries')->insertGetId([
            'type' => 'post',
            'status' => 'published',
            'title' => 'Publié',
            'slug' => 'publie',
            'content' => '{"version":1,"blocks":[]}',
            'content_html' => '',
            'content_text' => '',
            'published_at' => '2025-01-01 00:00:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $draftId = $conn->table('entries')->insertGetId([
            'type' => 'post',
            'status' => 'draft',
            'title' => 'Brouillon',
            'slug' => 'brouillon',
            'content' => '{"version":1,"blocks":[]}',
            'content_html' => '',
            'content_text' => '',
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $conn->table('entry_terms')->insert(['entry_id' => $pubId, 'term_id' => $parent->id, 'position' => 0]);
        $conn->table('entry_terms')->insert(['entry_id' => $draftId, 'term_id' => $parent->id, 'position' => 0]);

        $repo->recountTerms([$parent->id]);
        $refreshed = $repo->find($parent->id);
        $this->assertNotNull($refreshed);
        $this->assertSame(1, $refreshed->count);
    }

    public function testCustomFieldRegistryAndValidatorCoverAllFieldTypes(): void
    {
        $hooks = new Hooks();
        $registry = new FieldRegistry($hooks);

        $registry->registerGroup(new FieldGroup(
            id: 'demo.details',
            title: 'Détails',
            contentTypes: ['post'],
            location: FieldGroup::LOCATION_SIDEBAR,
            fields: [
                new Field(name: 'subtitle', type: 'text', label: 'Sous-titre', required: true),
                new Field(name: 'notes', type: 'textarea', label: 'Notes'),
                new Field(name: 'rating', type: 'number', label: 'Note', min: 1, max: 5, required: true),
                new Field(name: 'contact', type: 'email', label: 'Email'),
                new Field(name: 'website', type: 'url', label: 'Site web'),
                new Field(name: 'level', type: 'select', label: 'Niveau', options: ['beginner' => 'Débutant', 'pro' => 'Pro']),
                new Field(name: 'featured', type: 'toggle', label: 'À la une'),
                new Field(name: 'agree', type: 'checkbox', label: 'Validé'),
                new Field(name: 'event_date', type: 'date', label: 'Date'),
                new Field(name: 'event_at', type: 'datetime', label: 'Date et heure'),
                new Field(name: 'accent_hex', type: 'color', label: 'Couleur'),
                new Field(name: 'related_id', type: 'entry', label: 'Contenu lié', entryTypes: ['page']),
            ],
        ));

        $validator = new FieldValidator($registry, new HtmlSanitizer());

        $valid = $validator->validate('post', [
            'subtitle' => 'Guide complet',
            'notes' => 'Notes internes',
            'rating' => '5',
            'contact' => 'editor@example.com',
            'website' => 'https://primecms.org',
            'level' => 'pro',
            'featured' => '1',
            'agree' => 'on',
            'event_date' => '2026-12-01',
            'event_at' => '2026-12-01T14:30',
            'accent_hex' => '#ED6647',
            'related_id' => '42',
        ]);

        $this->assertTrue($valid['valid'], json_encode($valid['errors']) ?: '');
        $this->assertSame(5, $valid['values']['rating']);
        $this->assertTrue($valid['values']['featured']);
        $this->assertSame('#ed6647', $valid['values']['accent_hex']);
        $this->assertSame(42, $valid['values']['related_id']);
        $this->assertSame('2026-12-01 14:30:00', $valid['values']['event_at']);

        // Invalid values rejected
        $invalid = $validator->validate('post', [
            'subtitle' => '',
            'rating' => '9',
            'contact' => 'not-an-email',
            'website' => 'javascript:alert(1)',
            'level' => 'unknown',
            'event_date' => '2026-99-99',
            'accent_hex' => 'red',
            'related_id' => 'abc',
        ]);

        $this->assertFalse($invalid['valid']);
        $this->assertArrayHasKey('fields.subtitle', $invalid['errors']);
        $this->assertArrayHasKey('fields.rating', $invalid['errors']);
        $this->assertArrayHasKey('fields.contact', $invalid['errors']);
        $this->assertArrayHasKey('fields.website', $invalid['errors']);
        $this->assertArrayHasKey('fields.level', $invalid['errors']);
        $this->assertArrayHasKey('fields.event_date', $invalid['errors']);
        $this->assertArrayHasKey('fields.accent_hex', $invalid['errors']);
        $this->assertArrayHasKey('fields.related_id', $invalid['errors']);
    }
}
