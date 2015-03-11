<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Filament\Resources\ImportSessions\Tables;

use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Components\Tables\Columns\IdentifierColumn;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\MigrationAssistant\Actions\BuildImportRecoveryStatusAction;
use Capell\MigrationAssistant\Actions\ReclaimStaleImportSessionsAction;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Filament\Pages\ImportPagesPage;
use Capell\MigrationAssistant\Filament\Pages\ImportSitesPage;
use Capell\MigrationAssistant\Models\ImportSession;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Foundation\Auth\User;

class ImportSessionsTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns(static::getTableColumns())
            ->headerActions([
                Action::make('recover_stale_imports')
                    ->label(function (): string {
                        $status = BuildImportRecoveryStatusAction::run();

                        return (string) __('migration-assistant::recovery.imports_action', [
                            'count' => $status->staleCount,
                            'age' => $status->oldestAgeMinutes ?? 0,
                        ]);
                    })
                    ->visible(fn (): bool => BuildImportRecoveryStatusAction::run()->staleCount > 0)
                    ->authorize(fn (): bool => auth()->user()?->can('viewAny', ImportSession::class) === true)
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $count = ReclaimStaleImportSessionsAction::run();
                        Notification::make()->title(__('migration-assistant::recovery.imports_recovered', ['count' => $count]))->success()->send();
                    }),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('capell-admin::exchanger.kind'))
                    ->options(ImportSessionKind::class),
                SelectFilter::make('status')
                    ->label(__('capell-admin::exchanger.status'))
                    ->options(ImportSessionStatus::class),
                SelectFilter::make('user_id')
                    ->label(__('capell-admin::exchanger.user'))
                    ->options(fn (): array => User::query()
                        ->whereIn('id', ImportSession::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading(__('capell-admin::generic.no_import_sessions'))
            ->emptyStateDescription(__('migration-assistant::imports.empty_state_description'))
            ->emptyStateIcon('heroicon-o-arrow-down-tray')
            ->emptyStateActions([
                Action::make('start_page_import')
                    ->label(__('migration-assistant::imports.empty_state_page_import'))
                    ->url(ImportPagesPage::getUrl()),
                Action::make('start_site_import')
                    ->label(__('migration-assistant::imports.empty_state_site_import'))
                    ->color('gray')
                    ->url(ImportSitesPage::getUrl()),
            ]);
    }

    /**
     * @return array<array-key, mixed>
     */
    protected static function getTableColumns(): array
    {
        return [
            IdentifierColumn::make('id'),
            TextColumn::make('uuid')
                ->label(__('capell-admin::exchanger.uuid'))
                ->limit(8)
                ->searchable()
                ->toggleable(),
            TextColumn::make('kind')
                ->label(__('capell-admin::exchanger.kind'))
                ->badge()
                ->sortable(),
            TextColumn::make('status')
                ->label(__('capell-admin::exchanger.status'))
                ->badge()
                ->sortable()
                ->color(fn (ImportSessionStatus $state): string => match ($state) {
                    ImportSessionStatus::Completed => 'success',
                    ImportSessionStatus::Failed => 'danger',
                    ImportSessionStatus::Running, ImportSessionStatus::Queued => 'warning',
                    default => 'gray',
                }),
            TextColumn::make('user.name')
                ->label(__('capell-admin::exchanger.user'))
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('source_filename')
                ->label(__('capell-admin::exchanger.source_filename'))
                ->searchable()
                ->toggleable(),
            TextColumn::make('result_summary')
                ->label(__('capell-admin::exchanger.result_summary_counts'))
                ->formatStateUsing(static function (mixed $state): string {
                    if (! is_array($state) || $state === []) {
                        return '—';
                    }

                    $pages = (int) ($state['pages_imported'] ?? $state['pages_created'] ?? 0);
                    $relations = (int) ($state['relations_resolved'] ?? 0);
                    $media = (int) ($state['media_ingested'] ?? $state['media_reassigned'] ?? 0);
                    $sites = count(is_array($state['created_site_ids'] ?? null) ? $state['created_site_ids'] : []);
                    $domains = count(is_array($state['created_site_domain_ids'] ?? null) ? $state['created_site_domain_ids'] : []);

                    return sprintf('P:%d · R:%d · M:%d · S:%d · D:%d', $pages, $relations, $media, $sites, $domains);
                })
                ->toggleable(),
            DateColumn::make('executed_at')
                ->label(__('capell-admin::exchanger.executed_at'))
                ->toggleable(),
            DateColumn::make('created_at')
                ->toggleable(),
        ];
    }
}
