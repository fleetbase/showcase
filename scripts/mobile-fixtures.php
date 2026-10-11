// scripts/mobile-fixtures.php
//
// What the Storefront app build and its Maestro flows need from the seeded stack: the store
// and network keys the app connects with, an API key for the Fleetbase SDK, where to put the
// customer (the store's location, the food truck's position) and the review customer who
// signs in.
//
// Like freeze-timestamps.php this is NOT a standalone script: scripts/mobile-fixtures.sh
// base64-encodes it and eval()s it inside `php artisan tinker --execute=...`, so it omits the
// opening <?php tag and uses fully-qualified class names.
//
// Prints one MOBILE_FIXTURES={json} line, or MOBILE_FIXTURES_ERROR=<reason>.

try {
    $company = \Fleetbase\Models\Company::where('name', getenv('SHOWCASE_ORG_NAME') ?: 'Fleetbase Pte Ltd')->first();
    if (!$company) {
        throw new \RuntimeException('showcase organization not found; seed first');
    }

    $store   = \Fleetbase\Storefront\Models\Store::where('meta->seed', 'showcase-storefront-store')->orderBy('created_at')->first();
    $network = \Fleetbase\Storefront\Models\Network::where('options->seed', 'showcase-storefront-network')->first();
    if (!$store || !$network) {
        throw new \RuntimeException('seeded store or network not found; run seed.sh storefront');
    }

    // The Fleetbase SDK key: the unrestricted production credential the Developers area seeds.
    $credential = \Fleetbase\Models\ApiCredential::where('company_uuid', $company->uuid)
        ->where('name', 'Production Backend')
        ->first();
    if (!$credential) {
        throw new \RuntimeException('API credential "Production Backend" not found; run seed.sh developers');
    }

    $point = function ($location) {
        if (!$location || !method_exists($location, 'getLat')) {
            return null;
        }

        return ['latitude' => round((float) $location->getLat(), 6), 'longitude' => round((float) $location->getLng(), 6)];
    };

    $storeLocation = \Fleetbase\Storefront\Models\StoreLocation::where('store_uuid', $store->uuid)->orderBy('created_at')->first();
    $storePlace    = $storeLocation ? \Fleetbase\FleetOps\Models\Place::where('uuid', $storeLocation->place_uuid)->first() : null;

    $truck   = \Fleetbase\Storefront\Models\FoodTruck::where('store_uuid', $store->uuid)->first();
    $vehicle = $truck ? \Fleetbase\FleetOps\Models\Vehicle::where('uuid', $truck->vehicle_uuid)->first() : null;

    // The review customer: the store's first seeded customer, who has order history and an
    // order in progress (StoreSeeder's order 1). Login looks customers up by their user's phone.
    $email    = getenv('MOBILE_CUSTOMER_EMAIL') ?: 'ava.chen@example.test';
    $customer = \Fleetbase\FleetOps\Models\Contact::where('company_uuid', $company->uuid)->where('type', 'customer')->where('email', $email)->first();
    $user     = $customer && $customer->user_uuid ? \Fleetbase\Models\User::where('uuid', $customer->user_uuid)->first() : null;
    $phone    = $user?->phone ?: $customer?->phone;
    if (!$phone) {
        throw new \RuntimeException("review customer {$email} has no phone");
    }

    echo PHP_EOL . 'MOBILE_FIXTURES=' . json_encode(array_filter([
        'store_key'      => $store->key,
        'network_key'    => $network->key,
        'fleetbase_key'  => $credential->key,
        'currency'       => $store->currency,
        'store_location' => $point($storePlace?->location),
        'truck_location' => $point($vehicle?->location),
        'customer_phone' => preg_replace('/[^\d+]/', '', $phone),
    ])) . PHP_EOL;
} catch (\Throwable $e) {
    echo PHP_EOL . 'MOBILE_FIXTURES_ERROR=' . str_replace(["\n", "\r"], ' ', $e->getMessage()) . PHP_EOL;
}
