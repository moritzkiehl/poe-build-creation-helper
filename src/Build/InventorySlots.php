<?php

declare(strict_types=1);

namespace App\Build;

use Symfony\Component\Yaml\Yaml;

/**
 * The `Inventories` ids a build may name, curated.
 *
 * Step 0 established that no upstream source carries this vocabulary, so it is
 * a file we maintain against real exported files rather than data we sync.
 */
final class InventorySlots
{
    /** @var list<array{id: string, label: string}>|null */
    private ?array $slots = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function all(): array
    {
        if (null !== $this->slots) {
            return $this->slots;
        }

        $parsed = Yaml::parseFile($this->file);
        $entries = \is_array($parsed) ? ($parsed['inventory_slots'] ?? []) : [];

        $slots = [];

        foreach ((array) $entries as $slot) {
            if (\is_array($slot) && \is_string($slot['id'] ?? null) && \is_string($slot['label'] ?? null)) {
                $slots[] = ['id' => $slot['id'], 'label' => $slot['label']];
            }
        }

        return $this->slots = $slots;
    }

    public function isKnown(string $inventoryId): bool
    {
        return \in_array($inventoryId, array_column($this->all(), 'id'), true);
    }
}
