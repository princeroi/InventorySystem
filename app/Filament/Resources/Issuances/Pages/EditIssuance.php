<?php

namespace App\Filament\Resources\Issuances\Pages;

use App\Filament\Resources\Issuances\IssuanceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditIssuance extends EditRecord
{
    protected static string $resource = IssuanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
