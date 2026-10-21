<?php

namespace App\Http\Controllers;

use App\Authorization\PermissionMap;
use App\Documents\DocumentLanguage;
use App\Documents\DocumentRenderer;
use App\Enums\DunningStage;
use App\Enums\OrderStatus;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Accounting\CarbonDays;
use App\Services\Accounting\OrderPayments;
use App\Support\CompanyProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Printed payment reminder letter (Spec 06 section 12, route `receivables.reminder.print`): the
 * customer's schedule items that are due soon or overdue, grouped by currency, in the document
 * language of the customer (or `?lang=`). Only orders the user may see in receivables are listed.
 */
class PaymentReminderPrintController extends Controller
{
    public function __invoke(Request $request, Customer $customer, DocumentRenderer $renderer, OrderPayments $payments): View
    {
        Gate::authorize('view', $customer);
        Gate::authorize('viewReceivables', AccountingPeriod::class);
        abort_unless(PermissionMap::allowsFor($request->user(), 'receivables.view', $customer->owner_id), 403);

        $language = DocumentLanguage::resolve($request->query('lang'), $customer);
        $today = AccountingPeriods::today();
        $rows = [];

        $orders = PermissionMap::applyScope(Order::query(), $request->user(), 'receivables.view')
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [OrderStatus::New, OrderStatus::Cancelled, OrderStatus::Closed])
            ->orderBy('id')->get();

        foreach ($orders as $order) {
            foreach ($payments->coverage($order) as $row) {
                if ($row['outstanding'] <= 0 || $row['item']->due_on === null) {
                    continue;
                }

                $days = (int) CarbonDays::between($row['item']->due_on->toDateString(), $today->toDateString());

                if (DunningStage::reached($days) === null) {
                    continue;
                }

                $rows[$order->currency][] = ['order' => $order, 'due_on' => $row['item']->due_on, 'outstanding' => $row['outstanding'], 'days_overdue' => max(0, $days)];
            }
        }

        ksort($rows);

        return $renderer->print('documents.payment_reminder', [
            'customer' => $customer,
            'rows' => $rows,
            'seller' => CompanyProfile::seller($language),
            'date' => $today,
        ], $language, __('documents.reminder.title', [], $language->value).' · '.$customer->name, route('receivables.index'));
    }
}
