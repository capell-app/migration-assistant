<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Capell\MigrationAssistant\Actions\BuildImportValidationSummaryAction;
use Capell\MigrationAssistant\Data\Imports\PageImportDecisionData;
use Capell\MigrationAssistant\Data\Imports\PageImportWizardStateData;
use Capell\MigrationAssistant\Data\PageReviewRow;
use Capell\MigrationAssistant\Data\RelationResolveRow;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\PackageReader;
use Capell\MigrationAssistant\Services\Import\ResolutionMap;
use Capell\MigrationAssistant\Services\Import\Resolvers\MatchResolution;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static PageImportWizardStateData run(PageImportDecisionData $decisionData, bool $forceValidation = false)
 */
final class AdvancePageImportToValidationAction
{
    use AsAction;

    public function handle(
        PageImportDecisionData $decisionData,
        bool $forceValidation = false,
    ): PageImportWizardStateData {
        if ($decisionData->sessionId === null) {
            return $this->state($decisionData, 'upload');
        }

        if ($this->hasBlockingWorkspaceConflict($decisionData)) {
            return $this->state(
                $decisionData,
                'review',
                PageImportWizardStateData::NOTICE_BLOCKED_BY_WORKSPACE_CONFLICT,
            );
        }

        if (! $forceValidation && ! $decisionData->shouldSkipResolveStep()) {
            return $this->state($decisionData, 'resolve');
        }

        if (! $decisionData->shouldSkipResolveStep()
            && ! $this->hasValidRelationDecisions($decisionData)) {
            return $this->state(
                $decisionData,
                'resolve',
                PageImportWizardStateData::NOTICE_BLOCKED_PENDING_DECISIONS,
            );
        }

        return $this->validatedState($decisionData);
    }

    private function validatedState(PageImportDecisionData $decisionData): PageImportWizardStateData
    {
        $session = ResolvePageImportSessionAction::run($decisionData->sessionId);
        if (! $session instanceof ImportSession) {
            return $this->state($decisionData, 'upload');
        }

        $package = (new PackageReader)->read(
            Storage::disk('local')->path((string) $session->source_package_path),
        );

        $resolutionMap = $this->hydrateResolutionMap(is_array($session->resolution_map) ? $session->resolution_map : []);
        $pageDecisions = $this->sanitizedPageDecisions($decisionData->pageDecisions);
        $relationDecisions = $this->sanitizedRelationDecisions($decisionData->relationDecisions);

        $summary = (new BuildImportValidationSummaryAction)->run(
            package: $package,
            map: $resolutionMap,
            pageDecisions: $pageDecisions,
            relationDecisions: $relationDecisions,
        );

        $confirmationExpected = ResolvePageImportConfirmationTargetAction::run($session);

        $session->forceFill([
            'page_decisions' => $pageDecisions,
            'relation_decisions' => $relationDecisions,
            'validation_results' => [
                ...$summary->toArray(),
                'confirmation_expected' => $confirmationExpected,
            ],
            'status' => ImportSessionStatus::Validated,
        ])->save();

        return new PageImportWizardStateData(
            step: 'validate',
            sessionId: (int) $session->getKey(),
            reviewRows: $decisionData->reviewRows,
            pageDecisions: $pageDecisions,
            resolveRows: $decisionData->resolveRows,
            relationDecisions: $relationDecisions,
            validationSummary: [
                ...$summary->toArray(),
                'confirmation_expected' => $confirmationExpected,
            ],
            confirmationExpected: $confirmationExpected,
        );
    }

    private function state(
        PageImportDecisionData $decisionData,
        string $step,
        ?string $notice = null,
    ): PageImportWizardStateData {
        return new PageImportWizardStateData(
            step: $step,
            sessionId: $decisionData->sessionId,
            reviewRows: $decisionData->reviewRows,
            pageDecisions: $decisionData->pageDecisions,
            resolveRows: $decisionData->resolveRows,
            relationDecisions: $decisionData->relationDecisions,
            notice: $notice,
        );
    }

