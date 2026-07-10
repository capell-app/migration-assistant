<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class BindMigrationArchiveUploadAction
{
    use AsAction;

    public const string STAGING_DIRECTORY = 'migration-assistant/imports/staged';

    public const string ERROR_INVALID_UPLOAD = 'invalid_or_expired_upload';

    private const string UPLOAD_DIRECTORY = 'migration-assistant/imports/uploads';

    private const int TOKEN_TTL_SECONDS = 1800;

    public static function consume(string $token): array
    {
        throw_unless(Str::isUuid($token), RuntimeException::class, self::ERROR_INVALID_UPLOAD);

        $action = new self;
        $lock = Cache::lock($action->cacheKey($token), 10);

        try {
            throw_unless($lock->get(), RuntimeException::class, self::ERROR_INVALID_UPLOAD);
            $upload = Cache::pull($action->cacheKey($token));
        } finally {
            optional($lock)->release();
        }

        throw_unless(is_array($upload) && ($upload['actor_session'] ?? null) === $action->actorSessionHash(), RuntimeException::class, self::ERROR_INVALID_UPLOAD);
        $path = $upload['path'] ?? null;
        throw_unless(is_string($path) && $action->isOwnedPath($path) && Storage::disk($action->diskName())->exists($path), RuntimeException::class, self::ERROR_INVALID_UPLOAD);

        return $upload;
    }

    public static function isCanonicalUploadPath(string $path): bool
    {
        return str_starts_with($path, self::UPLOAD_DIRECTORY . '/') && ! str_contains($path, '..');
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public function handle(array $state): array
    {
        $stagedPath = $this->pathFrom($state['archive'] ?? null);
        throw_unless($this->isStagedPath($stagedPath), RuntimeException::class, StartPageImportAction::ERROR_UPLOAD_REQUIRED);

        $disk = Storage::disk($this->diskName());
        throw_unless($disk->exists($stagedPath), RuntimeException::class, StartPageImportAction::ERROR_UPLOAD_REQUIRED);

        $token = (string) Str::uuid();
        $path = self::UPLOAD_DIRECTORY . '/' . $this->actorSessionHash() . '/' . $token . '.zip';
        throw_unless($disk->move($stagedPath, $path), RuntimeException::class, 'Unable to secure the uploaded migration package.');

        Cache::put($this->cacheKey($token), [
            'actor_session' => $this->actorSessionHash(),
            'path' => $path,
            'filename' => $this->filenameFrom($state['archive_filename'] ?? null),
        ], now()->addSeconds(self::TOKEN_TTL_SECONDS));

        $state['archive'] = $token;

        return $state;
    }

    private function diskName(): string
    {
        $disk = config('migration-assistant.disk', 'local');

        return is_string($disk) ? $disk : 'local';
    }

    private function actorSessionHash(): string
    {
        return hash_hmac('sha256', (string) auth()->id() . '|' . session()->getId(), (string) config('app.key'));
    }

    private function cacheKey(string $token): string
    {
        return 'migration-assistant:upload:' . $token;
    }

    private function isStagedPath(string $path): bool
    {
        return str_starts_with($path, self::STAGING_DIRECTORY . '/') && ! str_contains($path, '..');
    }

    private function isOwnedPath(string $path): bool
    {
        return self::isCanonicalUploadPath($path) && str_starts_with($path, self::UPLOAD_DIRECTORY . '/' . $this->actorSessionHash() . '/');
    }

    private function pathFrom(mixed $value): string
    {
        return is_array($value) ? (string) array_values($value)[0] : (string) $value;
    }

    private function filenameFrom(mixed $value): ?string
    {
        $filename = is_array($value) ? array_values($value)[0] ?? null : $value;

        return is_string($filename) && $filename !== '' ? basename($filename) : null;
    }
}
