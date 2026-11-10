<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Enums;

use Capell\MigrationAssistant\Exceptions\ImportRefusedException;

enum InPlaceDatabaseRefusalReason: int
{
    case ProfileMismatch = 47001;
    case PlanChanged = 47002;
    case Privilege = 47003;
    case Trigger = 47004;
    case Cascade = 47005;
    case Unsupported = 47006;
    case Digest = 47007;
    case Target = 47008;
    case Invalid = 47009;
    case Visibility = 47010;
    case Overlap = 47011;

    public function key(): string
    {
        return match ($this) {
            self::ProfileMismatch => 'profile_database_mismatch', self::PlanChanged => 'write_plan_changed',
            self::Privilege => 'privilege_outside_plan', self::Trigger => 'destination_trigger',
            self::Cascade => 'protected_cascade', self::Unsupported => 'unsupported_capability',
            self::Digest => 'write_plan_digest_mismatch', self::Target => 'protected_target',
            self::Invalid => 'invalid_write_plan', self::Visibility => 'metadata_visibility', self::Overlap => 'physical_overlap',
        };
    }

    public function exception(string $message): ImportRefusedException
    {
        return new ImportRefusedException($message, $this->value);
    }
}
