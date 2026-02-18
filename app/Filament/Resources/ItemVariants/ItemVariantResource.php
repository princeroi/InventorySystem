<?php

namespace App\Filament\Resources\ItemVariants;

use App\Filament\Resources\ItemVariants\Pages\CreateItemVariant;
use App\Filament\Resources\ItemVariants\Pages\EditItemVariant;
use App\Filament\Resources\ItemVariants\Pages\ListItemVariants;
use App\Filament\Resources\ItemVariants\Schemas\ItemVariantForm;
use App\Filament\Resources\ItemVariants\Tables\ItemVariantsTable;
use App\Models\ItemVariant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ItemVariantResource extends Resource
{
    protected static ?string $model = ItemVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'Stock';

    protected static ?string $navigationLabel = 'Stocks';

    protected static ?string $modelLabel = 'Stocks';

    protected static ?string $pluralModelLabel = 'Stocks';

    public static function getNavigationGroup(): ?string
    {
        return 'Stock Management';
    }

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return ItemVariantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ItemVariantsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListItemVariants::route('/'),
        ];
    }
}
