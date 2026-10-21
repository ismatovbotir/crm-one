<?php

use App\Actions\Accounting\CloseOrder;
use App\Actions\Agreements\RunAgreementLifecycle;
use App\Actions\Counterparties\ManageCounterparty;
use App\Actions\Leads\AnonymiseClosedLeads;
use App\Actions\Offers\ExpireOffers;
use App\Actions\Orders\StartOrder;
use App\Actions\Tenders\TenderWorkflow;
use App\Actions\Users\EnsureAdminUser;
use App\Enums\OrderStatus;
use App\Models\CustomerAsset;
use App\Models\InboundBatch;
use App\Models\InboundBatchPayload;
use App\Models\InboundSnapshot;
use App\Models\IntegrationLog;
use App\Models\LeadFollowUp;
use App\Models\OnecOutboxEvent;
use App\Models\Order;
use App\Models\SerialImportBatch;
use App\Models\User;
use App\Notifications\LeadFollowUpDue;
use App\Notifications\WarrantyExpiring;
use App\Services\Accounting\LedgerVerifier;
use App\Services\Accounting\RunDunning;
use App\Services\Maintenance\BackupManager;
use App\Services\Maintenance\DemoDataWiper;
use App\Services\Maintenance\ProductionCheck;
use App\Services\Maintenance\SetupChecklist;
use App\Services\Notifications\Notifier;
use App\Services\OneC\Customers\CustomerOutbox;
use App\Services\OneC\Inbound\SnapshotFinalizer;
use App\Services\Stock\Import\SerialImporter;
use App\Services\Telegram\Internal\InternalScheduler;
use App\Support\BusinessSettings;
use App\Support\OpenApi\OneCIntegrationSpec;
use App\Support\OpenApi\YamlEmitter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| 1C inbound API housekeeping (Spec 02 7.1 schedule). Needs the scheduler running
| (Windows Task Scheduler on OSPanel, cron on Linux) and a queue worker:
|   php artisan queue:work --queue=onec-inbound,default --max-time=3600
*/

Artisan::command('onec:expire-snapshots', function (SnapshotFinalizer $finalizer) {
    $this->info($finalizer->expireStale().' snapshot(s) expired.');
})->purpose('Expire 1C full snapshots whose parts did not all arrive within the TTL');

Artisan::command('onec:queue-customers', function (CustomerOutbox $outbox) {
    $this->info($outbox->queueNotSynced().' customer(s) queued for 1C.');
})->purpose('Queue every pushable not-synced CRM customer in the 1C outbox (run after enabling the customer exchange)');

Artisan::command('offers:expire', function (ExpireOffers $expire) {
    $this->info($expire->handle().' offer(s) expired.');
})->purpose('Expire open offers whose validity date has passed (Spec 05, Asia/Tashkent)');

Schedule::command('offers:expire')->hourly()->withoutOverlapping()->onOneServer();

Schedule::command('onec:expire-snapshots')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('model:prune', ['--model' => [InboundBatch::class, InboundBatchPayload::class, InboundSnapshot::class, IntegrationLog::class]])->dailyAt('03:30')->withoutOverlapping()->onOneServer();

// DB review F-22: queue housekeeping (database queue).
Schedule::command('queue:prune-failed', ['--hours' => 720])->dailyAt('03:40')->onOneServer();
Schedule::command('queue:prune-batches', ['--hours' => 168])->dailyAt('03:45')->onOneServer();

Artisan::command('agreements:lifecycle', function (RunAgreementLifecycle $lifecycle) {
    $result = $lifecycle->handle();
    $this->info(sprintf('%d activated, %d expired, %d renewed, %d reminder(s).', $result['activated'], $result['expired'], $result['renewed'], $result['reminders']));
})->purpose('Activate, expire and renew agreements; record expiry and unsigned reminders (Spec 06 BR-AG.8)');

Schedule::command('agreements:lifecycle')->dailyAt('00:05')->timezone('Asia/Tashkent')->withoutOverlapping()->onOneServer();

Artisan::command('accounting:nightly', function (LedgerVerifier $verifier) {
    $result = $verifier->run();
    $this->info(sprintf('%d payments, %d orders checked; %d difference(s); %d payment status(es) updated.', $result['payments_checked'], $result['orders_checked'], count($result['differences']), $result['statuses_updated']));

    foreach (array_slice($result['differences'], 0, 20) as $difference) {
        $this->warn($difference);
    }
})->purpose('Recompute order payment statuses (overdue) and verify cached balances against allocations (Spec 06 BR-AC.15/18)');

