<section class="card p-6" aria-labelledby="pl-title" data-profile-language>
    <h2 id="pl-title" class="font-semibold text-ink">{{ __('users.locale') }}</h2>
    <p class="mt-1 text-sm text-muted">{{ __('users.locale_hint') }}</p>
    <form wire:submit="save" class="mt-3 flex flex-wrap items-end gap-3" novalidate>
        <div>
            <label for="pl-locale" class="sr-only">{{ __('users.locale') }}</label>
            <select wire:model="locale" id="pl-locale" class="form-input">
                @foreach ($locales as $option)
                    <option value="{{ $option->value }}" lang="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            @error('locale') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn btn-secondary">{{ __('common.save') }}</button>
    </form>
</section>
