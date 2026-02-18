<?php

namespace App\Filament\Resources\ItemVariants\Pages;

use App\Filament\Resources\ItemVariants\ItemVariantResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditItemVariant extends EditRecord
{
    protected static string $resource = ItemVariantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
