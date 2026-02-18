<?php

namespace App\Filament\Resources\Restocks\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
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
use Filament\Schemas\Components\Grid;
use Illuminate\Support\HtmlString;

class RestocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('ordered_by')
                    ->label('Ordered By')
                    ->searchable(),

                TextColumn::make('ordered_at')
                    ->label('Ordered Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'delivered',
                        'danger'  => 'cancelled',
                        'info'    => 'returned',
                    ]),

                TextColumn::make('item_ids')
                    ->label('Items')
                    ->html(),

                TextColumn::make('note')
                    ->label('Note')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('deliver')
                        ->label('Mark as Delivered')
                        ->color('success')
                        ->icon('heroicon-s-check')
                        ->visible(fn ($record) => $record->status === 'pending')
                        ->action(function ($record) {
                            $record->update(['status' => 'delivered']);

                            foreach ($record->items as $restockItem) {
                                $variant = ItemVariant::where('item_id', $restockItem->item_id)
                                    ->where('size_label', $restockItem->size)
                                    ->first();
                                if ($variant) {
                                    $variant->increment('quantity', $restockItem->quantity);
                                }
                            }

                            Notification::make()
                                ->title('Restock delivered and stock updated.')
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation(),

                    Action::make('return')
                        ->label('Return')
                        ->color('warning')
                        ->icon('heroicon-s-arrow-path')
                        ->visible(fn ($record) => $record->status === 'delivered')
                        ->modalHeading('Return Restock')
                        ->modalDescription('Review the items being returned. You can adjust the quantity for each item before confirming.')
                        ->modalSubmitActionLabel('Confirm Return')
                        ->form(function ($record): array {
                            $fields = [];

                            foreach ($record->items as $index => $restockItem) {
                                $itemName = $restockItem->item?->name ?? "Item #{$restockItem->item_id}";
                                $size     = $restockItem->size;
                                $label    = $size ? "{$itemName} ({$size})" : $itemName;

                                $fields[] = Placeholder::make("label_{$index}")
                                    ->label('')
                                    ->content(new HtmlString(
                                        "<div class='text-sm font-semibold text-gray-700 border-b pb-1 mb-1'>"
                                        . e($label)
                                        . "</div>"
                                    ));

                                $fields[] = TextInput::make("quantities.{$index}")
                                    ->label("Quantity to Return")
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue($restockItem->quantity)
                                    ->default($restockItem->quantity)
                                    ->suffix("/ {$restockItem->quantity} delivered")
                                    ->helperText("Max: {$restockItem->quantity}")
                                    ->required()
                                    ->dehydrated(true);
                            }

                            return $fields;
                        })
                        ->action(function ($record, array $data) {
                            $record->update(['status' => 'returned']);

                            $record->load('items');
                            $quantities = $data['quantities'] ?? [];

                            foreach ($record->items as $index => $restockItem) {
                                $quantityToReturn = (int) ($quantities[$index] ?? $restockItem->quantity);

                                if ($quantityToReturn <= 0) continue;

                                $variant = ItemVariant::where('item_id', $restockItem->item_id)
                                    ->where('size_label', $restockItem->size)
                                    ->first();

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
                        ->visible(fn ($record) => $record->status === 'pending')
                        ->action(function ($record) {
                            $record->update(['status' => 'cancelled']);

                            Notification::make()
                                ->title('Restock cancelled.')
                                ->danger()
                                ->send();
                        })
                        ->requiresConfirmation(),

                    EditAction::make()
                        ->visible(fn ($record) => $record->status === 'pending')
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

                            foreach ($cachedItems ?? [] as $itemRow) {
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
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_deliver')
                        ->label('Deliver Selected')
                        ->color('success')
                        ->icon('heroicon-s-check')
                        ->modalDescription(fn ($records) =>
                            'Only pending records will be delivered. ' .
                            $records->where('status', '!=', 'pending')->count() . ' record(s) will be skipped.'
                        )
                        ->action(function ($records) {
                            $delivered = 0;

                            foreach ($records->where('status', 'pending') as $record) {
                                $record->update(['status' => 'delivered']);

                                foreach ($record->items as $restockItem) {
                                    $variant = ItemVariant::where('item_id', $restockItem->item_id)
                                        ->where('size_label', $restockItem->size)
                                        ->first();
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