Artisan::command('orders:auto-close', function (CloseOrder $close) {
    $this->info(BusinessSettings::bool('accounting.auto_close_orders') ? $close->closeEligible().' order(s) closed.' : 'Auto-close is switched off.');
})->purpose('Close invoiced, paid orders with a binding agreement (Spec 06 BR-OR.11)');

Schedule::command('accounting:nightly')->dailyAt('00:15')->timezone('Asia/Tashkent')->withoutOverlapping()->onOneServer();

$ensureAdmin = function (EnsureAdminUser $ensure) {
    try {
        $result = $ensure->handle(config('crm.admin.name'), config('crm.admin.email'), config('crm.admin.password'));
    } catch (InvalidArgumentException $exception) {
        $this->error($exception->getMessage().' Set ADMIN_NAME, ADMIN_EMAIL and ADMIN_PASSWORD in .env.');

        return 1;
    }

    $user = $result['user'];

    if ($result['created']) {
        $this->info(sprintf('Administrator created: #%d %s <%s>.', $user->id, $user->name, $user->email));

        if ($result['id_one_taken']) {
            $this->warn('User id 1 already belongs to someone else, so this administrator got id '.$user->id.'.');
        }

        return 0;
    }

    $this->info(sprintf('Password reset for #%d <%s> (%s).', $user->id, $user->email, implode(', ', $result['changes'])));
    $this->comment('Remove ADMIN_PASSWORD from .env now that it is used.');

    return 0;
};

foreach (['crm:admin', 'admin:login'] as $adminCommand) {
    Artisan::command($adminCommand, $ensureAdmin)->purpose('Create the default administrator (id 1) from ADMIN_* in .env, or reset its password when the e-mail exists');
}

Artisan::command('accounting:dunning', function (RunDunning $dunning) {
    $result = $dunning->run();
    $this->info(sprintf('%d schedule item(s) at a reminder stage; %d internal and %d customer reminder(s) sent; %d accountant digest(s).', $result['items'], $result['internal'], $result['customer'], $result['digest']));
})->purpose('Send payment reminders once per schedule item and stage; customer reminders only when switched on (Spec 06 BR-AC.17)');

Schedule::command('accounting:dunning')->dailyAt('09:00')->timezone('Asia/Tashkent')->withoutOverlapping()->onOneServer();
Schedule::command('orders:auto-close')->dailyAt('01:00')->timezone('Asia/Tashkent')->withoutOverlapping()->onOneServer();
Schedule::command('model:prune', ['--model' => [OnecOutboxEvent::class]])->dailyAt('03:50')->onOneServer();

Artisan::command('tenders:tick', function (TenderWorkflow $workflow) {
    $this->info($workflow->closeDue().' tender(s) closed and opened.');
})->purpose('Close published tenders whose bid deadline passed and open their sealed bids (Spec 03 6.1, idempotent)');

Artisan::command('counterparties:expire-documents', function (ManageCounterparty $counterparties) {
    $this->info($counterparties->expireDocuments().' counterparty(ies) back to pending qualification.');
})->purpose('Send qualified suppliers/carriers with an expired document back to pending qualification (Spec 03 6.2)');

Schedule::command('tenders:tick')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('counterparties:expire-documents')->dailyAt('00:20')->timezone('Asia/Tashkent')->withoutOverlapping()->onOneServer();

Artisan::command('auth:prune', function () {
    $deleted = DB::table('login_codes')->where('expires_at', '<', now()->subDay())->delete()
        + DB::table('telegram_link_tokens')->where('expires_at', '<', now()->subDay())->delete()
        + DB::table('telegram_updates')->where('received_at', '<', now()->subDays(7))->delete()
        + DB::table('auth_events')->where('created_at', '<', now()->subDays((int) config('auth.auth_events_retention_days', 365)))->delete();
    $this->info($deleted.' expired authentication row(s) pruned.');
})->purpose('Prune expired one-time codes, Telegram link tokens, processed webhook ids (7 days) and old auth events (Spec 03 7.1)');

Schedule::command('auth:prune')->dailyAt('03:55')->onOneServer();

Artisan::command('stock:prune-serial-imports', function () {
    $files = 0;
    $previews = 0;

    SerialImportBatch::query()->whereNotNull('disk_path')->where('expires_at', '<', now())->orderBy('id')->each(function (SerialImportBatch $batch) use (&$files, &$previews): void {
        Storage::disk(SerialImporter::DISK)->delete((string) $batch->disk_path);
        $batch->forceFill(['disk_path' => null])->save();
        $files++;

        if ($batch->committed_at === null) {
            $previews += $batch->rows()->delete();
        }
    });

    $this->info($files.' expired serial file(s) deleted, '.$previews.' preview row(s) of uncommitted imports removed.');
})->purpose('Delete serial import files after the retention period and preview rows of imports never committed; batch metadata stays (Spec 06 21.8)');

