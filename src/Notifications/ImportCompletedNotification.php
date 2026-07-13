<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Notifications;

use Capell\MigrationAssistant\Filament\Resources\ImportSessions\ImportSessionResource;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Delivered when an {@see ImportSession} finishes with a successful
 * report. Mail and database channels are driven from the
 * `migration-assistant.notifications.channels` config key so operators
 * opt in once.
 */
class ImportCompletedNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    private readonly int $importSessionId;

    public function __construct(ImportSession $session)
    {
        $sessionKey = $session->getKey();

        if (! is_int($sessionKey)) {
            throw new UnexpectedValueException('Expected an integer import session key.');
        }

        $this->importSessionId = $sessionKey;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = config(
            'migration-assistant.notifications.channels',
            ['mail', 'database'],
        );

        return is_array($channels) ? array_values(array_filter($channels, is_string(...))) : ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $counts = $this->resultCounts();

        return (new MailMessage)
            ->subject(__('capell-admin::exchanger.mail.completed_subject'))
            ->line(__('capell-admin::exchanger.mail.completed_intro', [
                'pages' => $counts['pages'],
            ]))
            ->action(__('capell-admin::exchanger.mail.completed_cta'), $this->resolveSessionUrl());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'import_session_id' => $this->importSessionId,
            'outcome' => 'completed',
        ];
    }

    /** @return array{pages: int} */
    private function resultCounts(): array
    {
        $summary = $this->session()?->result_summary;
        $summary = is_array($summary) ? $summary : [];
        $pages = $summary['pages_created'] ?? $summary['pages'] ?? 0;

        return ['pages' => is_int($pages) ? $pages : (is_string($pages) && ctype_digit($pages) ? (int) $pages : 0)];
    }

    private function resolveSessionUrl(): string
    {
        try {
            $session = $this->session();

            if (! $session instanceof ImportSession) {
                throw new RuntimeException('Import session is no longer available.');
            }

            return ImportSessionResource::getUrl('view', [
                'record' => $session->getKey(),
            ]);
        } catch (Throwable) {
            $fallback = config('app.url', '/');

            return is_string($fallback) ? $fallback : '/';
        }
    }

    private function session(): ?ImportSession
    {
        return ImportSession::query()->find($this->importSessionId);
    }
}
