<?php

namespace App\Filament\Resources\Issuances\Pages;

use App\Filament\Resources\Issuances\IssuanceResource;
use App\Models\Issuance;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListIssuances extends ListRecords
{
    protected static string $resource = IssuanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->badge(Issuance::count()),

            'pending' => Tab::make('Pending')
                ->badge(Issuance::where('status', 'pending')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),

            'released' => Tab::make('Released')
                ->badge(Issuance::where('status', 'released')->count())
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'released')),

            'issued' => Tab::make('Issued')
                ->badge(Issuance::where('status', 'issued')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'issued')),

            'returned' => Tab::make('Returned')
                ->badge(Issuance::where('status', 'returned')->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'returned')),

            'cancelled' => Tab::make('Cancelled')
                ->badge(Issuance::where('status', 'cancelled')->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'cancelled')),
        ];
    }
}