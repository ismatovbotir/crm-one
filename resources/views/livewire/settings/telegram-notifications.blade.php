<div class="space-y-6" data-telegram-notifications>
    <section class="card p-6" aria-labelledby="tn-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="tn-title" class="text-lg font-semibold text-ink">{{ __('telegram.internal.title') }}</h2>
                <p class="mt-1 max-w-3xl text-sm text-muted">{{ __('telegram.internal.intro') }}</p>
            </div>
            <button type="button" wire:click="newDestination" class="btn btn-primary" data-action="new-destination">{{ __('telegram.internal.add') }}</button>
        </div>

        @if ($this->candidates->isNotEmpty() && $this->destinations->isEmpty())
            <div class="mt-4">
                <p class="text-sm text-muted">{{ __('telegram.internal.candidates') }}</p>
                <ul class="mt-2 flex flex-wrap gap-2">
                    @foreach ($this->candidates as $candidate)
                        <li wire:key="cand-{{ $candidate->id }}">
                            <button type="button" wire:click="newDestination({{ $candidate->chat_id }})" class="btn btn-secondary btn-sm">{{ $candidate->displayName() }}@if ($candidate->isInternal()) · {{ $candidate->purpose->label() }}@endif</button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($showForm)
            <form wire:submit="saveDestination" class="mt-5 grid gap-4 border-t border-line pt-5 sm:grid-cols-2" data-destination-form novalidate>
                <div>
                    <label for="tn-name" class="form-label">{{ __('telegram.internal.fields_ui.name') }}</label>
                    <input wire:model="form.name" id="tn-name" type="text" maxlength="120" class="form-input" placeholder="{{ __('telegram.internal.name_placeholder') }}">
                    @error('form.name') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="tn-chat" class="form-label">{{ __('telegram.internal.fields_ui.chat_id') }}</label>
                    <input wire:model="form.chat_id" id="tn-chat" type="text" inputmode="numeric" list="tn-candidates" class="form-input font-mono" aria-describedby="tn-chat-hint">
                    <datalist id="tn-candidates">
                        @foreach ($this->candidates as $candidate)
                            <option value="{{ $candidate->chat_id }}">{{ $candidate->displayName() }}</option>
                        @endforeach
                    </datalist>
                    <p id="tn-chat-hint" class="mt-1 text-xs text-muted">{{ __('telegram.internal.chat_hint') }}</p>
                    @error('form.chat_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="tn-thread" class="form-label">{{ __('telegram.internal.fields_ui.thread') }}</label>
                    <input wire:model="form.message_thread_id" id="tn-thread" type="text" inputmode="numeric" class="form-input font-mono" aria-describedby="tn-thread-hint">
                    <p id="tn-thread-hint" class="mt-1 text-xs text-muted">{{ __('telegram.internal.thread_hint') }}</p>
                    @error('form.message_thread_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="tn-locale" class="form-label">{{ __('telegram.internal.fields_ui.locale') }}</label>
                    <select wire:model="form.locale" id="tn-locale" class="form-input">
                        @foreach ($locales as $locale)
                            <option value="{{ $locale->value }}">{{ $locale->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-start gap-2 text-sm text-ink">
                    <input wire:model="form.include_amounts" type="checkbox" class="mt-0.5 rounded border-line">
                    <span>{{ __('telegram.internal.fields_ui.include_amounts') }}<span class="block text-xs text-muted">{{ __('telegram.internal.amounts_hint') }}</span></span>
                </label>
                <label class="flex items-center gap-2 text-sm text-ink">
                    <input wire:model="form.is_active" type="checkbox" class="rounded border-line"> {{ __('telegram.internal.fields_ui.active') }}
                </label>
                @if ($leadWarning !== '')
                    <div class="rounded-md border border-brand-600 bg-brand-50 p-3 text-sm text-brand-800 sm:col-span-2" role="alert" data-lead-warning>
                        <p>{{ $leadWarning }}</p>
                        <label class="mt-2 flex items-center gap-2"><input wire:model="confirmLeadClose" type="checkbox" class="rounded border-line" data-confirm-lead-close> {{ __('telegram.internal.confirm_lead_close') }}</label>
                    </div>
                @endif
                <div class="flex justify-end gap-2 sm:col-span-2">
                    <button type="button" wire:click="$set('showForm', false)" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('common.save') }}</button>
                </div>
            </form>
        @endif

        @if ($this->destinations->isNotEmpty())
            <ul class="mt-5 divide-y divide-line rounded-md border border-line" data-destinations>
                @foreach ($this->destinations as $destination)
                    @php($chat = $chats[$destination->chat_id] ?? null)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm" wire:key="dest-{{ $destination->id }}">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">{{ $destination->name }} @unless ($destination->is_active) <x-badge tone="outline">{{ __('telegram.internal.inactive') }}</x-badge> @endunless</p>
                            <p class="text-xs text-muted">
                                <span class="font-mono">{{ $destination->chat_id }}</span>@if ($destination->message_thread_id) · {{ __('telegram.internal.topic', ['id' => $destination->message_thread_id]) }}@endif
                                · {{ \App\Enums\Locale::tryFrom($destination->locale)?->label() }}
                                · {{ __('telegram.internal.bot_status', ['status' => $chat?->bot_status?->label() ?? '—']) }}
                                · {{ __('telegram.internal.last_success', ['at' => $destination->last_success_at?->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) ?? '—']) }}
                            </p>
                            @if ($destination->last_error)
                                <p class="text-xs text-brand-800" data-destination-error>{{ __('telegram.internal.last_error', ['error' => __('telegram.internal.post_errors.'.$destination->last_error)]) }}</p>
                            @endif
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="sendTest({{ $destination->id }})" class="btn btn-secondary btn-sm" data-action="test-destination">{{ __('telegram.internal.send_test') }}</button>
                            <button type="button" wire:click="editDestination({{ $destination->id }})" class="btn btn-secondary btn-sm">{{ __('common.edit') }}</button>
                            <button type="button" wire:click="deleteDestination({{ $destination->id }})" wire:confirm="{{ __('telegram.internal.confirm_delete') }}" class="btn btn-danger-outline btn-sm">{{ __('common.remove') }}</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($this->destinations->isNotEmpty())
        <section class="card" aria-labelledby="tn-matrix">
            <div class="border-b border-line px-6 py-4">
                <h3 id="tn-matrix" class="font-semibold text-ink">{{ __('telegram.internal.matrix_title') }}</h3>
                <p class="text-sm text-muted">{{ __('telegram.internal.matrix_hint') }}</p>
            </div>
            <form wire:submit="saveRouting" novalidate>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-routing-matrix>
                        <thead class="bg-sidebar text-left text-xs text-muted uppercase">
                            <tr>
                                <th scope="col" class="px-4 py-2">{{ __('telegram.internal.event') }}</th>
                                @foreach ($this->destinations as $destination)
                                    <th scope="col" class="px-4 py-2" wire:key="mh-{{ $destination->id }}">{{ $destination->name }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($events as $event)
                                <tr wire:key="mr-{{ $event->value }}">
                                    <th scope="row" class="px-4 py-2 text-left font-medium text-ink">
                                        {{ $event->label() }}
                                        <span class="block text-xs font-normal text-muted">{{ __('telegram.internal.categories.'.$event->category()) }}</span>
                                    </th>
                                    @foreach ($this->destinations as $destination)
                                        <td class="px-4 py-2" wire:key="mc-{{ $event->value }}-{{ $destination->id }}">
                                            <label class="sr-only" for="m-{{ $destination->id }}-{{ $loop->parent->index }}">{{ $event->label() }} · {{ $destination->name }}</label>
                                            <select wire:model="matrix.{{ $destination->id }}.{{ $event->name }}" id="m-{{ $destination->id }}-{{ $loop->parent->index }}" class="form-input">
                                                <option value="">{{ __('telegram.internal.levels.off') }}</option>
                                                <option value="minimal">{{ __('telegram.internal.levels.minimal') }}</option>
                                                <option value="standard">{{ __('telegram.internal.levels.standard') }}</option>
                                            </select>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end border-t border-line px-6 py-4">
                    <button type="submit" class="btn btn-primary" data-action="save-routing">{{ __('common.save') }}</button>
                </div>
            </form>
        </section>

        <form wire:submit="saveSchedule" class="card grid gap-4 p-6 sm:grid-cols-3" aria-labelledby="tn-schedule" novalidate>
            <h3 id="tn-schedule" class="font-semibold text-ink sm:col-span-3">{{ __('telegram.internal.schedule.title') }}</h3>
            <p class="text-sm text-muted sm:col-span-3">{{ __('telegram.internal.schedule.hint') }}</p>
            <div>
                <label for="tn-daily" class="form-label">{{ __('telegram.internal.schedule.daily') }}</label>
                <input wire:model="dailyTime" id="tn-daily" type="time" class="form-input">
                @error('dailyTime') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="tn-weekly-day" class="form-label">{{ __('telegram.internal.schedule.weekly_day') }}</label>
                <select wire:model="weeklyDay" id="tn-weekly-day" class="form-input">
                    @foreach (\App\Services\Telegram\Internal\InternalScheduler::DAYS as $day)
                        <option value="{{ $day }}">{{ __('telegram.internal.days.'.$day) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="tn-weekly-time" class="form-label">{{ __('telegram.internal.schedule.weekly_time') }}</label>
                <input wire:model="weeklyTime" id="tn-weekly-time" type="time" class="form-input">
                @error('weeklyTime') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end sm:col-span-3"><button type="submit" class="btn btn-secondary">{{ __('common.save') }}</button></div>
        </form>

        <section class="card" aria-labelledby="tn-log">
            <h3 id="tn-log" class="border-b border-line px-6 py-4 font-semibold text-ink">{{ __('telegram.internal.log_title') }}</h3>
            @if ($posts->isEmpty())
                <p class="px-6 py-4 text-sm text-muted">{{ __('telegram.internal.log_empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-post-log>
                        <thead class="bg-sidebar text-left text-xs text-muted uppercase">
                            <tr>
                                <th scope="col" class="px-4 py-2">{{ __('telegram.internal.log.when') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('telegram.internal.event') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('telegram.internal.log.destination') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('telegram.internal.log.status') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('telegram.internal.log.error') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($posts as $post)
                                <tr wire:key="post-{{ $post->id }}">
                                    <td class="px-4 py-2 whitespace-nowrap text-muted">{{ $post->created_at?->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) }}</td>
                                    <td class="px-4 py-2 text-ink">{{ \App\Enums\InternalEvent::tryFrom($post->event)?->label() ?? __('telegram.internal.test_title') }}</td>
                                    <td class="px-4 py-2 text-ink">{{ $post->destination?->name }}</td>
                                    <td class="px-4 py-2"><x-badge :tone="$post->status === 'failed' ? 'danger-outline' : 'outline'">{{ __('telegram.internal.post_status.'.$post->status) }}</x-badge></td>
                                    <td class="px-4 py-2 text-muted">{{ $post->error ? __('telegram.internal.post_errors.'.$post->error) : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
