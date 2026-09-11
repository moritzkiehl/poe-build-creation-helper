<?php

declare(strict_types=1);

namespace App\Interchange;

final class BuildDocumentReader
{
    public function read(string $json): BuildDocument
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw InvalidBuildDocument::notJson($e);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw InvalidBuildDocument::notAnObject();
        }

        return new BuildDocument(
            name: $this->requiredString($data, 'name'),
            author: $this->optionalString($data, 'author'),
            link: $this->optionalString($data, 'link'),
            description: $this->optionalString($data, 'description'),
            ascendancy: $this->optionalString($data, 'ascendancy'),
            passives: $this->objectList($data, 'passives'),
            skills: $this->objectList($data, 'skills'),
            inventorySlots: $this->objectList($data, 'inventory_slots'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredString(array $data, string $field): string
    {
        if (!array_key_exists($field, $data)) {
            throw InvalidBuildDocument::missingField($field);
        }

        if (!is_string($data[$field])) {
            throw InvalidBuildDocument::wrongType($field, 'a string', get_debug_type($data[$field]));
        }

        return $data[$field];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optionalString(array $data, string $field): ?string
    {
        if (!array_key_exists($field, $data) || null === $data[$field]) {
            return null;
        }

        return $this->requiredString($data, $field);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function objectList(array $data, string $field): array
    {
        if (!array_key_exists($field, $data)) {
            return [];
        }

        if (!is_array($data[$field]) || !array_is_list($data[$field])) {
            throw InvalidBuildDocument::wrongType($field, 'a list', get_debug_type($data[$field]));
        }

        foreach ($data[$field] as $index => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw InvalidBuildDocument::wrongType($field.'['.$index.']', 'an object', get_debug_type($entry));
            }
        }

        /** @var list<array<string, mixed>> */
        return $data[$field];
    }
}