Schedule::command('stock:prune-serial-imports')->dailyAt('04:05')->onOneServer();

Artisan::command('leads:remind', function () {
    $sent = 0;

    LeadFollowUp::query()->with(['lead', 'assignee'])->whereNull('completed_at')->whereNull('reminded_at')->whereNotNull('remind_at')->where('remind_at', '<=', now())
        ->orderBy('id')->each(function (LeadFollowUp $followUp) use (&$sent): void {
            $followUp->forceFill(['reminded_at' => now()])->save();
            $followUp->assignee?->notify(new LeadFollowUpDue($followUp));
            $sent++;
        });

    $this->info($sent.' follow-up reminder(s) sent.');
})->purpose('Send due lead follow-up reminders once (Spec 04 section 16)');

Schedule::command('leads:remind')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

Artisan::command('notifications:scan', function (Notifier $notifier) {
    $counts = $notifier->scanDeadlines();
    $this->info($counts['tenders'].' tender deadline and '.$counts['offers'].' offer expiry notification(s) sent.');
})->purpose('Notify owners of tenders closing within 24 hours and offers expiring within 2 days, once each (roadmap step 4)');

Schedule::command('notifications:scan')->hourly()->withoutOverlapping()->onOneServer();

// Spec 03 section 23: daily/weekly reports and overdue posts to the company Telegram groups.
Artisan::command('telegram:internal-tick', function (InternalScheduler $scheduler) {
    $this->info(json_encode($scheduler->tick()));
})->purpose('Post scheduled internal Telegram reports (Spec 03 section 23)');

Schedule::command('telegram:internal-tick')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

Artisan::command('crm:wipe-demo {--catalog : Also remove items, units, categories, stock and serials} {--force : Required in production} {--allow-real : Wipe even if non-demo users own records (never in production)}', function (DemoDataWiper $wiper) {
    if (app()->isProduction() && ! $this->option('force')) {
        $this->error('Refused: run with --force in production.');

        return 1;
    }

    $real = $wiper->realOwners();

    if ($real !== [] && (! $this->option('allow-real') || app()->isProduction())) {
        $this->error('Refused: records belong to non-demo users: '.implode(', ', array_slice($real, 0, 10)));

        return 1;
    }

    if (! $this->confirm('Delete all demo business data'.($this->option('catalog') ? ' and the catalog' : '').'? This cannot be undone.', ! $this->input->isInteractive())) {
        return 1;
    }

    foreach ($wiper->wipe((bool) $this->option('catalog')) as $table => $count) {
        $this->line($table.': '.$count);
    }

    $this->info('Demo data removed.');

    return 0;
})->purpose('Remove demo data before go-live, guarded against real data (roadmap step 7)');

// Roadmap step 7: the setup checklist shows whether the scheduler runs.
Schedule::call(fn () => Cache::put(SetupChecklist::HEARTBEAT_KEY, now()->timestamp, now()->addHour()))->name('scheduler-heartbeat')->everyMinute()->onOneServer();

// Roadmap step 10: backup / restore (docs/operations.md). Archives stay in BACKUP_PATH.
Artisan::command('crm:backup {--list : Show existing backups} {--verify= : Check an archive without restoring}', function (BackupManager $backups) {
    if ($this->option('list')) {
        $this->table(['Archive', 'Size (MB)', 'Created'], array_map(fn (array $b): array => [$b['name'], number_format($b['bytes'] / 1048576, 1), date('Y-m-d H:i', $b['created_at'])], $backups->list()));

        return 0;
    }

    if (filled($this->option('verify'))) {
        $manifest = $backups->verify((string) $this->option('verify'));
        $this->info('Archive OK: '.$manifest['driver'].' backup from '.$manifest['created_at'].', '.count($manifest['checksums']).' entries verified.');

        return 0;
    }

    $result = $backups->backup();
    $this->info('Backup written: '.$result['path'].' ('.number_format($result['bytes'] / 1048576, 1).' MB, '.$result['entries'].' entries).');

    return 0;
})->purpose('Back up the database and stored files to BACKUP_PATH (roadmap step 10)');

