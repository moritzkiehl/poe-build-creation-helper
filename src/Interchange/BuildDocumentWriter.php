<?php

declare(strict_types=1);

namespace App\Interchange;

final class BuildDocumentWriter
{
    public function write(BuildDocument $document): string
    {
        $data = ['name' => $document->name];

        foreach (['author' => $document->author, 'link' => $document->link, 'description' => $document->description, 'ascendancy' => $document->ascendancy] as $field => $value) {
            if (null !== $value) {
                $data[$field] = $value;
            }
        }

        foreach (['passives' => $document->passives, 'skills' => $document->skills, 'inventory_slots' => $document->inventorySlots] as $field => $value) {
            if ([] !== $value) {
                $data[$field] = $value;
            }
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
