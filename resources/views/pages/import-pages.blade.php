<div>
    @php
        $journey = [
            'upload' => __('migration-assistant::imports.journey.source'),
            'review' => __('migration-assistant::imports.journey.review'),
            'resolve' => __('migration-assistant::imports.journey.resolve'),
            'validate' => __('migration-assistant::imports.journey.validate'),
            'executing' => __('migration-assistant::imports.journey.import'),
            'completed' => __('migration-assistant::imports.journey.result'),
        ];
        $journeyKeys = array_keys($journey);
        $journeyStep = $step === 'failed' ? 'completed' : $step;
        $currentIndex = array_search($journeyStep, $journeyKeys, true);
    @endphp

    <nav
        aria-label="{{ __('migration-assistant::imports.journey.current') }}"
        class="mb-6 overflow-x-auto"
    >
        <ol class="flex min-w-max items-center gap-2 text-sm">
            @foreach ($journey as $key => $label)
                @php
                    $index = array_search($key, $journeyKeys, true);
                    $active = $key === $journeyStep;
                    $done = is_int($currentIndex) && $index < $currentIndex;
                @endphp
                <li class="flex items-center gap-2">
                    <span
                        class="inline-flex size-7 items-center justify-center rounded-full {{ $active ? 'bg-primary-600 text-white' : ($done ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300') }}"
                        aria-current="{{ $active ? 'step' : 'false' }}"
                    >
                        {{ $index + 1 }}
                    </span>
                    <span
                        class="{{ $active ? 'font-semibold' : 'text-gray-600 dark:text-gray-300' }}"
                        >{{ $label }}</span
                    >
                    @if (! $loop->last)
                        <span
                            class="text-gray-400"
                            aria-hidden="true"
                            >→</span
                        >
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>

    @if ($step === 'review')
        @php
        $blockingReviewRows = array_values(array_filter($reviewRows, fn (array $row): bool => ($row['collision_state'] ?? null) !== null && ($row['collision_state'] ?? null) !== 'none'));
        $unresolvedRelations = array_values(array_filter($resolveRows, fn (array $row): bool => ($row['top_match'] ?? null) === null || ! isset($relationDecisions[$row['ref'] ?? ''])));
    @endphp
        @if ($blockingReviewRows !== [] || $unresolvedRelations !== [])
            <section
                class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 dark:border-rose-900 dark:bg-rose-950"
                aria-labelledby="migration-review-blockers"
            >
                <h2
                    id="migration-review-blockers"
                    class="font-semibold text-rose-800 dark:text-rose-200"
                >
                    {{ __('migration-assistant::imports.journey.review_blocking_heading') }}
                </h2>
                <p class="mt-1 text-sm text-rose-700 dark:text-rose-200">
                    {{ __('migration-assistant::imports.journey.review_blocking_body', ['count' => count($blockingReviewRows) + count($unresolvedRelations)]) }}
                </p>
            </section>
        @endif
        <details
            class="mb-6 rounded-lg border border-gray-200 p-4 dark:border-gray-700"
        >
            <summary class="cursor-pointer font-medium">
                {{ __('migration-assistant::imports.journey.technical_details') }}
            </summary>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('migration-assistant::imports.journey.technical_details_body') }}</p>
            <dl class="mt-3 space-y-3 text-sm">
                @foreach ($resolveRows as $row)
                    @if (is_array($row['top_match'] ?? null))
                        <div>
                            <dt class="font-medium">{{ $row['ref'] }}</dt>
                            <dd>{{ $row['top_match']['reason'] }}</dd>
                            <dd class="text-gray-600 dark:text-gray-300">
                                {{ __('migration-assistant::imports.journey.match_details', ['target' => $row['top_match']['local_id'], 'strategy' => $row['top_match']['strategy'], 'confidence' => (int) round($row['top_match']['confidence'] * 100)]) }}
                            </dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        </details>
    @elseif ($step === 'validate')
        <div
            class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200"
        >
            {{ __('migration-assistant::imports.journey.write_boundary') }}
        </div>
    @endif

    {{-- The existing state-specific form remains the canonical interaction surface. --}}
    @include('capell-admin::components.pages.import-pages')

    @if ($step === 'completed')
        <section
            class="mt-6 rounded-lg border border-gray-200 p-4 dark:border-gray-700"
            aria-labelledby="migration-result-follow-up"
        >
            <h2
                id="migration-result-follow-up"
                class="font-semibold"
            >
                {{ __('migration-assistant::imports.journey.result_follow_up_heading') }}
            </h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('migration-assistant::imports.journey.result_follow_up_body') }}</p>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('migration-assistant::imports.journey.rollback_report_reference') }}</p>
        </section>
    @endif
</div>
