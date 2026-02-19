<?php

namespace App\Filament\Resources\ItemVariants\Pages;

use App\Filament\Resources\ItemVariants\ItemVariantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListItemVariants extends ListRecords
{
    protected static string $resource = ItemVariantResource::class;

    // -------------------------------------------------------------------------
    // Permission Helper
    // -------------------------------------------------------------------------

    private function userCan(string $permission): bool
    {
        return Auth::user()?->can($permission) ?? false;
    }

    // -------------------------------------------------------------------------
    // Header Actions
    // -------------------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => $this->userCan('create stock')),
        ];
    }
}