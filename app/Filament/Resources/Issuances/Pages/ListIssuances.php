<?php

namespace App\Filament\Resources\Issuances\Pages;

use App\Filament\Resources\Issuances\IssuanceResource;
use App\Models\Issuance;
use App\Models\IssuanceItem;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ListIssuances extends ListRecords
{
    protected static string $resource = IssuanceResource::class;

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
        $rows = Issuance::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $total = array_sum($rows);

        return array_merge(
            ['all' => $total],
            $rows
        );
    }

    // -------------------------------------------------------------------------
    // Header Actions
    // -------------------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        $cachedItems = [];

        return [
            CreateAction::make()
                ->visible(fn () => $this->userCan('create issuance'))
                ->mutateFormDataUsing(function (array $data) use (&$cachedItems): array {
                    $cachedItems = $data['issuance_items'] ?? [];
                    unset($data['issuance_items']);
                    return $data;
                })
                ->after(function ($record) use (&$cachedItems): void {
                    // Bulk-insert all issuance items in one go instead of looping creates
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
                                'issuance_id' => $record->id,
                                'item_id'     => $itemId,
                                'size'        => $size,
                                'quantity'    => $quantity,
                                'created_at'  => $now,
                                'updated_at'  => $now,
                            ];
                        }
                    }

                    if (! empty($rows)) {
                        \App\Models\IssuanceItem::insert($rows);
                    }
                }),
        ];
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

            'released' => Tab::make('Released')
                ->badge($counts['released'] ?? 0)
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'released')),

            'issued' => Tab::make('Issued')
                ->badge($counts['issued'] ?? 0)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'issued')),

            'returned' => Tab::make('Returned')
                ->badge($counts['returned'] ?? 0)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'returned')),

            'cancelled' => Tab::make('Cancelled')
                ->badge($counts['cancelled'] ?? 0)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'cancelled')),
        ];
    }
}