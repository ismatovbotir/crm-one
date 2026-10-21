<?php

namespace App\Livewire\Search;

use App\Services\Search\GlobalSearch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Global search page (roadmap step 5): results grouped by module, only visible records; the top
 * bar search box submits here (GET ?q=).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    #[Url(except: '')]
    public string $q = '';

    public function render(GlobalSearch $search): View
    {
        $this->q = mb_substr($this->q, 0, 100);

        return view('livewire.search.index', [
            'groups' => $search->search(auth()->user(), $this->q),
            'tooShort' => mb_strlen(trim($this->q)) < GlobalSearch::MIN_LENGTH,
        ])->title(__('search.title'));
    }
}
