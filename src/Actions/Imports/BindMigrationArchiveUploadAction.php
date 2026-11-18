<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class BindMigrationArchiveUploadAction
{
    use AsFake;
    use AsObject;

    public const string STAGING_DIRECTORY = 'migration-assistant/imports/staged';

    public const string ERROR_INVALID_UPLOAD = 'invalid_or_expired_upload';

    public const string ERROR_UPLOAD_TOO_LARGE = 'upload_too_large';

    private const int DEFAULT_MAXIMUM_UPLOAD_KILOBYTES = 250 * 1024;

    private const string UPLOAD_DIRECTORY = 'migration-assistant/imports/uploads';

    private const int TOKEN_TTL_SECONDS = 1800;

    /** @return array{actor_session: string, path: string, filename: string|null} */
    public static function consume(string $token): array
    {
        throw_unless(Str::isUuid($token), RuntimeException::class, self::ERROR_INVALID_UPLOAD);

        $action = new self;
        $lock = Cache::lock($action->cacheKey($token), 10);

        try {
            throw_unless($lock->get(), RuntimeException::class, self::ERROR_INVALID_UPLOAD);
            $upload = Cache::pull($action->cacheKey($token));
        } finally {
            $lock->release();
        }

        throw_unless(is_array($upload) && ($upload['actor_session'] ?? null) === $action->actorSessionHash(), RuntimeException::class, self::ERROR_INVALID_UPLOAD);
        $path = $upload['path'] ?? null;
        throw_unless(is_string($path) && $action->isOwnedPath($path) && Storage::disk(self::diskName())->exists($path), RuntimeException::class, self::ERROR_INVALID_UPLOAD);

        $filename = $upload['filename'] ?? null;

        return [
            'actor_session' => $upload['actor_session'],
            'path' => $path,
            'filename' => is_string($filename) ? $filename : null,
        ];
    }

    public static function maximumUploadKilobytes(): int
    {
        $configured = config('migration-assistant.limits.max_package_upload_kilobytes', self::DEFAULT_MAXIMUM_UPLOAD_KILOBYTES);

        return is_numeric($configured) ? max(1, (int) $configured) : self::DEFAULT_MAXIMUM_UPLOAD_KILOBYTES;
    }

    public static function maximumUploadMegabytes(): string
    {
        $megabytes = Number::format(self::maximumUploadKilobytes() / 1024, maxPrecision: 1);

        return is_string($megabytes) ? $megabytes : (string) round(self::maximumUploadKilobytes() / 1024, 1);
    }

    public static function isCanonicalUploadPath(string $path): bool
    {
        return str_starts_with($path, self::UPLOAD_DIRECTORY . '/') && ! str_contains($path, '..');
    }

    /** The disk the upload field stages to and this action secures from; both must agree. */
    public static function diskName(): string
    {
        $disk = config('migration-assistant.disk', 'local');

        return is_string($disk) ? $disk : 'local';
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function handle(array $state): array
    {
        $stagedPath = $this->pathFrom($state['archive'] ?? null);
        throw_unless($this->isStagedPath($stagedPath), RuntimeException::class, StartPageImportAction::ERROR_UPLOAD_REQUIRED);

        $disk = Storage::disk(self::diskName());
        throw_unless($disk->exists($stagedPath), RuntimeException::class, StartPageImportAction::ERROR_UPLOAD_REQUIRED);

        // The upload field's size rule only runs in the browser form, so the staged file is measured here
        // before it is bound to an import and parsed.
        if ($disk->size($stagedPath) > self::maximumUploadKilobytes() * 1024) {
            $disk->delete($stagedPath);

            throw new RuntimeException(self::ERROR_UPLOAD_TOO_LARGE);
        }

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

    private function actorSessionHash(): string
    {
        $actorId = auth()->id();
        $applicationKey = config('app.key');

        return hash_hmac(
            'sha256',
            (is_int($actorId) || is_string($actorId) ? $actorId : '') . '|' . session()->getId(),
            is_string($applicationKey) ? $applicationKey : '',
        );
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
        $path = is_array($value) ? array_values($value)[0] ?? null : $value;

        return is_string($path) ? $path : '';
    }

    private function filenameFrom(mixed $value): ?string
    {
        $filename = is_array($value) ? array_values($value)[0] ?? null : $value;

        return is_string($filename) && $filename !== '' ? basename($filename) : null;
    }
}
