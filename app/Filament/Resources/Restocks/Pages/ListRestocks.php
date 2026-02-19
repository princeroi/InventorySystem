<?php

namespace App\Filament\Resources\Restocks\Pages;

use App\Filament\Resources\Restocks\RestockResource;
use App\Models\Restock;
use App\Models\RestockItem;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ListRestocks extends ListRecords
{
    protected static string $resource = RestockResource::class;

    // -------------------------------------------------------------------------
    // Permission Helper
    // -------------------------------------------------------------------------

    private function userCan(string $permission): bool
    {
        return Auth::user()?->can($permission) ?? false;
    }

    // -------------------------------------------------------------------------
    // Status Counts
    // -------------------------------------------------------------------------

    /**
     * Single grouped COUNT query instead of one per tab.
     */
    protected function getStatusCounts(): array
    {
        $rows = Restock::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return array_merge(['all' => array_sum($rows)], $rows);
    }

    // -------------------------------------------------------------------------
    // Header Actions
    // -------------------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        $cachedItems = [];

        return [
            CreateAction::make()
                ->visible(fn () => $this->userCan('create restock'))
                ->mutateFormDataUsing(function (array $data) use (&$cachedItems): array {
                    $cachedItems = $data['items'] ?? [];
                    unset($data['items']);
                    return $data;
                })
                ->after(function ($record) use (&$cachedItems): void {
                    // Bulk-insert all restock items in one query
                    $rows = [];
                    $now  = now();

                    foreach ($cachedItems as $itemRow) {
                        $itemId = $itemRow['item_id'] ?? null;
                        if (! $itemId) continue;

                        foreach ($itemRow['sizes'] ?? [] as $sizeRow) {
                            $size     = $sizeRow['size'] ?? null;
                            $quantity = $sizeRow['quantity'] ?? null;

                            if (! $size || ! $quantity) continue;

                            $rows[] = [
                                'restock_id' => $record->id,
                                'item_id'    => $itemId,
                                'size'       => $size,
                                'quantity'   => $quantity,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }

                    if (! empty($rows)) {
                        RestockItem::insert($rows);
                    }

                    // Add stock if created with delivered status — batch variant lookup
                    if ($record->status === 'delivered') {
                        $record->refresh();

                        $items      = $record->items;
                        $variantMap = $this->variantMap($items);

                        foreach ($items as $restockItem) {
                            $key     = "{$restockItem->item_id}:{$restockItem->size}";
                            $variant = $variantMap[$key] ?? null;

                            if ($variant) {
                                $variant->increment('quantity', $restockItem->quantity);
                            }
                        }
                    }
                }),
        ];
    }

    // -------------------------------------------------------------------------
    // Variant Map
    // -------------------------------------------------------------------------

    /**
     * Build a keyed map of "item_id:size" => ItemVariant in one query.
     */
    private function variantMap(iterable $items): array
    {
        $pairs = collect($items)->map(fn ($i) => [
            'item_id' => $i->item_id,
            'size'    => $i->size,
        ])->unique()->values();

        if ($pairs->isEmpty()) {
            return [];
        }

        $variants = \App\Models\ItemVariant::query()
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as $p) {
                    $q->orWhere(function ($inner) use ($p) {
                        $inner->where('item_id', $p['item_id'])
                              ->where('size_label', $p['size']);
                    });
                }
            })
            ->get();

        $map = [];
        foreach ($variants as $v) {
            $map["{$v->item_id}:{$v->size_label}"] = $v;
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // Tabs
    // -------------------------------------------------------------------------

    public function getTabs(): array
    {
        $counts = $this->getStatusCounts();

        return [
            'all' => Tab::make('All')
                ->badge($counts['all'] ?? 0),

            'pending' => Tab::make('Pending')
                ->badge($counts['pending'] ?? 0)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),

            'delivered' => Tab::make('Delivered')
                ->badge($counts['delivered'] ?? 0)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'delivered')),

            'returned' => Tab::make('Returned')
                ->badge($counts['returned'] ?? 0)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'returned')),

            'cancelled' => Tab::make('Cancelled')
                ->badge($counts['cancelled'] ?? 0)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'cancelled')),

            'partial' => Tab::make('Partial')
                ->badge($counts['partial'] ?? 0)
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'partial')),
        ];
    }
}