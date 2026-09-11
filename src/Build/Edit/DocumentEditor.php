<?php

declare(strict_types=1);

namespace App\Build\Edit;

use App\Interchange\BuildDocument;

/**
 * Every change a build document can undergo, as pure functions.
 *
 * No database and no framework, because this is where the format's rules live
 * — level ranges, the shape of new entries, which fields a kind of entry may
 * carry — and those rules get readjusted with every game patch.
 *
 * Defaults for new entries are taken from the real-file corpus, not invented:
 * a passive the game wrote carries `level_interval` and `additional_text`, so
 * a passive we write carries them too.
 */
final class DocumentEditor
{
    private const int MIN_LEVEL = 0;
    private const int MAX_LEVEL = 100;

    public function allocatePassive(BuildDocument $document, string $id): BuildDocument
    {
        if (null !== $this->indexOfPassive($document, $id)) {
            return $document;
        }

        $passives = $document->passives;
        $passives[] = ['id' => $id, 'level_interval' => [1, self::MAX_LEVEL], 'additional_text' => ''];

        return $this->withPassives($document, $passives);
    }

    public function deallocatePassive(BuildDocument $document, string $id): BuildDocument
    {
        $index = $this->indexOfPassive($document, $id);

        if (null === $index) {
            return $document;
        }

        $passives = $document->passives;
        unset($passives[$index]);

        return $this->withPassives($document, array_values($passives));
    }

    public function setPassiveLevelInterval(BuildDocument $document, string $id, int $from, int $to): BuildDocument
    {
        $index = $this->indexOfPassive($document, $id) ?? throw InvalidEditCommand::noSuchEntry('passive "'.$id.'"');

        $passives = $document->passives;
        $passives[$index]['level_interval'] = $this->levelInterval($from, $to);

        return $this->withPassives($document, $passives);
    }

    /**
     * @return array{int, int}
     */
    private function levelInterval(int $from, int $to): array
    {
        if ($from < self::MIN_LEVEL || $to > self::MAX_LEVEL || $from > $to) {
            throw InvalidEditCommand::outOfRange('A level interval');
        }

        return [$from, $to];
    }

    private function indexOfPassive(BuildDocument $document, string $id): ?int
    {
        foreach ($document->passives as $index => $passive) {
            if (($passive['id'] ?? null) === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $passives
     */
    private function withPassives(BuildDocument $document, array $passives): BuildDocument
    {
        return new BuildDocument(
            name: $document->name,
            author: $document->author,
            link: $document->link,
            description: $document->description,
            ascendancy: $document->ascendancy,
            passives: $passives,
            skills: $document->skills,
            inventorySlots: $document->inventorySlots,
        );
    }
}
