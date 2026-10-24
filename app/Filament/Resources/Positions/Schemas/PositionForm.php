<?php

namespace App\Filament\Resources\Positions\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PositionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(60)
                    ->alphaDash()
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->required()
                    ->maxLength(150),
                Textarea::make('description')
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }
}
