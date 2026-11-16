<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\TaxonomyRegistry;

/**
 * Lists all registered Content Types and Taxonomies (`bin/prime content:types`).
 */
final class ContentTypesCommand implements CommandInterface
{
    public function __construct(
        private readonly ContentTypeRegistry $types,
        private readonly TaxonomyRegistry $taxonomies,
    ) {
    }

    public function name(): string
    {
        return 'content:types';
    }

    public function description(): string
    {
        return 'Affiche les types de contenus et taxonomies enregistrés';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $typeRows = [];
        foreach ($this->types->all() as $ct) {
            $attached = array_keys($this->taxonomies->forContentType($ct->name, $ct));
            $typeRows[] = [
                $ct->name,
                $ct->label('plural'),
                $ct->public ? 'Oui' : 'Non',
                $ct->hierarchical ? 'Oui' : 'Non',
                $attached !== [] ? implode(', ', $attached) : '-',
                $ct->source,
            ];
        }

        $output->writeln('Types de contenus :');
        $output->table(['Nom', 'Libellé', 'Public', 'Hiérarchique', 'Taxonomies', 'Source'], $typeRows);

        $taxRows = [];
        foreach ($this->taxonomies->all() as $tax) {
            $taxRows[] = [
                $tax->name,
                $tax->label('plural'),
                $tax->hierarchical ? 'Oui' : 'Non',
                $tax->contentTypes !== [] ? implode(', ', $tax->contentTypes) : '-',
                $tax->source,
            ];
        }

        $output->writeln('');
        $output->writeln('Taxonomies :');
        $output->table(['Nom', 'Libellé', 'Hiérarchique', 'Types associés', 'Source'], $taxRows);

        return self::SUCCESS;
    }
}
