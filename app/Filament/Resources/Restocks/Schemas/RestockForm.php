<?php

namespace App\Filament\Resources\Restocks\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Placeholder;
use App\Models\ItemVariant;
use Illuminate\Support\HtmlString;
use Filament\Notifications\Notification;

class RestockForm
{
    /**
     * Always reads directly from the DB — no cache.
     * Ensures stock numbers are always accurate after every restock/delivery.
     */
    private static function variantsForItem(?int $itemId): array
    {
        if (! $itemId) {
            return [];
        }

        return ItemVariant::where('item_id', $itemId)
            ->get(['id', 'item_id', 'size_label', 'quantity'])
            ->keyBy('size_label')
            ->toArray();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('supplier_name')
                    ->label('Supplier Name')
                    ->required(),

                TextInput::make('ordered_by')
                    ->label('Ordered By')
                    ->required(),

                Select::make('status')
                    ->options([
                        'pending'   => 'Pending',
                        'delivered' => 'Delivered',
                        'partial'   => 'Partial',
                        'returned'  => 'Returned',
                        'cancelled' => 'Cancelled',
                    ])
                    ->default('pending')
                    ->live()
                    ->afterStateUpdated(function (callable $set, $state) {
                        $now = now()->toDateString();
                        match ($state) {
                            'pending'   => $set('ordered_at', $now),
                            'delivered' => $set('delivered_at', $now),
                            'partial'   => $set('partial_at', $now),
                            'returned'  => $set('returned_at', $now),
                            'cancelled' => $set('cancelled_at', $now),
                            default     => null,
                        };
                    })
                    ->required(),

                DatePicker::make('ordered_at')
                    ->label('Ordered Date')
                    ->default(today())
                    ->visible(fn (callable $get) => $get('status') === 'pending')
                    ->required(fn (callable $get) => $get('status') === 'pending'),

                DatePicker::make('delivered_at')
                    ->label('Delivered Date')
                    ->visible(fn (callable $get) => $get('status') === 'delivered')
                    ->required(fn (callable $get) => $get('status') === 'delivered'),

                DatePicker::make('partial_at')
                    ->label('Partial Date')
                    ->visible(fn (callable $get) => $get('status') === 'partial')
                    ->required(fn (callable $get) => $get('status') === 'partial'),

                DatePicker::make('returned_at')
                    ->label('Returned Date')
                    ->visible(fn (callable $get) => $get('status') === 'returned')
                    ->required(fn (callable $get) => $get('status') === 'returned'),

                DatePicker::make('cancelled_at')
                    ->label('Cancelled Date')
                    ->visible(fn (callable $get) => $get('status') === 'cancelled')
                    ->required(fn (callable $get) => $get('status') === 'cancelled'),

                Textarea::make('note')
                    ->label('Note')
                    ->nullable()
                    ->columnSpanFull(),

                Repeater::make('items')
                    ->label('Items')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('item_id')
                            ->label('Item')
                            ->options(fn () => \App\Models\Item::pluck('name', 'id')->toArray())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (callable $get, callable $set, $state) {
                                if (! $state) return;

                                $allItems = $get('../../items') ?? [];

                                $duplicates = array_filter($allItems, fn ($row) =>
                                    isset($row['item_id']) && $row['item_id'] == $state
                                );

                                if (count($duplicates) > 1) {
                                    $set('item_id', null);
                                    $set('sizes', []);

                                    Notification::make()
                                        ->title('Item already added')
                                        ->body('This item already exists. Please add more sizes to the existing row instead.')
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                $set('sizes', [['size' => null, 'quantity' => null]]);
                            })
                            ->required()
                            ->columnSpanFull(),

                        Repeater::make('sizes')
                            ->label('Sizes & Quantities')
                            ->columnSpanFull()
                            ->hidden(fn (callable $get) => ! $get('item_id'))
                            ->columns(2)
                            ->schema([
                                Select::make('size')
                                    ->label('Size')
                                    ->options(function (callable $get) {
                                        $itemId = $get('../../item_id');

                                        // Always live from DB — no cache
                                        $variants = self::variantsForItem($itemId);

                                        return collect($variants)
                                            ->mapWithKeys(fn ($v) => [
                                                $v['size_label'] => "{$v['size_label']} (stock: {$v['quantity']})"
                                            ])
                                            ->toArray();
                                    })
                                    ->live()
                                    ->afterStateUpdated(function (callable $get, callable $set, $state) {
                                        if (! $state) return;

                                        $sizes = $get('../../sizes') ?? [];

                                        $duplicateKeys = array_keys(array_filter($sizes, fn ($row) =>
                                            isset($row['size']) && $row['size'] === $state
                                        ));

                                        if (count($duplicateKeys) > 1) {
                                            $set('size', null);
                                            $set('quantity', null);

                                            Notification::make()
                                                ->title("Size '{$state}' is already selected")
                                                ->body('Please update the quantity on the existing row instead.')
                                                ->warning()
                                                ->send();
                                        }
                                    })
                                    ->required(),

                                TextInput::make('quantity')
                                    ->numeric()
                                    ->minValue(1)
                                    ->live(debounce: 500)
                                    ->required(),

                                Placeholder::make('current_stock_note')
                                    ->label('')
                                    ->columnSpanFull()
                                    ->content(function (callable $get): ?HtmlString {
                                        $itemId = $get('../../item_id');
                                        $size   = $get('size');

                                        if (! $itemId || ! $size) {
                                            return null;
                                        }

                                        // Always live from DB — no cache
                                        $variants = self::variantsForItem($itemId);

                                        if (! isset($variants[$size])) {
                                            return new HtmlString(
                                                "<div class='text-sm text-danger-600 bg-danger-50 border
                                                    border-danger-200 rounded-lg px-3 py-2 mt-1'>
                                                    ⚠️ Variant not found.
                                                </div>"
                                            );
                                        }

                                        $stock = $variants[$size]['quantity'] ?? 0;

                                        return new HtmlString(
                                            "<div class='text-sm text-gray-600 bg-gray-50 border
                                                border-gray-200 rounded-lg px-3 py-2 mt-1'>
                                                📦 Current stock: <strong>{$stock}</strong>
                                            </div>"
                                        );
                                    }),
                            ])
                            ->defaultItems(0)
                            ->minItems(0)
                            ->addActionLabel('Add Size'),
                    ])
                    ->columns(1)
                    ->required(),
            ]);
    }
}