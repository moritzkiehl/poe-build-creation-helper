<?php

declare(strict_types=1);

namespace App\Build\Edit;

use App\Build\InventorySlots;
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

    public function __construct(private readonly InventorySlots $slots)
    {
    }

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

    public function addSkill(BuildDocument $document, string $gemId): BuildDocument
    {
        $skills = $document->skills;
        $skills[] = ['id' => $gemId, 'level_interval' => [1, self::MAX_LEVEL]];

        return $this->withSkills($document, $skills);
    }

    public function removeSkill(BuildDocument $document, int $index): BuildDocument
    {
        $skills = $document->skills;
        $this->mustHaveSkill($skills, $index);
        unset($skills[$index]);

        return $this->withSkills($document, array_values($skills));
    }

    public function setSkillLevelInterval(BuildDocument $document, int $index, int $from, int $to): BuildDocument
    {
        $skills = $document->skills;
        $this->mustHaveSkill($skills, $index);
        $skills[$index]['level_interval'] = $this->levelInterval($from, $to);

        return $this->withSkills($document, $skills);
    }

    public function addSupport(BuildDocument $document, int $skillIndex, string $supportId): BuildDocument
    {
        $skills = $document->skills;
        $this->mustHaveSkill($skills, $skillIndex);
        $supports = $this->supportsOf($skills[$skillIndex]);

        if (null !== $this->indexOfSupport($supports, $supportId)) {
            return $document;
        }

        $supports[] = ['id' => $supportId, 'level_interval' => [1, self::MAX_LEVEL]];
        $skills[$skillIndex]['support_skills'] = $supports;

        return $this->withSkills($document, $skills);
    }

    public function removeSupport(BuildDocument $document, int $skillIndex, string $supportId): BuildDocument
    {
        $skills = $document->skills;
        $this->mustHaveSkill($skills, $skillIndex);
        $supports = $this->supportsOf($skills[$skillIndex]);
        $index = $this->indexOfSupport($supports, $supportId);

        if (null === $index) {
            return $document;
        }

        unset($supports[$index]);
        $supports = array_values($supports);

        // A skill the game wrote with no supports carries no key at all.
        if ([] === $supports) {
            unset($skills[$skillIndex]['support_skills']);
        } else {
            $skills[$skillIndex]['support_skills'] = $supports;
        }

        return $this->withSkills($document, $skills);
    }

    public function setSupportLevelInterval(BuildDocument $document, int $skillIndex, string $supportId, int $from, int $to): BuildDocument
    {
        $skills = $document->skills;
        $this->mustHaveSkill($skills, $skillIndex);
        $supports = $this->supportsOf($skills[$skillIndex]);
        $index = $this->indexOfSupport($supports, $supportId) ?? throw InvalidEditCommand::noSuchEntry('support "'.$supportId.'"');

        $supports[$index]['level_interval'] = $this->levelInterval($from, $to);
        $skills[$skillIndex]['support_skills'] = $supports;

        return $this->withSkills($document, $skills);
    }

    public function setInventorySlot(BuildDocument $document, string $inventoryId, ?string $uniqueName, int $from, int $to, string $additionalText): BuildDocument
    {
        if (!$this->slots->isKnown($inventoryId)) {
            throw InvalidEditCommand::noSuchEntry('equipment slot "'.$inventoryId.'"');
        }

        $entry = [
            'inventory_id' => $inventoryId,
            'slot_x' => 0,
            'slot_y' => 0,
            'level_interval' => $this->levelInterval($from, $to),
            'additional_text' => $additionalText,
        ];

        if (null !== $uniqueName && '' !== $uniqueName) {
            $entry['unique_name'] = $uniqueName;
        }

        $slots = $document->inventorySlots;
        $index = $this->indexOfSlot($document, $inventoryId);

        if (null === $index) {
            $slots[] = $entry;
        } else {
            $slots[$index] = $entry;
        }

        return $this->withSlots($document, $slots);
    }

    public function clearInventorySlot(BuildDocument $document, string $inventoryId): BuildDocument
    {
        $index = $this->indexOfSlot($document, $inventoryId);

        if (null === $index) {
            return $document;
        }

        $slots = $document->inventorySlots;
        unset($slots[$index]);

        return $this->withSlots($document, array_values($slots));
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

    private function indexOfSlot(BuildDocument $document, string $inventoryId): ?int
    {
        foreach ($document->inventorySlots as $index => $slot) {
            if (($slot['inventory_id'] ?? null) === $inventoryId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $slots
     */
    private function withSlots(BuildDocument $document, array $slots): BuildDocument
    {
        return new BuildDocument(
            name: $document->name,
            author: $document->author,
            link: $document->link,
            description: $document->description,
            ascendancy: $document->ascendancy,
            passives: $document->passives,
            skills: $document->skills,
            inventorySlots: $slots,
        );
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

    /**
     * @param list<array<string, mixed>> $skills
     */
    private function mustHaveSkill(array $skills, int $index): void
    {
        if (!isset($skills[$index])) {
            throw InvalidEditCommand::noSuchEntry('skill #'.$index);
        }
    }

    /**
     * @param array<string, mixed> $skill
     *
     * @return list<array<string, mixed>>
     */
    private function supportsOf(array $skill): array
    {
        $supports = [];

        foreach ((array) ($skill['support_skills'] ?? []) as $support) {
            if (\is_array($support)) {
                /** @var array<string, mixed> $support */
                $supports[] = $support;
            }
        }

        return $supports;
    }

    /**
     * @param list<array<string, mixed>> $supports
     */
    private function indexOfSupport(array $supports, string $supportId): ?int
    {
        foreach ($supports as $index => $support) {
            if (($support['id'] ?? null) === $supportId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $skills
     */
    private function withSkills(BuildDocument $document, array $skills): BuildDocument
    {
        return new BuildDocument(
            name: $document->name,
            author: $document->author,
            link: $document->link,
            description: $document->description,
            ascendancy: $document->ascendancy,
            passives: $document->passives,
            skills: $skills,
            inventorySlots: $document->inventorySlots,
        );
    }
}
