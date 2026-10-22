<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\LabelTemplate;
use App\Models\Setting;
use App\Support\Labels\LabelData;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The label print sheet: one page per label at the template's exact size (@page), opened from the
 * print screen with a one-off job token kept in the session. The browser prints it through the
 * Windows driver of the chosen label printer.
 */
class LabelSheetController extends Controller
{
    public function __invoke(Request $request): View
    {
        Gate::authorize('viewAny', Item::class);

        $job = $request->session()->get('label_jobs.'.$request->query('job'));
        abort_unless(is_array($job), 404);

        $template = LabelTemplate::query()->findOrFail($job['template_id']);
        $items = Item::query()->with(['unit', 'category'])->whereIn('id', array_column($job['rows'], 'item_id'))->get()->keyBy('id');
        $serials = ItemSerial::query()->whereIn('id', array_filter(array_column($job['rows'], 'serial_id')))->get()->keyBy('id');
        $labels = [];

        foreach ($job['rows'] as $row) {
            $item = $items->get($row['item_id']);

            if ($item === null) {
                continue;
            }

            $values = LabelData::forItem($item, $row['serial_id'] ? $serials->get($row['serial_id']) : null);

            for ($copy = 0; $copy < $row['copies']; $copy++) {
                $labels[] = $values;
            }
        }

        return view('labels.sheet', [
            'template' => $template,
            'labels' => $labels,
            'logo' => Setting::logoUrl(),
            'backUrl' => route('labels.print'),
        ]);
    }
}
