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
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('supplier_name')
                    ->label('Supplier Name')
                    ->required(),

                TextInput::make('ordered_by')
                    ->label('Ordered By')
                    ->required(),

                DatePicker::make('ordered_at')
                    ->label('Ordered Date')
                    ->required()
                    ->default(today()),

                Select::make('status')
                    ->options([
                        'pending'   => 'Pending',
                        'delivered' => 'Delivered',
                        'returned'  => 'Returned',
                    ])
                    ->default('pending')
                    ->required(),

                Textarea::make('note')
                    ->label('Note')
                    ->nullable()
                    ->columnSpanFull(),

                Repeater::make('items')
                    ->label('Items')
                    ->schema([
                       Select::make('item_id')
                        ->label('Item')
                        ->options(fn () => \App\Models\Item::pluck('name', 'id')->toArray())
                        ->searchable()
                        ->preload()
                        ->reactive()
                        ->afterStateUpdated(function (callable $set, $state) {
                            $set('sizes', []);
                            if ($state) {
                                $set('sizes', [['size' => null, 'quantity' => null]]);
                            }
                        })
                        ->required()
                        ->columnSpanFull(),

                        Repeater::make('sizes')
                            ->label('Sizes & Quantities')
                            ->columnSpanFull()
                            ->hidden(fn (callable $get) => !$get('item_id'))
                            ->schema([
                                Select::make('size')
                                    ->label('Size')
                                    ->options(function (callable $get) {
                                        $itemId = $get('../../item_id');
                                        if (!$itemId) return [];
                                        return ItemVariant::where('item_id', $itemId)
                                            ->get()
                                            ->mapWithKeys(fn ($v) => [
                                                $v->size_label => "{$v->size_label} (stock: {$v->quantity})"
                                            ])
                                            ->toArray();
                                    })
                                    ->reactive()
                                    ->afterStateUpdated(function (callable $get, callable $set, $state) {
                                        if (!$state) return;

                                        $sizes = $get('../../sizes') ?? [];

                                        // Find all rows with this size
                                        $duplicateKeys = array_keys(array_filter($sizes, fn ($row) =>
                                            isset($row['size']) && $row['size'] === $state
                                        ));

                                        // If more than 1 row has this size it's a duplicate
                                        if (count($duplicateKeys) > 1) {
                                            // Just clear the duplicate row and notify
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
                                    ->reactive()
                                    ->required(),

                                Placeholder::make('current_stock_note')
                                    ->label('')
                                    ->columnSpanFull()
                                    ->content(function (callable $get): ?HtmlString {
                                        $itemId = $get('../../item_id');
                                        $size   = $get('size');

                                        if (!$itemId || !$size) {
                                            return null;
                                        }

                                        $variant = ItemVariant::where('item_id', $itemId)
                                            ->where('size_label', $size)
                                            ->first();

                                        if (!$variant) {
                                            return new HtmlString(
                                                "<div class=\"text-sm text-danger-600 bg-danger-50 border 
                                                    border-danger-200 rounded-lg px-3 py-2 mt-1\">
                                                    ⚠️ Variant not found.
                                                </div>"
                                            );
                                        }

                                        $stock = $variant->quantity ?? 0;

                                        return new HtmlString(
                                            "<div class=\"text-sm text-gray-600 bg-gray-50 border 
                                                border-gray-200 rounded-lg px-3 py-2 mt-1\">
                                                📦 Current stock: <strong>{$stock}</strong>
                                            </div>"
                                        );
                                    }),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->minItems(0)
                            ->addActionLabel('Add Size'),
                    ])
                    ->columns(1)
                    ->required(),
            ]);
    }
}
