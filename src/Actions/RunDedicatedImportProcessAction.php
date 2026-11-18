<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Enums\InPlaceDatabaseRefusalReason;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs one Artisan command in a child process whose Laravel bootstrap reads a private configuration cache, so the
 * restricted writer is the default before any provider boots. The child inherits no environment variables, which
 * keeps ambient and administrative database credentials out of it, and the cache is removed when the child exits.
 */
final readonly class RunDedicatedImportProcessAction
{
    public function __construct(private BuildDedicatedImportConfigurationAction $configuration) {}

    /**
     * @param  list<string>  $arguments  The Artisan command name followed by its options.
     * @param  Closure(string, string): void  $output  Receives each chunk of child output with its stream type.
     */
    public function handle(string $inspector, string $reader, string $writer, array $arguments, Closure $output): int
    {
        if (! app()->runningInConsole() || $arguments === [] || str_starts_with($arguments[0], '-')) {
            throw InPlaceDatabaseRefusalReason::Unsupported->exception('A dedicated import process runs one named Artisan command from the console.');
        }

        $entry = config('migration-assistant.dedicated_process.entry') ?? base_path('artisan');
        if (! is_string($entry) || ! is_file($entry)) {
            throw InPlaceDatabaseRefusalReason::Unsupported->exception('The Artisan entry point for the dedicated import process does not exist.');
        }

        /** @var array<string, mixed> $current */
        $current = config()->all();
        $items = $this->configuration->handle($current, $inspector, $reader, $writer);

        $root = storage_path('framework/dedicated-imports');
        $this->removeAbandonedCaches($root);
        $directory = $root . '/' . Str::uuid()->toString();
        $path = $directory . '/config.php';
        try {
            // The restrictive mask applies only while the credentials are written; the child must inherit the
            // application's own mask so the files it imports keep their normal permissions.
            $previous = umask(0077);
            try {
                File::ensureDirectoryExists($directory, 0700);
                File::put($path, '<?php return ' . var_export($items, true) . ';' . PHP_EOL);
                chmod($directory, 0700);
                chmod($path, 0600);
            } finally {
                umask($previous);
            }

            try {
                $restored = require $path;
            } catch (Throwable) {
                $restored = null;
            }

            // Restored objects are new instances (and DateInterval refuses ==), so the round trip compares serialised values.
            if (serialize($restored) !== serialize($items)) {
                throw InPlaceDatabaseRefusalReason::Unsupported->exception('The dedicated import configuration did not survive caching unchanged.');
            }

            // Every inherited variable is removed: the child reads its whole configuration from the private cache.
            // Symfony merges $_ENV and the process environment into a child, so every key from either is unset.
            $inherited = array_keys([...getenv(), ...$_ENV, ...array_filter($_SERVER, is_string(...))]);
            $environment = array_fill_keys(array_map(strval(...), $inherited), false);
            $environment['APP_CONFIG_CACHE'] = $path;

            $process = new Process([PHP_BINARY, $entry, ...$arguments], base_path(), $environment, null, null);
            $process->run(static function (string $type, string $buffer) use ($output): void {
                $output($type, $buffer);
            });

            return $process->getExitCode() ?? 1;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * A child reads its cache only while it boots, so a cache older than an hour belongs to a launcher that was killed
     * before it could clean up; it still holds restricted credentials and is removed.
     */
    private function removeAbandonedCaches(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        foreach (File::directories($root) as $directory) {
            $modified = @filemtime($directory);
            if ($modified !== false && $modified < time() - 3600) {
                File::deleteDirectory($directory);
            }
        }
    }
}
