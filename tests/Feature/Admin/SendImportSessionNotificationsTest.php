<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Events\ImportCompleted;
use Capell\MigrationAssistant\Events\ImportFailed;
use Capell\MigrationAssistant\Listeners\SendImportSessionNotifications;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Notifications\ImportCompletedNotification;
use Capell\MigrationAssistant\Notifications\ImportFailedNotification;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    if (! class_exists(ImportSession::class)) {
        test()->markTestSkipped('capell-app/migration-assistant is not installed in this checkout.');
    }
});

function makeImportSessionForNotification(?int $userId = null): ImportSession
{
    return ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $userId,
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed,
        'source_filename' => 'package.zip',
        'source_package_path' => 'migration-assistant/imports/package.zip',
        'result_summary' => ['pages_created' => 5, 'relations_resolved' => 2],
    ]);
}

it('notifies the initiating user on a completed import via mail and database channels', function (): void {
    Notification::fake();

    $initiator = User::factory()->create();
    $session = makeImportSessionForNotification($initiator->getKey());

    (new SendImportSessionNotifications)->handleCompleted(new ImportCompleted($session));

    Notification::assertSentTo(
        $initiator,
        ImportCompletedNotification::class,
        function (ImportCompletedNotification $notification, array $channels): true {
            expect($channels)->toEqualCanonicalizing(['mail', 'database']);

            return true;
        },
    );
});

it('notifies the initiating user on a failed import', function (): void {
    Notification::fake();

    $initiator = User::factory()->create();
    $session = makeImportSessionForNotification($initiator->getKey());
    $session->forceFill(['failure_reason' => 'checksum mismatch token=notification-secret'])->save();

    (new SendImportSessionNotifications)->handleFailed(new ImportFailed($session, 'checksum mismatch token=notification-secret'));

    Notification::assertSentTo(
        $initiator,
        ImportFailedNotification::class,
        function (ImportFailedNotification $notification, array $channels): bool {
            $payload = $notification->toArray(new User);
            expect($notification)->toBeInstanceOf(ShouldBeEncrypted::class)
                ->and($payload)->toBe([
                    'import_session_id' => $payload['import_session_id'],
                    'outcome' => 'failed',
                ])
                ->and(serialize($notification))->not->toContain('notification-secret');

            return true;
        },
    );
});

it('queues completed notifications with only an opaque session reference', function (): void {
    $session = makeImportSessionForNotification();
    $session->forceFill([
        'result_summary' => ['private_url' => 'https://example.test/import?token=summary-secret'],
    ])->save();
    $notification = new ImportCompletedNotification($session);

    expect($notification)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($notification->toArray(new User))->toBe([
            'import_session_id' => $session->getKey(),
            'outcome' => 'completed',
        ])
        ->and(serialize($notification))->not->toContain('summary-secret');
});

it('honours channel preferences and sends only the database channel when mail is disabled', function (): void {
    Notification::fake();

    config()->set('migration-assistant.notifications.channels', ['database']);

    $initiator = User::factory()->create();
    $session = makeImportSessionForNotification($initiator->getKey());

    (new SendImportSessionNotifications)->handleCompleted(new ImportCompleted($session));

    Notification::assertSentTo(
        $initiator,
        ImportCompletedNotification::class,
        function (ImportCompletedNotification $notification, array $channels): bool {
            expect($channels)->toBe(['database']);

            return true;
        },
    );
});

it('sends nothing when migration-assistant notifications are disabled globally', function (): void {
    Notification::fake();

    config()->set('migration-assistant.notifications.enabled', false);

    $initiator = User::factory()->create();
    $session = makeImportSessionForNotification($initiator->getKey());

    (new SendImportSessionNotifications)->handleCompleted(new ImportCompleted($session));
    (new SendImportSessionNotifications)->handleFailed(new ImportFailed($session, 'x'));

    Notification::assertNothingSent();
});

it('skips delivery when the session has no initiating user and no recipient roles', function (): void {
    Notification::fake();

    $session = makeImportSessionForNotification();

    (new SendImportSessionNotifications)->handleCompleted(new ImportCompleted($session));

    Notification::assertNothingSent();
});
