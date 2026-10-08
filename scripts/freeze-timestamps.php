// scripts/freeze-timestamps.php
//
// Pin every server-side timestamp that the console renders, so two captures of the same UI
// produce the same pixels.
//
// This file is NOT a standalone script — like the monorepo's scripts/ci/mint-api-key.php it is
// base64-encoded and eval()'d inside `php artisan tinker --execute=...`, so it deliberately
// omits the opening <?php tag and uses fully-qualified class names.
//
// WHY IT EXISTS. The capture pins the BROWSER clock, which fixes everything rendered relative
// to "now". It cannot touch a value that arrives from the database already rendered — and the
// IAM users screen shows `last_login` and `created_at` columns. Two things write wall-clock
// time into those:
//
//   * the organization owner is created by OrganizationSeeder outside createRecord(), so it
//     carries a real created_at;
//   * logging in stamps last_login, and the capture has to log in.
//
// Measured before this existed: 6 of 8 files were byte-identical across two runs, and the two
// users screens differed on every run. That is precisely the noise this whole pipeline is
// built to avoid, so it is fixed at the source rather than masked in the diff.
//
// Run AFTER authenticating and BEFORE capturing. scripts/capture.sh enforces that order.

try {
    $frozen = \Illuminate\Support\Carbon::parse('2026-01-15 08:00:00', 'Asia/Singapore');

    // Every user in the showcase organization, seeded or not — the owner is the one that most
    // needs it and is the one the seeder does not create through createRecord().
    $company = \Fleetbase\Models\Company::where('name', getenv('SHOWCASE_ORG_NAME') ?: 'Fleetbase Pte Ltd')->first();

    if (!$company) {
        echo PHP_EOL . 'FREEZE_ERROR=no showcase company found' . PHP_EOL;

        return;
    }

    // Bypass Eloquent: touching these through the model would fire observers and updated_at
    // would be rewritten to now() by the timestamps behaviour, which is the very thing being
    // frozen.
    // Staggered, not one shared value: lists sort by created_at, and rows that tie come back in
    // whatever order MySQL picks — the IAM users and groups lists reordered between two
    // identical runs. Ordered by email (name for groups) so the stagger is the same every run.
    $users = 0;
    foreach (\Illuminate\Support\Facades\DB::table('users')->where('company_uuid', $company->uuid)->orderBy('email')->pluck('uuid') as $i => $uuid) {
        $at     = $frozen->copy()->subMinutes(7 * $i);
        $users += \Illuminate\Support\Facades\DB::table('users')->where('uuid', $uuid)->update([
            'last_login' => $at->copy()->subHours(2),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
    if (\Illuminate\Support\Facades\Schema::hasTable('groups')) {
        foreach (\Illuminate\Support\Facades\DB::table('groups')->where('company_uuid', $company->uuid)->orderBy('name')->pluck('uuid') as $i => $uuid) {
            \Illuminate\Support\Facades\DB::table('groups')->where('uuid', $uuid)->update(['created_at' => $frozen->copy()->subDays(30)->subMinutes(11 * $i)]);
        }
    }

    // Orders: the seeders restore statuses with query updates that stamp updated_at with the
    // wall clock (order details prints "Updated <time>"), and some orders share created_at, so
    // the board's columns reordered between identical runs. Break created_at ties by public id
    // (derived from the seed key) and put updated_at a fixed five minutes after it.
    $orders = 0;
    $prevAt = null;
    $tie    = 0;
    foreach (\Illuminate\Support\Facades\DB::table('orders')->where('company_uuid', $company->uuid)->whereNull('deleted_at')->orderBy('created_at')->orderBy('public_id')->get(['uuid', 'created_at']) as $order) {
        $tie    = $order->created_at === $prevAt ? $tie + 1 : 0;
        $prevAt = $order->created_at;
        $at     = \Illuminate\Support\Carbon::parse($order->created_at)->addSeconds($tie);
        $orders += \Illuminate\Support\Facades\DB::table('orders')->where('uuid', $order->uuid)->update(['created_at' => $at, 'updated_at' => $at->copy()->addMinutes(5)]);
    }

    // Order ETA provider, pinned at company level — the highest stored level Fleet-Ops reads
    // (TrackingOptions: request, then company setting, then system setting, then env). With
    // only env set, the live map's badges still flipped between "Fallback ETA" and "Low ETA
    // Confidence" across identical runs. The local calculation needs no external service.
    // Alerts carried over (or Fleet-Ops' own defaults): a company row without them would turn
    // off the tracking alerts Radar counts on the dashboard.
    $trackingKey = 'company.' . $company->uuid . '.tracking';
    $existing    = \Fleetbase\Models\Setting::lookup($trackingKey, []);
    $alerts      = is_array($existing) ? data_get($existing, 'alerts') : null;
    if (!$alerts) {
        $defaults = new \ReflectionMethod(\Fleetbase\FleetOps\Http\Controllers\Internal\v1\SettingController::class, 'trackingAlertDefaults');
        $defaults->setAccessible(true);
        $alerts = $defaults->invoke(app(\Fleetbase\FleetOps\Http\Controllers\Internal\v1\SettingController::class));
    }
    \Fleetbase\Models\Setting::configure($trackingKey, [
        'alerts'                           => $alerts,
        'provider'                         => 'calculated',
        'fallbacks'                        => ['calculated'],
        'traffic_enabled'                  => false,
        'cache_ttl_seconds'                => 60,
        'route_cache_ttl_seconds'          => 600,
        'stale_location_threshold_seconds' => 300,
        'default_vehicle_speed_kph'        => 35,
    ]);

    // Scheduled Storefront campaigns: seeded a year ahead of the real clock so the scheduler
    // could not send them during seeding; capture.sh has stopped it by now. Put each on the
    // capture's calendar, meta.send_in_days after 2026-01-20, so the campaigns list reads
    // "scheduled in N days" against the browser's frozen clock.
    $campaigns = 0;
    if (class_exists(\Fleetbase\Storefront\Models\Campaign::class)) {
        $captureDay = \Illuminate\Support\Carbon::parse('2026-01-20 10:00:00', 'Asia/Singapore');
        foreach (\Fleetbase\Storefront\Models\Campaign::where('company_uuid', $company->uuid)->where('status', 'scheduled')->get() as $campaign) {
            $days = (int) data_get($campaign->meta, 'send_in_days', 0);
            if ($days > 0) {
                $campaigns += \Fleetbase\Storefront\Models\Campaign::where('uuid', $campaign->uuid)->update(['send_at' => $captureDay->copy()->addDays($days)->utc()->format('Y-m-d H:i:s')]);
            }
        }
    }

    // The developers webhook details panel lists delivery attempts and API events. Seeding
    // other areas fires real order events at the seeded .example endpoints, adding rows with
    // wall-clock times and random ids. The seeded rows are dated January 2026; drop the rest.
    $runtime = 0;
    // geofence_event_logs too: driver positions set during seeding can trip live detection.
    foreach (['webhook_request_logs', 'api_events', 'geofence_event_logs'] as $table) {
        if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
            $runtime += \Illuminate\Support\Facades\DB::table($table)->where('company_uuid', $company->uuid)->where('created_at', '>', '2026-03-01')->delete();
        }
    }

    $companies = \Illuminate\Support\Facades\DB::table('companies')
        ->where('uuid', $company->uuid)
        ->update(['created_at' => $frozen, 'updated_at' => $frozen]);

    // The IAM policies list prints a Created column, and the system policies and roles are
    // written by `fleetbase:create-permissions` at provision time — wall-clock values that
    // made iam-policies-list differ on every run. Every row, not just the organization's: the
    // stack is throwaway and the system rows have no company.
    $iam = 0;
    foreach (['policies', 'roles', 'permissions'] as $table) {
        if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
            $iam += \Illuminate\Support\Facades\DB::table($table)->update(['created_at' => $frozen, 'updated_at' => $frozen]);
        }
    }

    // The wallet details panel prints Created and Last Updated; Ledger provisions company
    // wallets at run time.
    $wallets = \Illuminate\Support\Facades\Schema::hasTable('ledger_wallets')
        ? \Illuminate\Support\Facades\DB::table('ledger_wallets')->where('company_uuid', $company->uuid)->update(['created_at' => $frozen, 'updated_at' => $frozen])
        : 0;

    // The dashboard's Recent IAM Activity widget prints activity timestamps, and seeding
    // users and roles writes activity rows at wall-clock time. Every row: throwaway stack.
    $activity = \Illuminate\Support\Facades\Schema::hasTable('activity')
        ? \Illuminate\Support\Facades\DB::table('activity')->update(['created_at' => $frozen->copy()->subHours(3), 'updated_at' => $frozen->copy()->subHours(3)])
        : 0;

    // Tracking numbers: TrackingNumberObserver::creating always generates a random one,
    // overwriting whatever a seeder passed, and Storefront's orders get theirs from the order
    // hooks. Order lists print them, so rewrite each seeded order's from its public id (itself
    // derived from the seed key), keeping the generator's format: company prefix, ten digits,
    // region.
    $tracking = 0;
    if (\Illuminate\Support\Facades\Schema::hasTable('tracking_numbers')) {
        $prefix = strtoupper(substr($company->name, 0, 3));
        $rows   = \Illuminate\Support\Facades\DB::table('orders')
            ->join('tracking_numbers', 'tracking_numbers.uuid', '=', 'orders.tracking_number_uuid')
            ->where('orders.company_uuid', $company->uuid)
            ->select('tracking_numbers.uuid', 'orders.public_id', 'tracking_numbers.region')
            ->get();
        foreach ($rows as $row) {
            $digits = str_pad((string) (hexdec(substr(hash('sha256', 'tracking:' . $row->public_id), 0, 12)) % 10000000000), 10, '0', STR_PAD_LEFT);
            $tracking += \Illuminate\Support\Facades\DB::table('tracking_numbers')->where('uuid', $row->uuid)
                ->update(['tracking_number' => $prefix . $digits . strtoupper($row->region ?: 'SG')]);
        }
    }

    // The opposite direction for drivers: their location age is measured against the SERVER
    // clock (TrackingContextBuilder: now() - drivers.updated_at), and nothing rendered reads
    // drivers.updated_at itself, so every driver is touched to real now — the offline one
    // too, or the one order assigned to her keeps a Stale GPS badge on the live panel. This
    // is what turns "Driver stale · 262d" into a live signal. The age that the tracking
    // footer prints will read a few minutes and vary slightly between runs; that is the one
    // accepted source of drift, and it sits below the fold of the hero.
    $drivers = \Illuminate\Support\Facades\DB::table('drivers')
        ->where('company_uuid', $company->uuid)
        ->whereNull('deleted_at')
        ->update(['updated_at' => \Illuminate\Support\Carbon::now()]);

    // Same clock problem for trailers: the list's connectivity badge compares
    // assets.last_online_at with the server's now, so an "online" trailer stamped with the
    // fixture date reads Offline. Nothing rendered shows the timestamp itself.
    $trailers = \Illuminate\Support\Facades\DB::table('assets')
        ->where('company_uuid', $company->uuid)
        ->where('online', 1)
        ->whereNull('deleted_at')
        // Two hours ahead, not now: the badge flips from Online to "Recently offline" a few
        // minutes after last_online_at, and the trailer screens are captured minutes after
        // this runs — two identical runs disagreed on it.
        ->update(['last_online_at' => \Illuminate\Support\Carbon::now()->addHours(2)]);

    echo PHP_EOL . 'FROZEN_USERS=' . $users . PHP_EOL;
    echo 'FRESHENED_TRAILERS=' . $trailers . PHP_EOL;
    echo 'FROZEN_COMPANIES=' . $companies . PHP_EOL;
    echo 'FROZEN_IAM=' . $iam . PHP_EOL;
    echo 'FROZEN_WALLETS=' . $wallets . PHP_EOL;
    echo 'FROZEN_ACTIVITY=' . $activity . PHP_EOL;
    echo 'FROZEN_TRACKING=' . $tracking . PHP_EOL;
    echo 'DROPPED_RUNTIME_EVENTS=' . $runtime . PHP_EOL;
    echo 'FROZEN_ORDERS=' . $orders . PHP_EOL;
    echo 'FROZEN_CAMPAIGNS=' . $campaigns . PHP_EOL;
    echo 'FRESHENED_DRIVERS=' . $drivers . PHP_EOL;
} catch (\Throwable $e) {
    echo PHP_EOL . 'FREEZE_ERROR=' . $e->getMessage() . PHP_EOL;
}
