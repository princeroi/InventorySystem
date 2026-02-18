<?php

namespace App\Filament\Resources\Restocks\Pages;

use App\Filament\Resources\Restocks\RestockResource;
use Filament\Resources\Pages\CreateRecord;
use App\Models\RestockItem;
use App\Models\ItemVariant;

class CreateRestock extends CreateRecord
{
    protected static string $resource = RestockResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Strip items so Filament does not touch restock_items at all
        unset($data['items']);
        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->record;

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

        // Handle stock changes based on status
        $record->refresh();

        if ($record->status === 'delivered') {
            foreach ($record->items as $restockItem) {
                $variant = ItemVariant::where('item_id', $restockItem->item_id)
                    ->where('size_label', $restockItem->size)
                    ->first();
                if ($variant) {
                    $variant->increment('quantity', $restockItem->quantity);
                }
            }
        }

        if ($record->status === 'returned') {
            foreach ($record->items as $restockItem) {
                $variant = ItemVariant::where('item_id', $restockItem->item_id)
                    ->where('size_label', $restockItem->size)
                    ->first();
                if ($variant) {
                    $variant->decrement('quantity', $restockItem->quantity);
                }
            }
        }
    }
}
