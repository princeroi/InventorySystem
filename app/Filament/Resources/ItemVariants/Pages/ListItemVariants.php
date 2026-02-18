<?php

namespace App\Filament\Resources\ItemVariants\Pages;

use App\Filament\Resources\ItemVariants\ItemVariantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListItemVariants extends ListRecords
{
    protected static string $resource = ItemVariantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            
        ];
    }
}
