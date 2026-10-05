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
    $users = \Illuminate\Support\Facades\DB::table('users')
        ->where('company_uuid', $company->uuid)
        ->update([
            'last_login'  => $frozen->copy()->subHours(2),
            'created_at'  => $frozen,
            'updated_at'  => $frozen,
        ]);

    $companies = \Illuminate\Support\Facades\DB::table('companies')
        ->where('uuid', $company->uuid)
        ->update(['created_at' => $frozen, 'updated_at' => $frozen]);

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

    echo PHP_EOL . 'FROZEN_USERS=' . $users . PHP_EOL;
    echo 'FROZEN_COMPANIES=' . $companies . PHP_EOL;
    echo 'FRESHENED_DRIVERS=' . $drivers . PHP_EOL;
} catch (\Throwable $e) {
    echo PHP_EOL . 'FREEZE_ERROR=' . $e->getMessage() . PHP_EOL;
}