    private function hasValidRelationDecisions(PageImportDecisionData $decisionData): bool
    {
        foreach ($decisionData->resolveRows as $row) {
            if (! is_array($row)) {
                return false;
            }

            $ref = (string) ($row['ref'] ?? '');
            $decision = $decisionData->relationDecisions[$ref] ?? null;
            if (! is_array($decision)) {
                return false;
            }

            $action = $decision['action'] ?? '';

            switch ($action) {
                case RelationResolveRow::ACTION_USE_EXISTING:
                    $targetId = $decision['target_id'] ?? null;
                    if ($targetId === null || $targetId === '') {
                        return false;
                    }

                    break;
                case RelationResolveRow::ACTION_UPDATE_EXISTING:
                    if (! $decisionData->canUpdateSharedRelations) {
                        return false;
                    }

                    $targetId = $decision['target_id'] ?? null;
                    if ($targetId === null || $targetId === '') {
                        return false;
                    }

                    break;
                case RelationResolveRow::ACTION_CREATE_NEW:
                case RelationResolveRow::ACTION_CLONE_IMPORTED:
                case RelationResolveRow::ACTION_SKIP:
                    break;
                default:
                    return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, array{action: string, notes?: string}>  $pageDecisions
     * @return array<string, array{action: string, notes?: string}>
     */
    private function sanitizedPageDecisions(array $pageDecisions): array
    {
        $sanitized = [];

        foreach ($pageDecisions as $uuid => $decision) {
            if (! is_string($uuid)) {
                continue;
            }

            if (! is_array($decision)) {
                continue;
            }

            $action = is_string($decision['action'] ?? null) ? $decision['action'] : PageReviewRow::ACTION_CREATE;
            $entry = ['action' => $action];

            if (isset($decision['notes']) && is_string($decision['notes']) && $decision['notes'] !== '') {
                $entry['notes'] = $decision['notes'];
            }

            $sanitized[$uuid] = $entry;
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $persisted
     */
    private function hydrateResolutionMap(array $persisted): ResolutionMap
    {
        $resolvedSource = is_array($persisted['resolved'] ?? null) ? $persisted['resolved'] : [];
        $unresolvedSource = is_array($persisted['unresolved'] ?? null) ? $persisted['unresolved'] : [];

        $resolved = [];
        foreach ($resolvedSource as $ref => $entry) {
            if (! is_string($ref)) {
                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            $resolved[$ref] = $this->matchResolutionFrom($entry);
        }

        $unresolved = array_values(array_filter(
            $unresolvedSource,
            is_string(...),
        ));

        return new ResolutionMap($resolved, $unresolved);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function matchResolutionFrom(array $entry): MatchResolution
    {
        $localId = $entry['local_id'] ?? 0;
        if (! is_int($localId) && ! is_string($localId)) {
            $localId = 0;
        }

        $alternatives = [];
        $alternativesSource = $entry['alternatives'] ?? [];
        if (is_array($alternativesSource)) {
            foreach ($alternativesSource as $alternative) {
                if (is_array($alternative)) {
                    $alternatives[] = $this->matchResolutionFrom($alternative);
                }
            }
        }

        return new MatchResolution(
            localId: $localId,
            strategy: is_string($entry['strategy'] ?? null) ? $entry['strategy'] : '',
            confidence: is_numeric($entry['confidence'] ?? null) ? (float) $entry['confidence'] : 1.0,
            reason: is_string($entry['reason'] ?? null) ? $entry['reason'] : '',
            alternatives: $alternatives,
        );
    }

    /**
     * @param  array<string, array{action: string, target_id?: int|string|null, notes?: string}>  $relationDecisions
     * @return array<string, array{action: string, target_id?: int|string, notes?: string}>
     */
    private function sanitizedRelationDecisions(array $relationDecisions): array
    {
        $sanitized = [];

        foreach ($relationDecisions as $ref => $decision) {
            if (! is_string($ref)) {
                continue;
            }

            if (! is_array($decision)) {
                continue;
            }

            $action = $decision['action'] ?? RelationResolveRow::ACTION_USE_EXISTING;
            $entry = ['action' => $action];

            $targetId = $decision['target_id'] ?? null;
            if (is_int($targetId) || (is_string($targetId) && $targetId !== '')) {
                $entry['target_id'] = $targetId;
            }

            if (isset($decision['notes']) && is_string($decision['notes']) && $decision['notes'] !== '') {
                $entry['notes'] = $decision['notes'];
            }

            $sanitized[$ref] = $entry;
        }

        return $sanitized;
    }

    private function hasBlockingWorkspaceConflict(PageImportDecisionData $decisionData): bool
    {
        foreach ($decisionData->reviewRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (($row['collision_state'] ?? null) !== PageReviewRow::COLLISION_URL_WORKSPACE) {
                continue;
            }

            $uuid = (string) ($row['uuid'] ?? '');
            $action = $decisionData->pageDecisions[$uuid]['action'] ?? PageReviewRow::ACTION_SKIP;
            if ($action !== PageReviewRow::ACTION_SKIP) {
                return true;
            }
        }

        return false;
    }
}
