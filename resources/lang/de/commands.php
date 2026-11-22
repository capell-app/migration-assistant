<?php

declare(strict_types=1);

return [
    'install' => [
        'permissions_installed' => 'Die Berechtigungen für den Migration Assistant sind installiert. Mitarbeiterrollen können jetzt Zugriff auf Import, Export und Rollback erhalten.',
    ],
    'status' => [
        'description' => 'Zeigt den Status von Importsitzungen des Migration Assistant an.',
        'not_found' => 'Die Importsitzung [:session] wurde nicht gefunden.',
        'empty' => 'Keine Importsitzungen gefunden.',
        'columns' => [
            'id' => 'ID',
            'uuid' => 'UUID',
            'kind' => 'Art',
            'status' => 'Status',
            'source' => 'Quelle',
            'executed_at' => 'Ausgeführt am',
        ],
    ],
    'reclaim_stale' => [
        'description' => 'Stellt Importsitzungen, die von beendeten Workern verwaist wurden, erneut in die Warteschlange.',
        'completed' => 'Veraltete Importsitzungen erneut eingereiht: :count.',
    ],
    'export' => [
        'description' => 'Exportiert ein Capell-Migrationspaket anhand von Seiten- oder Site-IDs.',
        'require_scope' => 'Geben Sie entweder eine oder mehrere --page-IDs oder eine oder mehrere --site-IDs an.',
        'completed' => 'Das Migrationspaket wurde nach :path exportiert.',
    ],
    'import' => [
        'description' => 'Erstellt und validiert eine Importsitzung des Migration Assistant und führt sie optional aus.',
        'archive_not_found' => 'Das Archiv des Migrationspakets wurde nicht gefunden.',
        'archive_too_large' => 'Das Migrationspaket überschreitet das in migration-assistant.limits.max_package_upload_kilobytes festgelegte Limit von :size MB. Es wurde nichts importiert.',
        'invalid_kind' => 'Die Importart muss page-import oder site-import sein.',
        'session_not_found' => 'Die Importsitzung konnte nicht erstellt werden.',
        'actor_required' => 'Geben Sie mit --actor=<user id> den Benutzer an, unter dem der Import ausgeführt wird. Die Ausführung eines Imports schreibt Seiten und wird daher anhand der Importberechtigung dieses Benutzers geprüft. Es wurde nichts importiert.',
        'actor_not_found' => 'Kein Benutzer entspricht der --actor-ID. Es wurde nichts importiert. Prüfen Sie die ID und führen Sie den Befehl erneut aus.',
        'actor_denied' => 'Der --actor-Benutzer darf keine Seiten importieren. Es wurde nichts importiert. Erteilen Sie dem Benutzer die Berechtigung zum Seitenimport oder geben Sie einen anderen Benutzer an.',
        'completed' => 'Die Importsitzung :session hat den Status :status.',
    ],
    'rollback_report' => [
        'description' => 'Zeigt den Rollback-Bericht einer Importsitzung des Migration Assistant an.',
        'not_found' => 'Der Rollback-Bericht für die Importsitzung [:session] wurde nicht gefunden.',
        'columns' => [
            'model' => 'Modell',
            'id' => 'ID',
            'type' => 'Datensatz',
            'site' => 'Site',
            'operation' => 'Änderung',
        ],
        'global' => 'Global',
    ],
    'rollback_execute' => [
        'description' => 'Führt einen signierten Rollback-Bericht des Migration Assistant als autorisierter Benutzer aus.',
        'actor_required' => 'Zur Autorisierung der Rollback-Ausführung ist eine gültige --actor-Benutzer-ID erforderlich.',
        'dry_run' => 'Nur Testlauf: Es wurden keine importierten Datensätze gelöscht oder wiederhergestellt.',
        'not_found' => 'Der Rollback-Bericht für die Importsitzung [:session] wurde nicht gefunden.',
        'rejected' => 'Die Rollback-Ausführung wurde abgelehnt, da die Herkunft oder die Autorisierung des Benutzers ungültig ist.',
        'delete_failed' => 'Einige Datensätze konnten nicht entfernt werden und wurden beibehalten. Bitten Sie einen autorisierten Administrator, den Rollback-Bericht und das Anwendungsprotokoll zu prüfen, bevor Sie manuell wiederherstellen.',
        'summary' => 'Übereinstimmende importierte Datensätze: :matched. Gelöscht: :deleted. Wiederhergestellt: :restored. Übersprungen: :skipped.',
        'not_dedicated' => 'Dieser Import wurde nicht in einem dedizierten Prozess ausgeführt. Führen Sie den Rollback daher ohne --dedicated durch.',
        'dedicated_refused' => 'Der dedizierte Rollback-Prozess wurde nicht gestartet und es wurde nichts geändert: :cause',
    ],
];
