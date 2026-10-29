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
// It also opens every store all day, every day. The stack exists only for this capture, and a
// run can happen at any hour: a store that reads "Closed" at 3am Singapore time can't take an
// order, so the cart and checkout flows would fail, and the open/closed line would change the
// screenshots from one run to the next. Stores that sell bookable services keep their seeded
// hours: a booking is for a later day, so it needs no open store now, and all-day hours would
// offer times from midnight.
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

    $bookingStoreUuids = \Fleetbase\Storefront\Models\Product::where('company_uuid', $company->uuid)->where('is_bookable', true)->distinct()->pluck('store_uuid');
    $locationUuids     = \Fleetbase\Storefront\Models\StoreLocation::whereIn(
        'store_uuid',
        \Fleetbase\Storefront\Models\Store::where('company_uuid', $company->uuid)->whereNotIn('uuid', $bookingStoreUuids)->pluck('uuid')
    )->pluck('uuid');
    foreach ($locationUuids as $locationUuid) {
        \Fleetbase\Storefront\Models\StoreHour::where('store_location_uuid', $locationUuid)->forceDelete();
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
            $hour = new \Fleetbase\Storefront\Models\StoreHour();
            $hour->forceFill([
                'uuid'                => (string) \Illuminate\Support\Str::uuid(),
                'store_location_uuid' => $locationUuid,
                'day_of_week'         => $day,
                'start'               => '00:00',
                'end'                 => '23:59',
            ]);
            \Fleetbase\Storefront\Models\StoreHour::withoutEvents(fn () => $hour->save());
        }
    }

    $point = function ($location) {
        if (!$location || !method_exists($location, 'getLat')) {
            return null;
        }

        return ['latitude' => round((float) $location->getLat(), 6), 'longitude' => round((float) $location->getLng(), 6)];
    };

    $storeLocation = \Fleetbase\Storefront\Models\StoreLocation::where('store_uuid', $store->uuid)->orderBy('created_at')->first();
    $storePlace    = $storeLocation ? \Fleetbase\FleetOps\Models\Place::where('uuid', $storeLocation->place_uuid)->first() : null;

    // Where to put the customer for a trucks home: inside the truck's zone (else its service
    // area), so the app finds the zone and lists the truck as nearby. The vehicle itself may be
    // parked anywhere; a truck counts as serving the customer by its zone.
    $centroid = function ($geometry) {
        if (!$geometry) {
            return null;
        }
        $geo    = json_decode(json_encode($geometry), true);
        $coords = $geo['coordinates'] ?? [];
        $ring   = ($geo['type'] ?? '') === 'MultiPolygon' ? ($coords[0][0] ?? []) : ($coords[0] ?? []);
        if (count($ring) < 3) {
            return null;
        }
        $lng = array_sum(array_column($ring, 0)) / count($ring);
        $lat = array_sum(array_column($ring, 1)) / count($ring);

        return ['latitude' => round($lat, 6), 'longitude' => round($lng, 6)];
    };
    $truckCustomer = function (?\Fleetbase\Storefront\Models\Store $owner) use ($centroid, $point) {
        $truck = $owner ? \Fleetbase\Storefront\Models\FoodTruck::where('store_uuid', $owner->uuid)->first() : null;
        if (!$truck) {
            return null;
        }
        $zone = $truck->zone_uuid ? \Fleetbase\FleetOps\Models\Zone::where('uuid', $truck->zone_uuid)->first() : null;
        $area = $truck->service_area_uuid ? \Fleetbase\FleetOps\Models\ServiceArea::where('uuid', $truck->service_area_uuid)->first() : null;
        $vehicle = \Fleetbase\FleetOps\Models\Vehicle::where('uuid', $truck->vehicle_uuid)->first();

        return $centroid($zone?->border) ?? $centroid($area?->border) ?? $point($vehicle?->location);
    };
    $memberStore = \Fleetbase\Storefront\Models\Store::where('meta->seed_id', 'store:rochor-noodle-house')->orderBy('created_at')->first();

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
        'truck_location'         => $truckCustomer($store),
        'network_truck_location' => $truckCustomer($memberStore),
        'customer_phone' => preg_replace('/[^\d+]/', '', $phone),
    ])) . PHP_EOL;
} catch (\Throwable $e) {
    echo PHP_EOL . 'MOBILE_FIXTURES_ERROR=' . str_replace(["\n", "\r"], ' ', $e->getMessage()) . PHP_EOL;
}