Artisan::command('crm:restore {archive : Path of a crm-backup-*.zip} {--force : Required in production}', function (BackupManager $backups) {
    $archive = (string) $this->argument('archive');

    if (app()->isProduction() && ! $this->option('force')) {
        $this->error('Refused: run with --force in production.');

        return 1;
    }

    $manifest = $backups->verify($archive);
    $this->line('Archive OK: '.$manifest['driver'].' backup from '.$manifest['created_at'].'.');

    if (! $this->confirm('Replace the current database and stored files with this backup? Users are logged out of open forms.', ! $this->input->isInteractive())) {
        return 1;
    }

    $this->call('down', ['--retry' => 60]);

    try {
        $result = $backups->restore($archive);
    } finally {
        $this->call('up');
    }

    $this->info('Restored '.$result['database'].' and '.$result['files'].' files.'.($result['safety_copy'] ? ' Previous database kept at '.$result['safety_copy'].'.' : ''));
    $this->line('Next: php artisan migrate --force (if the backup is older than the code), then php artisan optimize.');

    return 0;
})->purpose('Restore a backup made by crm:backup (roadmap step 10)');

Artisan::command('crm:prod-check', function (ProductionCheck $check) {
    $rows = $check->run();
    $this->table(['Check', 'Status', 'Expected'], array_map(fn (array $row): array => [$row['key'], strtoupper($row['status']), $row['message']], $rows));
    $failed = count(array_filter($rows, fn (array $row): bool => $row['status'] === 'fail'));
    $failed > 0 ? $this->error($failed.' blocking item(s).') : $this->info('No blocking items.');

    return $failed > 0 ? 1 : 0;
})->purpose('Production .env checklist (roadmap step 10)');

Artisan::command('crm:prune-notifications', function () {
    $count = DB::table('notifications')->whereNotNull('read_at')->where('read_at', '<', now()->subDays((int) config('operations.retention.notifications_days')))->delete();
    $this->info("Pruned {$count} read notifications.");
})->purpose('Delete read notifications past the retention period (roadmap step 10)');

if (config('operations.backups.schedule')) {
    Schedule::command('crm:backup')->dailyAt((string) config('operations.backups.at'))->timezone('Asia/Tashkent')->withoutOverlapping()->onOneServer();
}

Schedule::command('crm:prune-notifications')->dailyAt('04:15')->onOneServer();

// Spec 06 section 31 (one-off): orders no longer wait for a confirmation. Lists the existing `new`
// orders without a blocking hold (customer blocked, credit limit, overdue, manual; dry run by default); --apply starts them as their owner.
Artisan::command('orders:start-new {--apply : Start the listed orders}', function (StartOrder $start) {
    $orders = Order::query()->with(['customer', 'owner'])->where('status', OrderStatus::New)->orderBy('id')->get()
        ->filter(fn (Order $order): bool => collect($order->activeHolds())->every(fn ($hold): bool => ! $hold->blocksConfirmation()) && $order->customer !== null && ! $order->customer->trashed() && ! $order->customer->isDeletedInOneC());

    foreach ($orders as $order) {
        $this->line($order->number.' · '.$order->customer?->name.' · '.$order->owner?->name.' · '.$order->created_at?->toDateTimeString());
    }

    if (! $this->option('apply')) {
        $this->info("Dry run: {$orders->count()} order(s) would be started. Run again with --apply.");

        return 0;
    }

    $started = $orders->filter(fn (Order $order): bool => $start->handle($order, null)->status !== OrderStatus::New)->count();
    $this->info("Started {$started} of {$orders->count()} order(s); the rest stay new (a hold appeared while starting).");

    return 0;
})->purpose('Start existing new orders that no hold blocks (Spec 06 section 31); dry run unless --apply');

// DBA review: Telegram logs grow with every update and post. Keep `operations.retention.telegram_days`
// (Settings > Telegram, default 365); messages linked to a lead, leads, links and chats are kept;
// posts still queued or retrying are kept.
Artisan::command('telegram:prune', function () {
    $days = max(30, BusinessSettings::int('operations.retention.telegram_days'));
    $cutoff = now()->subDays($days);
    $updates = DB::table('telegram_updates')->where('received_at', '<', $cutoff)->delete();
    $messages = DB::table('telegram_messages')->whereNull('lead_id')->where('received_at', '<', $cutoff)->delete();
    $posts = DB::table('telegram_posts')->whereIn('status', ['sent', 'failed'])->where('created_at', '<', $cutoff)->delete();
    $this->info("Pruned Telegram logs older than {$days} days: {$updates} updates, {$messages} messages, {$posts} posts.");
})->purpose('Delete old Telegram updates, message log rows and company-group posts (retention in Settings > Telegram)');

Schedule::command('telegram:prune')->dailyAt('04:20')->onOneServer();

