// scripts/passenger-fixtures.php
//
// What the Passenger app build, its Maestro flows and the capture's driver actor need from the
// seeded stack: the API key the app is built with, the order config it books, the rider who
// signs in, where she stands, and the drivers the actor may accept her trip as.
//
// Like mobile-fixtures.php this is NOT a standalone script: scripts/mobile/passenger-fixtures.sh
// base64-encodes it and eval()s it inside `php artisan tinker --execute=...`, so it omits the
// opening <?php tag and uses fully-qualified class names.
//
// Prints one PASSENGER_FIXTURES={json} line, or PASSENGER_FIXTURES_ERROR=<reason>.

try {
    $company = \Fleetbase\Models\Company::where('name', getenv('SHOWCASE_ORG_NAME') ?: 'Fleetbase Pte Ltd')->first();
    if (!$company) {
        throw new \RuntimeException('showcase organization not found; seed first');
    }

    // The app's key: the unrestricted production credential the Developers area seeds. The app
    // signs riders in and books with it, and the actor drives the trip with it.
    $credential = \Fleetbase\Models\ApiCredential::where('company_uuid', $company->uuid)
        ->where('name', 'Production Backend')
        ->first();
    if (!$credential) {
        throw new \RuntimeException('API credential "Production Backend" not found; run seed.sh developers');
    }

    $config = \Fleetbase\FleetOps\Models\OrderConfig::where('company_uuid', $company->uuid)->where('key', 'passenger')->first();
    if (!$config) {
        throw new \RuntimeException('no passenger order config; run seed.sh passenger');
    }

    $rider = \Fleetbase\FleetOps\Models\Contact::where('company_uuid', $company->uuid)->where('meta->seed', 'showcase-passenger')->where('meta->seed_id', 'rider')->first();
    $user  = $rider && $rider->user_uuid ? \Fleetbase\Models\User::where('uuid', $rider->user_uuid)->first() : null;
    if (!$rider || !$user?->phone) {
        throw new \RuntimeException('seeded rider not found or has no phone; run seed.sh passenger');
    }

    $pickup = \Fleetbase\FleetOps\Models\Place::where('company_uuid', $company->uuid)->where('meta->seed', 'showcase-passenger')->where('meta->seed_id', 'place_pickup')->first();

    // Online drivers, nearest the pickup first: the order the sequential dispatch offers in.
    $drivers = \Fleetbase\FleetOps\Models\Driver::where('company_uuid', $company->uuid)
        ->where('meta->seed', 'showcase-passenger')
        ->where('online', 1)
        ->get()
        ->map(fn ($driver) => [
            'id'        => $driver->public_id,
            'latitude'  => round((float) $driver->location?->getLat(), 6),
            'longitude' => round((float) $driver->location?->getLng(), 6),
        ])
        ->values()
        ->all();

    echo PHP_EOL . 'PASSENGER_FIXTURES=' . json_encode([
        'api_key'      => $credential->key,
        'order_config' => $config->key,
        'rider_phone'  => preg_replace('/[^\d+]/', '', $user->phone),
        'pickup'       => $pickup ? ['latitude' => round((float) $pickup->location->getLat(), 6), 'longitude' => round((float) $pickup->location->getLng(), 6)] : null,
        'drivers'      => $drivers,
    ]) . PHP_EOL;
} catch (\Throwable $e) {
    echo PHP_EOL . 'PASSENGER_FIXTURES_ERROR=' . str_replace(["\n", "\r"], ' ', $e->getMessage()) . PHP_EOL;
}
