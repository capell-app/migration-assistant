<?php

declare(strict_types=1);

use Capell\Tests\Support\InstalledRuntimeParity;

it('matches fresh installed boot after in-process installation and repeated refresh', function (): void {
    InstalledRuntimeParity::assertPackage('migration-assistant');
});
