<?php

namespace App\Http\Controllers;

use App\Livewire\Partners\Index;
use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `partners.show` (Spec 03 section 24): opens the card of the partner's role the user may see
 * (customer card first, else the supplier card); 403 when none.
 */
class PartnerCardController extends Controller
{
    public function __invoke(Partner $partner): RedirectResponse
    {
        Gate::authorize('view', $partner);
        $url = Index::cardUrl($partner, auth()->user());
        abort_if($url === null, 403);

        return redirect($url);
    }
}
