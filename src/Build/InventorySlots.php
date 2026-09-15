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

    /** @var list<array{id: string, x: int, key: string, label: string}>|null */
    private ?array $positions = null;

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

    /**
     * Every place an item can go: one per id, or one per position for an id
     * that holds several — the belt strip flasks and charms share.
     *
     * @return list<array{id: string, x: int, key: string, label: string}>
     */
    public function positions(): array
    {
        if (null !== $this->positions) {
            return $this->positions;
        }

        $parsed = Yaml::parseFile($this->file);
        $entries = \is_array($parsed) ? ($parsed['inventory_slots'] ?? []) : [];
        $positions = [];

        foreach ((array) $entries as $slot) {
            if (!\is_array($slot) || !\is_string($slot['id'] ?? null) || !\is_string($slot['label'] ?? null)) {
                continue;
            }

            $listed = \is_array($slot['positions'] ?? null) ? $slot['positions'] : [['x' => 0, 'label' => $slot['label']]];

            foreach ($listed as $position) {
                if (\is_array($position) && \is_int($position['x'] ?? null) && \is_string($position['label'] ?? null)) {
                    $positions[] = ['id' => $slot['id'], 'x' => $position['x'], 'key' => $slot['id'].'@'.$position['x'], 'label' => $position['label']];
                }
            }
        }

        return $this->positions = $positions;
    }

    public function hasPosition(string $inventoryId, int $x): bool
    {
        foreach ($this->positions() as $position) {
            if ($position['id'] === $inventoryId && $position['x'] === $x) {
                return true;
            }
        }

        return false;
    }
}
