<?php

declare(strict_types=1);

// Stands in for Artisan in a dedicated child: reports what the launcher actually handed it.
require dirname(__DIR__, 4) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? null;
if (! is_array($arguments)) {
    throw new RuntimeException('The probe must run from the command line.');
}

$path = getenv('APP_CONFIG_CACHE');
$configuration = is_string($path) && is_file($path) ? require $path : null;

fwrite(STDOUT, json_encode([
    'arguments' => array_slice($arguments, 1),
    'environment' => getenv(),
    'mode' => is_string($path) && is_file($path) ? substr(sprintf('%o', fileperms($path)), -4) : null,
    'directory_mode' => is_string($path) && is_dir(dirname($path)) ? substr(sprintf('%o', fileperms(dirname($path))), -4) : null,
    'path' => $path,
    'umask' => sprintf('%04o', umask()),
    // Pure enums and other objects in a real configuration cannot be JSON encoded, so only the database section is
    // decoded; the complete cache is reported as text for credential checks.
    'configuration' => ['database' => is_array($configuration) ? ($configuration['database'] ?? null) : null],
    'configuration_text' => is_string($path) && is_file($path) ? file_get_contents($path) : null,
], JSON_THROW_ON_ERROR));
fwrite(STDERR, 'probe-stderr');

exit(in_array('--fail', $arguments, true) ? 3 : 0);
