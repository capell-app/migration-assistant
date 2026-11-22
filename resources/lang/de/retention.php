<?php

declare(strict_types=1);

return [
    'description' => 'Vorschau der Aufbewahrung und Speicherung von Importnachweisen; mit --prune werden berechtigte Nachweise gelöscht.',
    'report' => ':mode (:days Tage): :sessions Sitzungen, :bytes Byte Archivgröße; :eligible berechtigte Sitzungen, :eligible_bytes berechtigte Byte; :pruned bereinigt, :failed fehlgeschlagen.',
    'preview' => 'Vorschau',
    'prune' => 'Bereinigen',
    'eligible' => 'Berechtigte Sitzung: :id',
    'failed' => 'Sitzung :id konnte nicht verarbeitet werden; die Nachweise wurden beibehalten. Prüfen Sie das Anwendungsprotokoll, bevor Sie es erneut versuchen.',
    'hold' => 'Rechtliche Sperre setzen',
    'release' => 'Rechtliche Sperre aufheben',
];
