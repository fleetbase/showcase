// scripts/user-type.php
//
// Switch the showcase owner between an ordinary user and a system admin. The owner is an
// ordinary user on purpose — every screenshot should show what an operator sees — but the
// /admin screens only render for type `admin`. scripts/capture.sh flips it for the admin area
// alone and flips it back, so admin screens show the same user and organization as the rest.
//
// eval()'d inside `php artisan tinker`, like freeze-timestamps.php: no opening tag, fully
// qualified names. Env: SHOWCASE_USER_TYPE = user | admin, SHOWCASE_ORG_NAME.

try {
    $type = getenv('SHOWCASE_USER_TYPE') ?: 'user';
    if (!in_array($type, ['user', 'admin'], true)) {
        throw new \RuntimeException('unknown user type: ' . $type);
    }

    $company = \Fleetbase\Models\Company::where('name', getenv('SHOWCASE_ORG_NAME') ?: 'Fleetbase Pte Ltd')->first();
    if (!$company || !$company->owner_uuid) {
        throw new \RuntimeException('no showcase company owner found');
    }

    // A query update, not a model save: no observers, no updated_at, nothing else changes.
    \Illuminate\Support\Facades\DB::table('users')->where('uuid', $company->owner_uuid)->update(['type' => $type]);

    echo PHP_EOL . 'USER_TYPE=' . $type . PHP_EOL;
} catch (\Throwable $e) {
    echo PHP_EOL . 'USER_TYPE_ERROR=' . $e->getMessage() . PHP_EOL;
}
