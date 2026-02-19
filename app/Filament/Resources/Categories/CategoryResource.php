<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Filament\Resources\Categories\Tables\CategoriesTable;
use App\Models\Category;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 3;

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    private static function userCan(string $permission): bool
    {
        return Auth::user()?->can($permission) ?? false;
    }

    public static function canViewAny(): bool       { return self::userCan('view-any category'); }
    public static function canCreate(): bool        { return self::userCan('create category'); }
    public static function canEdit($record): bool   { return self::userCan('update category'); }
    public static function canDelete($record): bool { return self::userCan('delete category'); }

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------

    public static function getNavigationGroup(): ?string
    {
        return 'Stock Management';
    }

    // -------------------------------------------------------------------------
    // Schema / Table
    // -------------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return CategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoriesTable::configure($table);
    }

    // -------------------------------------------------------------------------
    // Pages / Relations
    // -------------------------------------------------------------------------

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
        ];
    }
}