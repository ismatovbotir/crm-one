<?php

use App\Http\Controllers\Api\V1\Integration\OneC\CustomerExchangeController;
use App\Http\Controllers\Api\V1\Integration\OneC\GoodsReceiptExchangeController;
use App\Http\Controllers\Api\V1\Integration\OneC\InboundController;
use App\Http\Controllers\Api\V1\Integration\OneC\OutboxController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Telegram\WebhookController;
use App\Http\Middleware\Integration\AuthenticateIntegrationToken;
use App\Http\Middleware\Integration\EnsureIntegrationEnabled;
use App\Http\Middleware\Integration\EnsureIntegrationIpAllowed;
use App\Http\Middleware\Integration\EnsureJsonPayload;
use App\Http\Middleware\Integration\EnsureTokenAbility;
use App\Http\Middleware\Integration\LogIntegrationRequest;
use App\Http\Middleware\Integration\RequireHttps;
use App\Http\Middleware\Integration\VerifyIntegrationSignature;
use Illuminate\Support\Facades\Route;

/*
| 1C -> CRM inbound API (Spec 02 section 9). Stateless: the `api` group has no session, cookies
| or CSRF. Middleware order per Spec 02 7.1 (the framework sorts `throttle` ahead of token
| auth, so the limiter keys on the hash of the presented token; see AppServiceProvider).
*/

Route::prefix('v1/integration/1c')
    ->name('api.onec.')
    ->middleware([
        LogIntegrationRequest::class,
        RequireHttps::class,
        AuthenticateIntegrationToken::class,
        EnsureIntegrationIpAllowed::class,
        VerifyIntegrationSignature::class,
        'throttle:onec-api',
    ])
    ->group(function () {
        Route::get('/ping', [InboundController::class, 'ping'])->name('ping');

        Route::get('/batches/{batchId}', [InboundController::class, 'showBatch'])
            ->middleware(EnsureTokenAbility::class.':batches:read')
            ->name('batches.show');

        // Ability, then enabled switch, then body checks. /batch checks per-section abilities itself.
        $writes = [EnsureIntegrationEnabled::class, EnsureJsonPayload::class];

        Route::post('/items', [InboundController::class, 'items'])->middleware([EnsureTokenAbility::class.':items:write', ...$writes])->name('items');
        Route::post('/item-stock', [InboundController::class, 'itemStock'])->middleware([EnsureTokenAbility::class.':stock:write', ...$writes])->name('item-stock');
        Route::post('/batch', [InboundController::class, 'batch'])->middleware($writes)->name('batch');

        // Customer exchange (Spec 02 rev. 3 section 10): 1C polls the outbox, acks, and pushes its customers.
        $customers = EnsureIntegrationEnabled::class.':customers';

        Route::get('/customers/pending', [CustomerExchangeController::class, 'pending'])->middleware([EnsureTokenAbility::class.':customers:read', $customers])->name('customers.pending');
        Route::post('/customers/ack', [CustomerExchangeController::class, 'ack'])->middleware([EnsureTokenAbility::class.':customers:write', $customers, EnsureJsonPayload::class])->name('customers.ack');
        Route::post('/customers', [CustomerExchangeController::class, 'upsert'])->middleware([EnsureTokenAbility::class.':customers:write', $customers, EnsureJsonPayload::class])->name('customers.upsert');

        // Document outbox and payments (Spec 06 section 9): the feed ability is checked per feed.
        Route::get('/outbox/pending', [OutboxController::class, 'pending'])->middleware(EnsureIntegrationEnabled::class)->name('outbox.pending');
        Route::post('/outbox/ack', [OutboxController::class, 'ack'])->middleware([EnsureIntegrationEnabled::class, EnsureJsonPayload::class])->name('outbox.ack');
        Route::post('/payments', [OutboxController::class, 'payments'])->middleware([EnsureTokenAbility::class.':payments:write', ...$writes])->name('payments');

        // Goods receipts created in 1C (Spec 06 26, Spec 02 rev. 5 10.6): push, or update one by its CRM id.
        Route::post('/goods-receipts', [GoodsReceiptExchangeController::class, 'push'])->middleware([EnsureTokenAbility::class.':warehouse:write', ...$writes])->name('goods-receipts.push');
        Route::put('/goods-receipts/{crmId}', [GoodsReceiptExchangeController::class, 'update'])->middleware([EnsureTokenAbility::class.':warehouse:write', ...$writes])->name('goods-receipts.update');
    });

// Telegram bot webhook (Spec 03 4.6): secret-header authenticated, deduplicated by update_id.
Route::post('telegram/webhook', WebhookController::class)->name('telegram.webhook')->middleware('throttle:120,1');

// Monitoring (roadmap step 10): overall status for everyone, details with X-Health-Token.
Route::get('health', HealthController::class)->name('health')->middleware('throttle:30,1');
