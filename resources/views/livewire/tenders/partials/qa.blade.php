{{-- Clarifications (answers published to all invitees without the asker, BR-T.5) and addenda (BR-T.3). --}}
<div class="space-y-8">
    <section aria-labelledby="qa-title" data-qa>
        <h3 id="qa-title" class="font-semibold text-ink">{{ __('tenders.qa.title') }}</h3>
        <ul class="mt-2 divide-y divide-line">
            @forelse ($tender->questions as $question)
                <li class="py-3 text-sm" wire:key="q-{{ $question->id }}">
                    <p class="text-ink"><span class="font-semibold">{{ __('tenders.qa.q') }}</span> {{ $question->question }}</p>
                    <p class="text-xs text-muted">{{ __('tenders.qa.internal_source', ['counterparty' => $question->counterparty?->name ?? '—', 'via' => $question->received_via ? __('tenders.vocab.received_via.'.$question->received_via) : '—']) }}</p>
                    @if ($question->answer)
                        <p class="mt-1 text-ink"><span class="font-semibold">{{ __('tenders.qa.a') }}</span> {{ $question->answer }} <span class="text-xs text-muted">· {{ __('tenders.qa.published', ['at' => $local($question->published_at)]) }}</span></p>
                    @else
                        @can('manageQuestions', $tender)
                            <div class="mt-2 flex flex-wrap items-end gap-2">
                                <div class="min-w-64 flex-1"><label for="ans-{{ $question->id }}" class="sr-only">{{ __('tenders.qa.answer') }}</label><textarea wire:model="answers.{{ $question->id }}" id="ans-{{ $question->id }}" rows="2" class="form-input" placeholder="{{ __('tenders.qa.answer') }}"></textarea></div>
                                <button type="button" wire:click="answerQuestion({{ $question->id }})" class="btn btn-primary btn-sm">{{ __('tenders.qa.publish_answer') }}</button>
                            </div>
                        @endcan
                    @endif
                </li>
            @empty
                <li class="py-2 text-sm text-muted">{{ __('tenders.qa.empty') }}</li>
            @endforelse
        </ul>
        @can('manageQuestions', $tender)
            <form wire:submit="recordQuestion" class="mt-3 grid gap-3 border-t border-line pt-4 sm:grid-cols-3">
                <div>
                    <label for="q-cp" class="form-label">{{ __('tenders.qa.from') }}</label>
                    <select wire:model="question.counterparty_id" id="q-cp" class="form-input">
                        <option value="">—</option>
                        @foreach ($tender->invitations as $invitation) <option value="{{ $invitation->counterparty_id }}">{{ $invitation->counterparty?->name }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label for="q-via" class="form-label">{{ __('tenders.qa.via') }}</label>
                    <select wire:model="question.received_via" id="q-via" class="form-input">
                        @foreach (config('tenders.vocab.received_via') as $via) <option value="{{ $via }}">{{ __('tenders.vocab.received_via.'.$via) }}</option> @endforeach
                    </select>
                </div>
                <div class="sm:col-span-3"><label for="q-text" class="form-label">{{ __('tenders.qa.question') }}</label><textarea wire:model="question.question" id="q-text" rows="2" class="form-input"></textarea>@error('question.question') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div class="flex justify-end sm:col-span-3"><button type="submit" class="btn btn-secondary">{{ __('tenders.qa.record') }}</button></div>
            </form>
        @endcan
    </section>

    <section aria-labelledby="add-title" data-addenda>
        <h3 id="add-title" class="font-semibold text-ink">{{ __('tenders.addenda.title') }}</h3>
        <ul class="mt-2 divide-y divide-line">
            @forelse ($tender->addenda as $addendum)
                <li class="py-3 text-sm" wire:key="ad-{{ $addendum->id }}">
                    <p class="text-ink"><span class="font-semibold">{{ __('tenders.version', ['version' => $addendum->version]) }}</span> · {{ $addendum->summary }}</p>
                    @if ($addendum->changes)<p class="whitespace-pre-line text-muted">{{ $addendum->changes }}</p>@endif
                    @if ($addendum->new_bids_due_at)<p class="text-xs text-muted">{{ __('tenders.addenda.new_due', ['at' => $local($addendum->new_bids_due_at).' '.$tz]) }}</p>@endif
                </li>
            @empty
                <li class="py-2 text-sm text-muted">{{ __('tenders.addenda.empty') }}</li>
            @endforelse
        </ul>
        @can('addAddendum', $tender)
            <form wire:submit="addAddendum" class="mt-3 grid gap-3 border-t border-line pt-4 sm:grid-cols-3">
                <div class="sm:col-span-2"><label for="ad-sum" class="form-label">{{ __('tenders.addenda.summary') }}</label><input wire:model="addendum.summary" id="ad-sum" type="text" class="form-input">@error('addendum.summary') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label for="ad-due" class="form-label">{{ __('tenders.addenda.new_bids_due_at') }} ({{ $tz }})</label><input wire:model="addendum.new_bids_due_at" id="ad-due" type="datetime-local" class="form-input"></div>
                <div class="sm:col-span-3"><label for="ad-ch" class="form-label">{{ __('tenders.addenda.changes') }}</label><textarea wire:model="addendum.changes" id="ad-ch" rows="3" class="form-input"></textarea></div>
                <p class="text-xs text-muted sm:col-span-2">{{ __('tenders.addenda.hint', ['hours' => (int) config('tenders.addendum_min_hours')]) }}</p>
                <div class="flex justify-end"><button type="submit" class="btn btn-primary">{{ __('tenders.addenda.issue') }}</button></div>
            </form>
        @endcan
    </section>
</div>
