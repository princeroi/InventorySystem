<?php

namespace App\Filament\Resources\Restocks\Pages;

use App\Filament\Resources\Restocks\RestockResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRestock extends EditRecord
{
    protected static string $resource = RestockResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $grouped = [];

        foreach ($this->record->items as $restockItem) {
            $itemId = $restockItem->item_id;

            if (!isset($grouped[$itemId])) {
                $grouped[$itemId] = [
                    'item_id' => $itemId,
                    'sizes'   => [],
                ];
            }

            $grouped[$itemId]['sizes'][] = [
                'size'     => $restockItem->size,
                'quantity' => $restockItem->quantity,
            ];
        }

        $data['items'] = array_values($grouped);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['items']);
        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->record;

        // Delete old items and re-insert fresh
        $record->items()->delete();

        foreach ($this->data['items'] ?? [] as $itemRow) {
            $itemId = $itemRow['item_id'] ?? null;
            if (!$itemId) continue;

            foreach ($itemRow['sizes'] ?? [] as $sizeRow) {
                $size     = $sizeRow['size'] ?? null;
                $quantity = $sizeRow['quantity'] ?? null;

                if (!$size || !$quantity) continue;

                RestockItem::create([
                    'restock_id' => $record->id,
                    'item_id'    => $itemId,
                    'size'       => $size,
                    'quantity'   => $quantity,
                ]);
            }
        }
    }
}

