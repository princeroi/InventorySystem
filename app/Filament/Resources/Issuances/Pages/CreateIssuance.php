<?php

namespace App\Filament\Resources\Issuances\Pages;

use App\Filament\Resources\Issuances\IssuanceResource;
use App\Models\ItemVariant;
use App\Models\Item;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;          // ← ADD THIS
use Illuminate\Validation\ValidationException;

class CreateIssuance extends CreateRecord
{
    protected static string $resource = IssuanceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (in_array($data['status'], ['issued', 'released'])) {
            $insufficientItems = $this->getInsufficientStockItems($data['items'] ?? []);

            if (!empty($insufficientItems)) {
                $lines = implode(', ', $insufficientItems);

                Notification::make()
                    ->title('Insufficient Stock')
                    ->body("Cannot save as \"{$data['status']}\". Stock too low for: {$lines}")
                    ->danger()
                    ->persistent()
                    ->send();

                throw ValidationException::withMessages([
                    'items' => "Insufficient stock for: {$lines}",
                ]);
            }
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->record->fresh(['items']);

        // Deduct stock when created as released or issued
        if (in_array($record->status, ['released', 'issued'])) {
            foreach ($record->items as $issuanceItem) {
                $variant = ItemVariant::where('item_id', $issuanceItem->item_id)
                    ->where('size_label', $issuanceItem->size)
                    ->first();

                if ($variant) {
                    $variant->decrement('quantity', $issuanceItem->quantity);
                }
            }

            Notification::make()
                ->title('Stock deducted successfully.')
                ->success()
                ->send();
        }

        // Restore stock when created as returned
        if ($record->status === 'returned') {
            foreach ($record->items as $issuanceItem) {
                $variant = ItemVariant::where('item_id', $issuanceItem->item_id)
                    ->where('size_label', $issuanceItem->size)
                    ->first();

                if ($variant) {
                    $variant->increment('quantity', $issuanceItem->quantity);
                }
            }

            Notification::make()
                ->title('Stock restored successfully.')
                ->success()
                ->send();
        }
    }

    protected function getInsufficientStockItems(array $items): array
    {
        $errors = [];

        foreach ($items as $item) {
            $itemId   = $item['item_id'] ?? null;
            $size     = $item['size'] ?? null;
            $quantity = (int) ($item['quantity'] ?? 0);

            if (!$itemId || !$size || $quantity < 1) continue;

            $variant  = ItemVariant::where('item_id', $itemId)
                ->where('size_label', $size)
                ->first();

            $stock    = $variant?->quantity ?? 0;
            $itemName = Item::find($itemId)?->name ?? "Item #{$itemId}";

            if ($quantity > $stock) {
                $errors[] = "{$itemName} ({$size}): needs {$quantity}, has {$stock}";
            }
        }

        return $errors;
    }
}