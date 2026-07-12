<?php

declare(strict_types=1);

arch('migration-assistant does not reference publishing studio')
    ->expect('Capell\MigrationAssistant')
    ->not->toUse('Capell\PublishingStudio');
