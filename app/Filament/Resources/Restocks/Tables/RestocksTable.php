<?php

namespace App\Filament\Resources\Restocks\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use App\Models\ItemVariant;
use Filament\Actions\ActionGroup;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Auth;

class RestocksTable
{
    // -------------------------------------------------------------------------
    // Permission Helper
    // -------------------------------------------------------------------------

    private static function userCan(string $permission): bool
    {
        return Auth::user()?->can($permission) ?? false;
    }

    // -------------------------------------------------------------------------
    // Variant Map
    // -------------------------------------------------------------------------

    /**
     * Build a keyed map of "item_id:size_label" => ItemVariant in one query.
     */
    private static function variantMap(iterable $items): array
    {
        $pairs = collect($items)->map(fn ($i) => [
            'item_id' => $i->item_id,
            'size'    => $i->size,
        ])->unique()->values();

        if ($pairs->isEmpty()) {
            return [];
        }

        $variants = ItemVariant::query()
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
    // Table Configuration
    // -------------------------------------------------------------------------

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(function ($query) {
                $query->orderByRaw("
                    COALESCE(
                        CASE status
                            WHEN 'pending'   THEN ordered_at
                            WHEN 'delivered' THEN delivered_at
                            WHEN 'partial'   THEN partial_at
                            WHEN 'returned'  THEN returned_at
                            WHEN 'cancelled' THEN cancelled_at
                            ELSE NULL
                        END,
                        DATE(created_at)
                    ) DESC
                ")
                ->orderBy('updated_at', 'desc');
            })
            ->columns([
                TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('ordered_by')
                    ->label('Ordered By')
                    ->searchable(),

                TextColumn::make('status_date')
                    ->label('Date')
                    ->getStateUsing(fn ($record) => match ($record->status) {
                        'pending'   => $record->ordered_at,
                        'delivered' => $record->delivered_at,
                        'partial'   => $record->partial_at,
                        'returned'  => $record->returned_at,
                        'cancelled' => $record->cancelled_at,
                        default     => null,
                    })
                    ->formatStateUsing(fn ($state) => $state
                        ? \Carbon\Carbon::parse($state)->format('M d, Y')
                        : '—'
                    )
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => 'pending',
                        'primary' => 'partial',
                        'success' => 'delivered',
                        'danger'  => 'cancelled',
                        'info'    => 'returned',
                    ]),

                TextColumn::make('item_ids')
                    ->label('Items')
                    ->html(),

                TextColumn::make('note')
                    ->label('Note')
                    ->formatStateUsing(fn ($state) => $state ?: 'No Note')
                    ->limit(50),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('deliver')
                        ->label('Mark as Delivered')
                        ->color('success')
                        ->icon('heroicon-s-check')
                        ->visible(fn ($record) =>
                            in_array($record->status, ['pending', 'partial']) &&
                            self::userCan('deliver restock')
                        )
                        ->modalHeading('Mark as Delivered')
                        ->modalDescription('Enter the actual quantity delivered for each item.')
                        ->modalSubmitActionLabel('Confirm Delivery')
                        ->form(function ($record): array {
                            $fields = [];

                            foreach ($record->items as $index => $restockItem) {
                                $itemName     = $restockItem->item?->name ?? "Item #{$restockItem->item_id}";
                                $size         = $restockItem->size;
                                $label        = $size ? "{$itemName} ({$size})" : $itemName;
                                $maxQty       = $restockItem->remaining_quantity ?? $restockItem->quantity;
                                $totalOrdered = $restockItem->quantity;

                                $fields[] = TextInput::make("quantities.{$index}")
                                    ->label($label)
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue($maxQty)
                                    ->default($maxQty)
                                    ->suffix("/ {$maxQty} remaining (ordered: {$totalOrdered})")
                                    ->helperText("Max deliverable: {$maxQty}")
                                    ->required()
                                    ->dehydrated(true);
                            }

                            return $fields;
                        })
                        ->action(function ($record, array $data) {
                            $quantities   = $data['quantities'] ?? [];
                            $allDelivered = true;

                            $record->load('items');
                            $variantMap = self::variantMap($record->items);

                            foreach ($record->items as $index => $restockItem) {
                                $deliveredQty = (int) ($quantities[$index] ?? 0);
                                $maxQty       = $restockItem->remaining_quantity ?? $restockItem->quantity;
                                $delivered    = min($deliveredQty, $maxQty);
                                $newRemaining = $maxQty - $delivered;

                                $restockItem->update([
                                    'delivered_quantity' => ($restockItem->delivered_quantity ?? 0) + $delivered,
                                    'remaining_quantity' => $newRemaining,
                                ]);

                                if ($newRemaining > 0) {
                                    $allDelivered = false;
                                }

                                $key     = "{$restockItem->item_id}:{$restockItem->size}";
                                $variant = $variantMap[$key] ?? null;

                                if ($variant && $delivered > 0) {
                                    $variant->increment('quantity', $delivered);
                                }
                            }

                            $record->update([
                                'status' => $allDelivered ? 'delivered' : 'partial',
                            ]);

                            $msg = $allDelivered
                                ? 'Fully delivered and stock updated.'
                                : 'Partially delivered and stock updated.';

                            Notification::make()->title($msg)->success()->send();
                        }),

                    Action::make('return')
                        ->label('Return')
                        ->color('warning')
                        ->icon('heroicon-s-arrow-path')
                        ->visible(fn ($record) =>
                            in_array($record->status, ['delivered', 'partial']) &&
                            self::userCan('return restock')
                        )
                        ->modalHeading('Return Restock')
                        ->modalDescription('Review the items being returned. You can adjust the quantity for each item before confirming.')
                        ->modalSubmitActionLabel('Confirm Return')
                        ->form(function ($record): array {
                            $fields = [];

                            foreach ($record->items as $index => $restockItem) {
                                $itemName = $restockItem->item?->name ?? "Item #{$restockItem->item_id}";
                                $size     = $restockItem->size;
                                $label    = $size ? "{$itemName} ({$size})" : $itemName;

                                // ✅ FIX — only allow returning what was actually delivered
                                // For partial: delivered_quantity (not full quantity)
                                // For delivered: full quantity
                                $maxReturnable = $restockItem->delivered_quantity ?? $restockItem->quantity;

                                // Skip items that have nothing delivered yet
                                if ($maxReturnable <= 0) {
                                    continue;
                                }

                                $fields[] = Placeholder::make("label_{$index}")
                                    ->label('')
                                    ->content(new HtmlString(
                                        "<div class='text-sm font-semibold text-gray-700 border-b pb-1 mb-1'>"
                                        . e($label)
                                        . ($restockItem->delivered_quantity !== null && $restockItem->delivered_quantity < $restockItem->quantity
                                            ? " <span class='text-warning-600 text-xs font-normal'>(partial: {$restockItem->delivered_quantity} of {$restockItem->quantity} delivered)</span>"
                                            : ""
                                        )
                                        . "</div>"
                                    ));

                                $fields[] = TextInput::make("quantities.{$index}")
                                    ->label("Quantity to Return")
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue($maxReturnable)          // ✅ only delivered qty
                                    ->default($maxReturnable)           // ✅ default to delivered qty
                                    ->suffix("/ {$maxReturnable} delivered")
                                    ->helperText("Max returnable: {$maxReturnable}")
                                    ->required()
                                    ->dehydrated(true);
                            }

                            // If no items have been delivered yet, show a notice
                            if (count($fields) === 0) {
                                $fields[] = Placeholder::make('no_delivered')
                                    ->label('')
                                    ->content(new HtmlString(
                                        "<div class='text-sm text-warning-700 bg-warning-50 border border-warning-200 rounded-lg px-4 py-3'>
                                            ⚠️ No items have been delivered yet. Nothing to return.
                                        </div>"
                                    ));
                            }

                            return $fields;
                        })
                        ->action(function ($record, array $data) {
                            $record->update(['status' => 'returned']);

                            $record->load('items');
                            $quantities = $data['quantities'] ?? [];
                            $variantMap = self::variantMap($record->items);

                            foreach ($record->items as $index => $restockItem) {
                                // ✅ Only return what was actually delivered
                                $maxReturnable    = $restockItem->delivered_quantity ?? $restockItem->quantity;
                                $quantityToReturn = (int) ($quantities[$index] ?? $maxReturnable);

                                // ✅ Clamp to max returnable — never exceed delivered qty
                                $quantityToReturn = min($quantityToReturn, $maxReturnable);

                                if ($quantityToReturn <= 0) continue;

                                $key     = "{$restockItem->item_id}:{$restockItem->size}";
                                $variant = $variantMap[$key] ?? null;

                                if ($variant) {
                                    $variant->decrement('quantity', $quantityToReturn);
                                }
                            }

                            Notification::make()->title('Restock returned and stock adjusted.')->warning()->send();
                        }),

                    Action::make('cancel')
                        ->label('Cancel')
                        ->color('danger')
                        ->icon('heroicon-s-x-mark')
                        ->visible(fn ($record) =>
                            $record->status === 'pending' &&
                            self::userCan('cancel restock')
                        )
                        ->action(function ($record) {
                            $record->update(['status' => 'cancelled']);

                            Notification::make()
                                ->title('Restock cancelled.')
                                ->danger()
                                ->send();
                        })
                        ->requiresConfirmation(),

                    EditAction::make()
                        ->visible(fn ($record) =>
                            $record->status === 'pending' &&
                            self::userCan('update restock')
                        )
                        ->fillForm(function ($record): array {
                            $grouped = [];

                            foreach ($record->items as $restockItem) {
                                $itemId = $restockItem->item_id;

                                if (! isset($grouped[$itemId])) {
                                    $grouped[$itemId] = ['item_id' => $itemId, 'sizes' => []];
                                }

                                $grouped[$itemId]['sizes'][] = [
                                    'size'     => $restockItem->size,
                                    'quantity' => $restockItem->quantity,
                                ];
                            }

                            return array_merge($record->toArray(), ['items' => array_values($grouped)]);
                        })
                        ->mutateFormDataUsing(function (array $data) use (&$cachedItems): array {
                            $cachedItems = $data['items'] ?? [];
                            unset($data['items']);
                            return $data;
                        })
                        ->after(function ($record) use (&$cachedItems): void {
                            $record->items()->delete();

                            // Bulk insert instead of looped create
                            $rows = [];
                            $now  = now();

                            foreach ($cachedItems ?? [] as $itemRow) {
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
                                \App\Models\RestockItem::insert($rows);
                            }
                        }),
                ]),

