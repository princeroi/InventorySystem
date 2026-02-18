<?php

namespace App\Filament\Resources\Restocks\Pages;

use App\Filament\Resources\Restocks\RestockResource;
use App\Models\Restock;
use App\Models\RestockItem;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRestocks extends ListRecords
{
    protected static string $resource = RestockResource::class;
    public array $cachedItems = []; 

    protected function getHeaderActions(): array
    {
        return [
           CreateAction::make()
            ->mutateFormDataUsing(function (array $data) use (&$cachedItems): array {
                $cachedItems = $data['items'] ?? [];
                unset($data['items']);
                return $data;
            })
            ->after(function ($record) use (&$cachedItems): void {
                foreach ($cachedItems as $itemRow) {
                    $itemId = $itemRow['item_id'] ?? null;
                    if (! $itemId) continue;

                    foreach ($itemRow['sizes'] ?? [] as $sizeRow) {
                        $size     = $sizeRow['size'] ?? null;
                        $quantity = $sizeRow['quantity'] ?? null;

                        if (! $size || ! $quantity) continue;

                        \App\Models\RestockItem::create([
                            'restock_id' => $record->id,
                            'item_id'    => $itemId,
                            'size'       => $size,
                            'quantity'   => $quantity,
                        ]);
                    }
                }

                // Add stock if created with delivered status
                if ($record->status === 'delivered') {
                    $record->refresh(); // ensure items are loaded fresh

                    foreach ($record->items as $restockItem) {
                        $variant = \App\Models\ItemVariant::where('item_id', $restockItem->item_id)
                            ->where('size_label', $restockItem->size)
                            ->first();

                        if ($variant) {
                            $variant->increment('quantity', $restockItem->quantity);
                        }
                    }
                }
            }),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->badge(Restock::count()),

            'pending' => Tab::make('Pending')
                ->badge(Restock::where('status', 'pending')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),

            'delivered' => Tab::make('Delivered')
                ->badge(Restock::where('status', 'delivered')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'delivered')),

            'returned' => Tab::make('Returned')
                ->badge(Restock::where('status', 'returned')->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'returned')),

            'cancelled' => Tab::make('Cancelled')
                ->badge(Restock::where('status', 'cancelled')->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'cancelled')),
        ];
    }
}