// Spec 04 section 19: lost and disqualified leads lose their personal data after
// `operations.retention.closed_leads_months` (default 24). Dry run with --dry-run.
Artisan::command('leads:anonymise {--dry-run : Only count}', function (AnonymiseClosedLeads $anonymise) {
    $months = max(6, BusinessSettings::int('operations.retention.closed_leads_months'));
    $count = $anonymise->handle(now()->subMonths($months), (bool) $this->option('dry-run'));
    $this->info(($this->option('dry-run') ? 'Would anonymise ' : 'Anonymised ')."{$count} closed lead(s) closed more than {$months} months ago.");
})->purpose('Remove personal data from lost and disqualified leads past the retention period (Spec 04 section 19)');

Schedule::command('leads:anonymise')->dailyAt('04:25')->onOneServer();

// Spec 03 section 22: what the counterparty -> organization merge did (conflicts are never resolved silently).
Artisan::command('crm:organization-merges {--conflicts : Only rows with conflicts}', function () {
    $rows = DB::table('organization_merges as m')
        ->join('partners as o', 'o.id', '=', 'm.organization_id')
        ->join('counterparties as c', 'c.id', '=', 'm.counterparty_id')
        ->when($this->option('conflicts'), fn ($query) => $query->whereNotNull('m.conflicts'))
        ->orderBy('m.id')
        ->get(['m.action', 'm.tax_id', 'o.id as partner_id', 'o.name as organization', 'c.id as counterparty_id', 'c.name as counterparty', 'm.conflicts']);

    $this->table(['Action', 'Tax ID', 'Org #', 'Organization', 'Cp #', 'Counterparty', 'Conflicts'], $rows->map(fn ($row): array => [
        $row->action, $row->tax_id, $row->partner_id, $row->organization, $row->counterparty_id, $row->counterparty,
        collect(json_decode((string) $row->conflicts, true) ?: [])->map(fn (array $pair, string $field): string => $field.': '.$pair['organization'].' | '.$pair['counterparty'])->implode('; '),
    ])->all());

    return 0;
})->purpose('Report of the counterparty to organization merge (Spec 03 section 22)');

// 1C integration API description (OpenAPI 3.1): print, or --write the copies in docs/openapi.
Artisan::command('crm:openapi {--write : Write docs/openapi/1c-integration.json and .yaml}', function (OneCIntegrationSpec $spec) {
    $json = json_encode($spec->build(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";

    if (! $this->option('write')) {
        $this->line($json);

        return 0;
    }

    File::ensureDirectoryExists(base_path('docs/openapi'));
    File::put(base_path('docs/openapi/1c-integration.json'), $json);
    File::put(base_path('docs/openapi/1c-integration.yaml'), YamlEmitter::dump($spec->build()));
    $this->info('Written docs/openapi/1c-integration.json and .yaml');

    return 0;
})->purpose('OpenAPI 3.1 description of the 1C integration API');

// Spec 09 §6: warranty of a customer unit ends in 30 days -> customer portal admins/buyers and the sales owner, once per unit.
Artisan::command('service:warranty-reminders', function () {
    $days = (int) config('service.warranty_reminder_days', 30);
    $sent = 0;

    CustomerAsset::query()->with(['organization', 'item'])
        ->whereNull('warranty_reminded_at')->where('status', '!=', 'out_of_service')
        ->whereBetween('warranty_until', [now()->toDateString(), now()->addDays($days)->toDateString()])
        ->orderBy('id')->chunkById(200, function ($assets) use (&$sent): void {
            foreach ($assets as $asset) {
                $portalUsers = User::query()->where('is_active', true)->whereHas('portalMemberships', fn ($q) => $q->where('customer_id', $asset->partner_id)->where('is_active', true)
                    ->where(fn ($r) => $r->whereJsonContains('roles', 'portal_admin')->orWhereJsonContains('roles', 'buyer')))->get();

                foreach ($portalUsers as $user) {
                    $user->notify(new WarrantyExpiring($asset, true));
                }

                $owner = $asset->organization?->owner;

                if ($owner !== null && $owner->is_active) {
                    $owner->notify(new WarrantyExpiring($asset, false));
                }

                $asset->forceFill(['warranty_reminded_at' => now()])->save();
                $sent++;
            }
        });

    $this->info("Warranty reminders: {$sent} units.");
})->purpose('Warranty expiry reminders for customer equipment (Spec 09)');

Schedule::command('service:warranty-reminders')->dailyAt('09:00')->timezone('Asia/Tashkent')->withoutOverlapping()->onOneServer();
