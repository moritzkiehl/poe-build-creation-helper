<?php

declare(strict_types=1);

namespace App\Build\Edit;

use App\Entity\Build;
use App\Interchange\BuildDocument;

/**
 * The editable state of a build, in one array and back again.
 *
 * Both halves travel together on purpose: reverting a build that restored the
 * passives but kept a later note would be a state the user never had.
 *
 * @phpstan-type Snapshot array{document: array<string, mixed>, header: array{class_key: string|null, target_level: int|null, note: string|null, archetype_key: string|null}}
 */
final class BuildSnapshot
{
    /**
     * @return Snapshot
     */
    public static function capture(Build $build): array
    {
        $document = $build->toDocument();

        return [
            'document' => [
                'name' => $document->name,
                'author' => $document->author,
                'link' => $document->link,
                'description' => $document->description,
                'ascendancy' => $document->ascendancy,
                'passives' => $document->passives,
                'skills' => $document->skills,
                'inventory_slots' => $document->inventorySlots,
            ],
            'header' => [
                'class_key' => $build->getClassKey(),
                'target_level' => $build->getTargetLevel(),
                'note' => $build->getNote(),
                'archetype_key' => $build->getArchetypeKey(),
            ],
        ];
    }

    /**
     * @param Snapshot $snapshot
     */
    public static function restore(Build $build, array $snapshot): void
    {
        $document = $snapshot['document'];

        $build->applyDocument(new BuildDocument(
            name: \is_string($document['name'] ?? null) ? $document['name'] : $build->getName(),
            author: self::nullableString($document, 'author'),
            link: self::nullableString($document, 'link'),
            description: self::nullableString($document, 'description'),
            ascendancy: self::nullableString($document, 'ascendancy'),
            passives: self::entries($document, 'passives'),
            skills: self::entries($document, 'skills'),
            inventorySlots: self::entries($document, 'inventory_slots'),
        ));

        $build->setClassKey($snapshot['header']['class_key']);
        $build->setTargetLevel($snapshot['header']['target_level']);
        $build->setNote($snapshot['header']['note']);
        $build->setArchetypeKey($snapshot['header']['archetype_key']);
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function nullableString(array $document, string $field): ?string
    {
        $value = $document[$field] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(array $document, string $field): array
    {
        $entries = [];

        foreach ((array) ($document[$field] ?? []) as $entry) {
            if (\is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
