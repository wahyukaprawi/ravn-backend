<?php

namespace App\Filament\Resources\Announcements\Tables;

use App\Filament\Actions\SensitiveAction;
use App\Models\Announcement;
use App\Services\AnnouncementService;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('author')->withCount(['audiences', 'reads']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Announcement::STATUS_PUBLISHED => 'success',
                        Announcement::STATUS_SCHEDULED => 'info',
                        Announcement::STATUS_ARCHIVED => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('author.name')->label('Author')->toggleable(),
                TextColumn::make('audiences_count')->label('Audiences'),
                TextColumn::make('reads_count')->label('Reads'),
                TextColumn::make('scheduled_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('published_at')->dateTime()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        Announcement::STATUS_DRAFT => 'Draft',
                        Announcement::STATUS_SCHEDULED => 'Scheduled',
                        Announcement::STATUS_PUBLISHED => 'Published',
                        Announcement::STATUS_ARCHIVED => 'Archived',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Announcement $record): bool => in_array($record->status, [Announcement::STATUS_DRAFT, Announcement::STATUS_SCHEDULED], true)),
                SensitiveAction::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Announcement $record): bool => in_array($record->status, [Announcement::STATUS_DRAFT, Announcement::STATUS_SCHEDULED], true) && auth()->user()?->can('announcements.publish'))
                    ->sensitiveAction(function (Announcement $record): void {
                        try {
                            app(AnnouncementService::class)->publish($record, auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Announcement could not be published')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Announcement published')->send();
                    }),
                SensitiveAction::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Announcement $record): bool => $record->status === Announcement::STATUS_PUBLISHED && auth()->user()?->can('announcements.archive'))
                    ->sensitiveAction(function (Announcement $record): void {
                        try {
                            app(AnnouncementService::class)->archive($record, auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Announcement could not be archived')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Announcement archived')->send();
                    }),
            ]);
    }
}
