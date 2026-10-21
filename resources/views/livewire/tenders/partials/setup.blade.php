{{-- Criteria (weights locked at publish), knock-out requirements, evaluation committee. --}}
<div class="space-y-8">
    <section aria-labelledby="criteria-title">
        <h3 id="criteria-title" class="font-semibold text-ink">{{ __('tenders.criteria_title') }}</h3>
        <p class="mt-1 text-sm text-muted">{{ __('tenders.criteria_hint') }}</p>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm" data-criteria>
                <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase">
                    <tr><th scope="col" class="py-2">{{ __('tenders.criteria_fields.criterion') }}</th><th scope="col" class="py-2">{{ __('tenders.criteria_fields.method') }}</th><th scope="col" class="py-2 text-right">{{ __('tenders.criteria_fields.weight') }}</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($criteria as $index => $criterion)
                        <tr wire:key="crit-{{ $index }}">
                            <td class="py-2 pr-2">
                                @if ($editable)
                                    <label for="crit-label-{{ $index }}" class="sr-only">{{ __('tenders.criteria_fields.criterion') }}</label>
                                    <input wire:model="criteria.{{ $index }}.label" id="crit-label-{{ $index }}" type="text" class="form-input" placeholder="{{ trans()->has('tenders.criteria.'.$criterion['code']) ? __('tenders.criteria.'.$criterion['code']) : $criterion['code'] }}">
                                @else
                                    {{ $criterion['label'] ?: (trans()->has('tenders.criteria.'.$criterion['code']) ? __('tenders.criteria.'.$criterion['code']) : $criterion['code']) }}
                                @endif
                            </td>
                            <td class="py-2 pr-2">
                                @if ($editable)
                                    <label for="crit-method-{{ $index }}" class="sr-only">{{ __('tenders.criteria_fields.method') }}</label>
                                    <select wire:model="criteria.{{ $index }}.method" id="crit-method-{{ $index }}" class="form-input">
                                        @foreach ($criterionMethods as $value => $label) <option value="{{ $value }}">{{ $label }}</option> @endforeach
                                    </select>
                                @else
                                    {{ $criterionMethods[$criterion['method']] ?? $criterion['method'] }}
                                @endif
                            </td>
                            <td class="py-2 text-right">
                                @if ($editable)
                                    <label for="crit-weight-{{ $index }}" class="sr-only">{{ __('tenders.criteria_fields.weight') }}</label>
                                    <input wire:model="criteria.{{ $index }}.weight" id="crit-weight-{{ $index }}" type="text" inputmode="decimal" class="form-input w-24 text-right">
                                @else
                                    {{ $criterion['weight'] }}%
                                @endif
                            </td>
                            <td class="py-2 pl-2 text-right">@if ($editable)<button type="button" wire:click="removeCriterion({{ $index }})" class="btn btn-secondary btn-sm">{{ __('tenders.remove') }}</button>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @error('criteria.*') <p class="form-error">{{ $message }}</p> @enderror
        @if ($editable)
            <div class="mt-3 flex justify-between gap-2">
                <button type="button" wire:click="addCriterion" class="btn btn-secondary">{{ __('tenders.criteria_add') }}</button>
                <button type="button" wire:click="saveCriteria" class="btn btn-primary" data-action="save-criteria">{{ __('common.save') }}</button>
            </div>
        @endif
    </section>

    <section aria-labelledby="req-title">
        <h3 id="req-title" class="font-semibold text-ink">{{ __('tenders.requirements.title') }}</h3>
        <ul class="mt-2 divide-y divide-line">
            @forelse ($tender->requirements as $requirement)
                <li class="flex items-center justify-between gap-2 py-2 text-sm" wire:key="req-{{ $requirement->id }}">
                    <span class="text-ink">{{ $requirement->text }} @if ($requirement->is_knockout)<x-badge tone="silver">{{ __('tenders.requirements.knockout') }}</x-badge>@endif</span>
                    @if ($editable)<button type="button" wire:click="removeRequirement({{ $requirement->id }})" class="btn btn-secondary btn-sm">{{ __('tenders.remove') }}</button>@endif
                </li>
            @empty
                <li class="py-2 text-sm text-muted">{{ __('tenders.requirements.empty') }}</li>
            @endforelse
        </ul>
        @if ($editable)
            <form wire:submit="addRequirement" class="mt-3 flex flex-wrap items-end gap-2">
                <div class="min-w-64 flex-1"><label for="req-text" class="form-label">{{ __('tenders.requirements.text') }}</label><input wire:model="requirementText" id="req-text" type="text" class="form-input">@error('requirementText') <p class="form-error">{{ $message }}</p> @enderror</div>
                <label class="flex items-center gap-2 pb-2 text-sm text-ink"><input wire:model="requirementKnockout" type="checkbox" class="rounded border-line"> {{ __('tenders.requirements.knockout') }}</label>
                <button type="submit" class="btn btn-secondary">{{ __('common.add') }}</button>
            </form>
        @endif
    </section>

    <section aria-labelledby="committee-title" data-committee>
        <h3 id="committee-title" class="font-semibold text-ink">{{ __('tenders.committee.title') }}</h3>
        <p class="mt-1 text-sm text-muted">{{ __('tenders.committee.hint') }}</p>
        <ul class="mt-2 divide-y divide-line">
            @forelse ($tender->committee as $member)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" wire:key="cm-{{ $member->id }}">
                    <span class="text-ink">{{ $member->user?->name }} · {{ $member->coi_declared_at ? __('tenders.committee.declared', ['at' => $local($member->coi_declared_at)]) : __('tenders.committee.not_declared') }}</span>
                    <span class="flex gap-2">
                        @if ($member->user_id === $user->id && ! $member->coi_declared_at)
                            <button type="button" wire:click="declareNoConflict" class="btn btn-primary btn-sm" data-action="declare-coi">{{ __('tenders.committee.declare') }}</button>
                        @endif
                        @can('manageCommittee', $tender)
                            <button type="button" wire:click="removeCommitteeMember({{ $member->id }})" class="btn btn-secondary btn-sm">{{ __('tenders.remove') }}</button>
                        @endcan
                    </span>
                </li>
            @empty
                <li class="py-2 text-sm text-muted">{{ __('tenders.committee.empty') }}</li>
            @endforelse
        </ul>
        @can('manageCommittee', $tender)
            <form wire:submit="addCommitteeMember" class="mt-3 flex flex-wrap items-end gap-2">
                <div class="min-w-64 flex-1">
                    <label for="cm-user" class="form-label">{{ __('tenders.committee.member') }}</label>
                    <select wire:model="committeeUserId" id="cm-user" class="form-input">
                        <option value="">—</option>
                        @foreach ($this->committeeCandidates as $candidate) <option value="{{ $candidate->id }}">{{ $candidate->name }}</option> @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary">{{ __('common.add') }}</button>
            </form>
        @endcan
    </section>
</div>