                Action::make('view_logs')
                    ->label('View Logs')
                    ->icon('heroicon-s-clock')
                    ->color('gray')
                    ->visible(fn () => self::userCan('view-any restock'))
                    ->modalHeading('Restock Activity Log')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('lg')
                    ->form(function ($record): array {
                        // logs already eager-loaded via getEloquentQuery()
                        $logs = $record->logs;

                        if ($logs->isEmpty()) {
                            return [
                                Placeholder::make('no_logs')
                                    ->label('')
                                    ->content(new HtmlString("
                                        <div style='display:flex; flex-direction:column; align-items:center; justify-content:center; padding:32px 0; color:#9ca3af;'>
                                            <svg style='width:40px;height:40px;margin-bottom:8px;' fill='none' stroke='currentColor' viewBox='0 0 24 24'>
                                                <path stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'/>
                                            </svg>
                                            <span style='font-size:13px;'>No activity logs found.</span>
                                        </div>
                                    ")),
                            ];
                        }

                        $config = [
                            'created'   => ['color' => '#6366f1', 'bg' => '#eef2ff', 'border' => '#c7d2fe', 'label' => 'Created',   'icon' => 'M12 4v16m8-8H4'],
                            'pending'   => ['color' => '#d97706', 'bg' => '#fffbeb', 'border' => '#fde68a', 'label' => 'Pending',   'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
                            'partial'   => ['color' => '#7c3aed', 'bg' => '#f5f3ff', 'border' => '#ddd6fe', 'label' => 'Partial',   'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
                            'delivered' => ['color' => '#059669', 'bg' => '#ecfdf5', 'border' => '#a7f3d0', 'label' => 'Delivered', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                            'returned'  => ['color' => '#ea580c', 'bg' => '#fff7ed', 'border' => '#fed7aa', 'label' => 'Returned',  'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
                            'cancelled' => ['color' => '#dc2626', 'bg' => '#fef2f2', 'border' => '#fecaca', 'label' => 'Cancelled', 'icon' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z'],
                        ];

                        $logCount = $logs->count();
                        $fields   = [];

                        foreach ($logs as $i => $log) {
                            $c      = $config[$log->action] ?? $config['created'];
                            $date   = \Carbon\Carbon::parse($log->created_at)->format('M d, Y');
                            $time   = \Carbon\Carbon::parse($log->created_at)->format('h:i A');
                            $isLast = $i === $logCount - 1;
                            $line   = ! $isLast ? "<div style='width:2px; flex:1; background:#e5e7eb; margin-top:4px; min-height:20px;'></div>" : '';
                            $note   = $log->note ? "<div style='font-size:12px; color:#6b7280; margin-top:6px; padding-top:6px; border-top:1px solid {$c['border']};'>{$log->note}</div>" : '';
                            $pb     = $isLast ? '0' : '16px';

                            $fields[] = Placeholder::make("log_{$log->id}")
                                ->label('')
                                ->content(new HtmlString("
                                    <div style='display:flex; gap:16px;'>
                                        <div style='display:flex; flex-direction:column; align-items:center;'>
                                            <div style='width:36px; height:36px; background:{$c['bg']}; border:2px solid {$c['border']}; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0;'>
                                                <svg style='width:16px; height:16px;' fill='none' stroke='{$c['color']}' viewBox='0 0 24 24'>
                                                    <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='{$c['icon']}'/>
                                                </svg>
                                            </div>
                                            {$line}
                                        </div>
                                        <div style='flex:1; padding-bottom:{$pb};'>
                                            <div style='background:{$c['bg']}; border:1px solid {$c['border']}; border-radius:8px; padding:12px 14px;'>
                                                <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;'>
                                                    <span style='font-size:13px; font-weight:600; color:{$c['color']};'>{$c['label']}</span>
                                                    <span style='font-size:11px; color:#9ca3af;'>{$date} · {$time}</span>
                                                </div>
                                                <div style='font-size:12px; color:#6b7280;'>
                                                    By: <strong style='color:#374151;'>{$log->performed_by}</strong>
                                                </div>
                                                {$note}
                                            </div>
                                        </div>
                                    </div>
                                "));
                        }

                        return $fields;
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_deliver')
                        ->label('Deliver Selected')
                        ->color('success')
                        ->icon('heroicon-s-check')
                        ->visible(fn () => self::userCan('deliver restock'))
                        ->modalDescription(fn ($records) =>
                            'Only pending records will be delivered. ' .
                            $records->where('status', '!=', 'pending')->count() . ' record(s) will be skipped.'
                        )
                        ->action(function ($records) {
                            $pendingRecords = $records->where('status', 'pending');

                            // Single variant map for all records
                            $allItems   = $pendingRecords->flatMap(fn ($r) => $r->items);
                            $variantMap = self::variantMap($allItems);

                            $delivered = 0;

                            foreach ($pendingRecords as $record) {
                                $record->update(['status' => 'delivered']);

                                foreach ($record->items as $restockItem) {
                                    $key     = "{$restockItem->item_id}:{$restockItem->size}";
                                    $variant = $variantMap[$key] ?? null;

                                    if ($variant) {
                                        $variant->increment('quantity', $restockItem->quantity);
                                    }
                                }

                                $delivered++;
                            }

                            Notification::make()
                                ->title("{$delivered} restock(s) delivered and stock updated.")
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulk_cancel')
                        ->label('Cancel Selected')
                        ->color('danger')
                        ->icon('heroicon-s-x-mark')
                        ->visible(fn () => self::userCan('cancel restock'))
                        ->modalDescription(fn ($records) =>
                            'Only pending records will be cancelled. ' .
                            $records->where('status', '!=', 'pending')->count() . ' record(s) will be skipped.'
                        )
                        ->action(fn ($records) => $records
                            ->where('status', 'pending')
                            ->each->update(['status' => 'cancelled'])
                        )
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}