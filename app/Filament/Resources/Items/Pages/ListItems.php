<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Category;
use App\Models\Item;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;

class ListItems extends ListRecords
{
    protected static string $resource = ItemResource::class;

    protected function getTableFilters(): array
    {
        // Get categories as id => name
        $categories = Category::withCount('items')->get()->pluck('name', 'id');

        return [
            SelectFilter::make('category_id')
                ->label('Category')
                ->options($categories)
                ->placeholder('All Items')
                ->query(fn ($query, $value) => $value ? $query->where('category_id', $value) : $query),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add Item')
                ->modalWidth('5xl')
                ->modalHeading('Add New Item'),
        ];
    }

    protected function getTableQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Item::query()->with('category', 'itemVariants');
    }
}
