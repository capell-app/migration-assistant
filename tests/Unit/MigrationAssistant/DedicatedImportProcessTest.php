<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Actions\AssertDedicatedImportProcessAction;
use Capell\MigrationAssistant\Actions\BuildDedicatedImportConfigurationAction;
use Capell\MigrationAssistant\Actions\RunDedicatedImportProcessAction;
use Capell\MigrationAssistant\Exceptions\ImportRefusedException;
use Illuminate\Support\Facades\File;

/** @return array<string, array<string, int|string>> */
function dedicatedProfiles(): array
{
    $profile = static fn (string $username): array => ['driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => 3307, 'database' => 'capell_test_x_test', 'username' => $username, 'password' => $username . '-secret'];

    return [
        'admin' => $profile('root'),
        'inspector' => $profile('inspector'),
        'reader' => $profile('reader'),
        'writer' => $profile('writer'),
    ];
}

/** @return array<string, mixed> */
function dedicatedConfiguration(): array
{
    return [
        'app' => ['name' => 'Capell', 'key' => 'base64:key'],
        'database' => ['default' => 'admin', 'connections' => dedicatedProfiles(), 'migrations' => ['table' => 'migrations'], 'redis' => ['default' => ['password' => 'redis-secret']]],
        'queue' => ['default' => 'redis', 'failed' => ['driver' => 'database-uuids', 'database' => 'admin']],
        'cache' => ['default' => 'redis'],
        'session' => ['driver' => 'database'],
        'mail' => ['default' => 'smtp', 'mailers' => ['smtp' => ['transport' => 'smtp', 'password' => 'smtp-secret']]],
        'services' => ['postmark' => ['token' => 'postmark-secret']],
        'voyager-importer' => ['in_place' => ['reader_connection' => 'reader']],
    ];
}

/** @return list<array-key> */
function dedicatedKeys(mixed $value): array
{
    return is_array($value) ? array_keys($value) : [];
}

/** @return array<array-key, mixed> */
function dedicatedProbe(string $stdout): array
{
    $probe = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    expect($probe)->toBeArray();

    return is_array($probe) ? $probe : [];
}

function dedicatedText(mixed $value): string
{
    return is_string($value) ? $value : '';
}

it('keeps only the three restricted profiles with the writer as default and no shared stores', function (): void {
    $built = resolve(BuildDedicatedImportConfigurationAction::class)->handle(dedicatedConfiguration(), 'inspector', 'reader', 'writer');

    expect(dedicatedKeys(data_get($built, 'database.connections')))->toBe(['inspector', 'reader', 'writer'])
        ->and(data_get($built, 'database.default'))->toBe('writer')
        ->and(data_get($built, 'database'))->not->toHaveKey('redis')
        ->and(data_get($built, 'database.migrations'))->toBe(['table' => 'migrations'])
        ->and(data_get($built, 'queue.default'))->toBe('sync')
        ->and(data_get($built, 'queue.failed.driver'))->toBe('null')
        ->and(data_get($built, 'cache.default'))->toBe('array')
        ->and(data_get($built, 'session.driver'))->toBe('array')
        ->and(data_get($built, BuildDedicatedImportConfigurationAction::MARKER))->toBeTrue()
        ->and(data_get($built, 'app'))->toBe(['name' => 'Capell', 'key' => 'base64:key'])
        ->and(data_get($built, 'mail.default'))->toBe('array')
        ->and(json_encode($built, JSON_THROW_ON_ERROR))->not->toContain('root')->not->toContain('redis-secret')->not->toContain('smtp-secret')->not->toContain('postmark-secret');
});

it('refuses profiles the child could not safely boot from', function (array $configuration, string $inspector, string $reader, string $writer): void {
    expect(fn () => resolve(BuildDedicatedImportConfigurationAction::class)->handle($configuration, $inspector, $reader, $writer))
        ->toThrow(ImportRefusedException::class);
})->with([
    'a repeated profile' => fn (): array => [dedicatedConfiguration(), 'reader', 'reader', 'writer'],
    'a missing profile' => fn (): array => [dedicatedConfiguration(), 'inspector', 'reader', 'absent'],
    'a non-MariaDB profile' => fn (): array => [array_replace_recursive(dedicatedConfiguration(), ['database' => ['connections' => ['writer' => ['driver' => 'sqlite']]]]), 'inspector', 'reader', 'writer'],
    'a URL profile' => fn (): array => [array_replace_recursive(dedicatedConfiguration(), ['database' => ['connections' => ['reader' => ['url' => 'mysql://root:pw@db/x']]]]), 'inspector', 'reader', 'writer'],
    'a launch from inside a dedicated child' => fn (): array => [array_replace_recursive(dedicatedConfiguration(), ['migration-assistant' => ['dedicated_process' => ['active' => true]]]), 'inspector', 'reader', 'writer'],
    'a value a cache cannot hold' => fn (): array => [[...dedicatedConfiguration(), 'filesystems' => ['resolver' => static fn (): null => null]], 'inspector', 'reader', 'writer'],
]);

it('boots the child from a private configuration cache with no inherited environment and removes it afterwards', function (): void {
    config(['database.connections' => dedicatedProfiles(), 'migration-assistant.dedicated_process.entry' => dirname(__DIR__, 2) . '/Fixtures/dedicated-process-probe.php']);
    putenv('DB_PASSWORD=ambient-admin-secret');
    $_ENV['DB_USERNAME'] = 'ambient-root';
    $stdout = '';
    $stderr = '';

    try {
        $exit = resolve(RunDedicatedImportProcessAction::class)->handle('inspector', 'reader', 'writer', ['voyager-importer:import', '--in-place-process', '--source-label=--force', '--json'], function (string $type, string $buffer) use (&$stdout, &$stderr): void {
            $type === 'err' ? $stderr .= $buffer : $stdout .= $buffer;
        });
    } finally {
        putenv('DB_PASSWORD');
        unset($_ENV['DB_USERNAME']);
    }

    $probe = dedicatedProbe($stdout);
    expect($exit)->toBe(0)
        ->and($stderr)->toBe('probe-stderr')
        ->and(data_get($probe, 'arguments'))->toBe(['voyager-importer:import', '--in-place-process', '--source-label=--force', '--json'])
        // The operating system may add its own process variables (macOS adds __CF_USER_TEXT_ENCODING); nothing inherited survives.
        ->and(array_values(array_filter(dedicatedKeys(data_get($probe, 'environment')), static fn (int|string $name): bool => ! str_starts_with((string) $name, '__CF_'))))->toBe(['APP_CONFIG_CACHE'])
        ->and(data_get($probe, 'mode'))->toBe('0600')
        ->and(data_get($probe, 'directory_mode'))->toBe('0700')
        ->and(data_get($probe, 'configuration.database.default'))->toBe('writer')
        ->and(dedicatedKeys(data_get($probe, 'configuration.database.connections')))->toBe(['inspector', 'reader', 'writer'])
        ->and(data_get($probe, 'configuration_text'))->toContain('writer-secret')->not->toContain('root-secret')
        ->and(dedicatedText(data_get($probe, 'path')))->toEndWith('/config.php')
        ->and(file_exists(dedicatedText(data_get($probe, 'path'))))->toBeFalse()
        ->and(is_dir(dirname(dedicatedText(data_get($probe, 'path')))))->toBeFalse();
});

it('leaves the child the application umask and removes caches abandoned by a killed launcher', function (): void {
    config(['database.connections' => dedicatedProfiles(), 'migration-assistant.dedicated_process.entry' => dirname(__DIR__, 2) . '/Fixtures/dedicated-process-probe.php']);
    $root = storage_path('framework/dedicated-imports');
    $abandoned = $root . '/abandoned-' . bin2hex(random_bytes(4));
    $recent = $root . '/recent-' . bin2hex(random_bytes(4));
    foreach ([$abandoned, $recent] as $directory) {
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/config.php', '<?php return [];');
    }

    touch($abandoned, time() - 7200);
    $previous = umask(0002);
    $stdout = '';
    try {
        resolve(RunDedicatedImportProcessAction::class)->handle('inspector', 'reader', 'writer', ['voyager-importer:import'], function (string $type, string $buffer) use (&$stdout): void {
            $type === 'out' && $stdout .= $buffer;
        });
        $parentMask = umask();
        $recentKept = is_dir($recent);
    } finally {
        umask($previous);
        File::deleteDirectory($recent);
    }

    expect(data_get(dedicatedProbe($stdout), 'umask'))->toBe('0002')
        ->and($parentMask)->toBe(0002)
        ->and(is_dir($abandoned))->toBeFalse()
        ->and($recentKept)->toBeTrue();
});

it('relays the child exit code and still removes the cache when the child fails', function (): void {
    config(['database.connections' => dedicatedProfiles(), 'migration-assistant.dedicated_process.entry' => dirname(__DIR__, 2) . '/Fixtures/dedicated-process-probe.php']);
    $stdout = '';

    $exit = resolve(RunDedicatedImportProcessAction::class)->handle('inspector', 'reader', 'writer', ['migration-assistant:rollback-execute', '--fail'], function (string $type, string $buffer) use (&$stdout): void {
        $type === 'out' && $stdout .= $buffer;
    });

    expect($exit)->toBe(3)
        ->and(dedicatedText(data_get(dedicatedProbe($stdout), 'path')))->toEndWith('/config.php')
        ->and(file_exists(dedicatedText(data_get(dedicatedProbe($stdout), 'path'))))->toBeFalse();
});

it('refuses an option in place of the command name and a missing entry point before writing a cache', function (array $arguments, ?string $entry): void {
    $arguments = array_values(array_filter($arguments, is_string(...)));
    config(['database.connections' => dedicatedProfiles(), 'migration-assistant.dedicated_process.entry' => $entry]);
    $directory = storage_path('framework/dedicated-imports');
    $before = is_dir($directory) ? scandir($directory) : [];

    expect(fn () => resolve(RunDedicatedImportProcessAction::class)->handle('inspector', 'reader', 'writer', $arguments, static function (): void {}))
        ->toThrow(ImportRefusedException::class)
        ->and(is_dir($directory) ? scandir($directory) : [])->toBe($before);
})->with([
    'an option first' => [['--in-place-process'], __FILE__],
    'no command' => [[], __FILE__],
    'a missing entry point' => [['voyager-importer:import'], '/nonexistent/artisan'],
]);

it('refuses a configuration object the cache cannot restore and removes what it wrote', function (): void {
    config(['database.connections' => dedicatedProfiles(), 'migration-assistant.dedicated_process.entry' => __FILE__, 'app.unrestorable' => new ArrayObject(['x' => 1])]);
    $directory = storage_path('framework/dedicated-imports');
    $before = is_dir($directory) ? scandir($directory) : [];

    expect(fn () => resolve(RunDedicatedImportProcessAction::class)->handle('inspector', 'reader', 'writer', ['voyager-importer:import'], static function (): void {}))
        ->toThrow(ImportRefusedException::class, 'did not survive caching')
        ->and(is_dir($directory) ? scandir($directory) : [])->toBe($before);
});

it('refuses a dedicated import in a process that did not boot from the launcher cache', function (bool $cached, bool $marked): void {
    app()->instance('config_loaded_from_cache', $cached);
    config([BuildDedicatedImportConfigurationAction::MARKER => $marked]);

    expect(fn () => (new AssertDedicatedImportProcessAction)->handle('inspector', 'reader', 'writer'))
        ->toThrow(ImportRefusedException::class, 'launcher-built configuration cache');
})->with([
    'booted from configuration files' => [false, true],
    'a cache the launcher did not build' => [true, false],
]);

it('removes the launcher cache as soon as the child has loaded it, and nothing outside the launcher directory', function (): void {
    $inside = storage_path('framework/dedicated-imports/booted-' . bin2hex(random_bytes(4)));
    $outside = storage_path('framework/not-dedicated-' . bin2hex(random_bytes(4)));
    foreach ([$inside, $outside] as $directory) {
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/config.php', '<?php return [];');
    }

    app()->instance('config_loaded_from_cache', true);
    config([BuildDedicatedImportConfigurationAction::MARKER => true]);
    try {
        foreach ([$inside, $outside] as $directory) {
            putenv('APP_CONFIG_CACHE=' . $directory . '/config.php');
            // The configuration here is not a dedicated one, so the check still refuses after removing the cache.
            expect(fn () => (new AssertDedicatedImportProcessAction)->handle('inspector', 'reader', 'writer'))->toThrow(ImportRefusedException::class);
        }

        expect(file_exists($inside . '/config.php'))->toBeFalse()
            ->and(file_exists($outside . '/config.php'))->toBeTrue();
    } finally {
        putenv('APP_CONFIG_CACHE');
        File::deleteDirectory($inside);
        File::deleteDirectory($outside);
    }
});
