<div class="space-y-6">
    @php($canManage = auth()->user()->can('manage', \App\Models\AccountingPeriod::class))
    <p class="text-sm text-muted">{{ __('accounting.periods.intro') }}</p>

    <x-flash />

    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm" data-periods>
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3">{{ __('accounting.periods.period') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('accounting.periods.checklist') }}</th>
                    <th scope="col" class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @foreach ($this->months as $month)
                    <tr wire:key="m-{{ $month['period'] }}">
                        <td class="px-4 py-3 font-mono">{{ $month['period'] }}</td>
                        <td class="px-4 py-3">
                            <x-badge :tone="$month['status'] === 'locked' ? 'dark' : 'outline'" :icon="$month['status'] === 'locked' ? 'lock' : null">{{ __('accounting.period_status.'.$month['status']) }}</x-badge>
                            @if ($month['model']?->locker) <span class="block text-xs text-muted">{{ $month['model']->locker->name }}</span> @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-muted">
                            @foreach ($month['checklist'] as $item => $count)
                                <span @class(['mr-2 whitespace-nowrap', 'font-semibold text-brand-700' => $count > 0])>{{ __('accounting.periods.items.'.$item) }}: {{ $count }}</span>
                            @endforeach
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if ($canManage)
                                @if ($month['status'] === 'locked')
                                    <button type="button" wire:click="$set('reopenId', '{{ $month['model']->id }}')" class="btn btn-secondary btn-sm">{{ __('accounting.periods.reopen') }}</button>
                                @else
                                    @if ($lockPeriod === $month['period'] && array_sum($month['checklist']) > 0)
                                        <label class="mr-2 inline-flex items-center gap-1 text-xs text-ink"><input type="checkbox" wire:model="confirmOpenItems" class="size-4 accent-brand-600"> {{ __('accounting.periods.confirm_open_items') }}</label>
                                    @endif
                                    <button type="button" wire:click="lock('{{ $month['period'] }}')" class="btn btn-secondary btn-sm" data-lock="{{ $month['period'] }}">{{ __('accounting.periods.lock') }}</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($reopenId !== '')
        <section class="card border-2 border-brand-600 p-5">
            <h3 class="font-semibold text-ink">{{ __('accounting.periods.reopen') }}</h3>
            <label for="r-reason" class="form-label mt-3">{{ __('accounting.fields.reason') }}</label>
            <textarea wire:model="reopenReason" id="r-reason" rows="2" class="form-input"></textarea>
            @error('reopenReason') <p class="form-error">{{ $message }}</p> @enderror
            <div class="mt-4 flex justify-end gap-3">
                <button type="button" wire:click="$set('reopenId', '')" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                <button type="button" wire:click="reopen" class="btn btn-primary">{{ __('accounting.periods.reopen') }}</button>
            </div>
        </section>
    @endif

    <section class="card p-5" data-fx-rates>
        <h3 class="font-semibold text-ink">{{ __('accounting.periods.rates') }}</h3>
        <p class="mt-1 text-xs text-muted">{{ __('accounting.periods.rates_help') }}</p>
        @if ($canManage)
            <form wire:submit="saveRate" class="mt-4 grid gap-4 sm:grid-cols-4" novalidate>
                <div>
                    <label for="fx-cur" class="form-label">{{ __('accounting.fields.currency') }}</label>
                    <select wire:model="rate.currency" id="fx-cur" class="form-input">
                        @foreach ($currencies as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="fx-date" class="form-label">{{ __('accounting.fields.rate_date') }}</label>
                    <input wire:model="rate.date" id="fx-date" type="date" class="form-input">
                </div>
                <div>
                    <label for="fx-rate" class="form-label">{{ __('accounting.fields.fx_rate') }} (UZS)</label>
                    <input wire:model="rate.rate" id="fx-rate" type="text" inputmode="decimal" class="form-input">
                    @error('rate.rate') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end"><button type="submit" class="btn btn-primary">{{ __('common.save') }}</button></div>
            </form>
        @endif
        <ul class="mt-4 divide-y divide-line text-sm">
            @forelse ($this->rates as $fx)
                <li class="flex justify-between py-2" wire:key="fx-{{ $fx->id }}"><span>{{ $fx->rate_date->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }} · {{ $fx->currency }}</span><span class="font-mono">{{ rtrim(rtrim((string) $fx->rate, '0'), '.') }} UZS</span></li>
            @empty
                <li class="py-2 text-muted">{{ __('accounting.periods.no_rates') }}</li>
            @endforelse
        </ul>
    </section>
</div>
