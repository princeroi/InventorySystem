<?php

namespace App\Filament\Resources\Restocks\Pages;

use App\Filament\Resources\Restocks\RestockResource;
use App\Models\Restock;
use App\Models\RestockItem;
use App\Models\ItemVariant;
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
                    // Build raw rows for bulk insert
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
                                'quantity'   => (int) $quantity,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }

                    if (! empty($rows)) {
                        // insert() bypasses model events — stock is handled manually below
                        RestockItem::insert($rows);

                        // ── Add stock if created directly as 'delivered' ───────
                        // Since insert() skips model events, we handle stock
                        // increment here when the restock is saved as delivered
                        // right from the create modal.
                        if ($record->status === 'delivered') {
                            $this->incrementStockForRows($rows);
                        }
                    }
                }),
        ];
    }

    // -------------------------------------------------------------------------
    // Stock Increment Helper
    // -------------------------------------------------------------------------

    /**
     * Increment stock for a set of raw item rows (as used after insert()).
     * Groups by item_id + size to minimise queries.
     *
     * @param array $rows  Array of ['item_id' => ..., 'size' => ..., 'quantity' => ...]
     */
    private function incrementStockForRows(array $rows): void
    {
        // Aggregate quantities per item_id + size in case of duplicates
        $aggregated = [];
        foreach ($rows as $row) {
            $key = "{$row['item_id']}:{$row['size']}";
            $aggregated[$key] = [
                'item_id'  => $row['item_id'],
                'size'     => $row['size'],
                'quantity' => ($aggregated[$key]['quantity'] ?? 0) + (int) $row['quantity'],
            ];
        }

        foreach ($aggregated as $entry) {
            $variant = ItemVariant::where('item_id', $entry['item_id'])
                ->where('size_label', $entry['size'])
                ->first();

            $variant?->increment('quantity', $entry['quantity']);
        }
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