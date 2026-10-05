// scripts/map-provider.php
//
// Switch the Fleet-Ops live map between Leaflet and Google Maps for the current showcase
// organization. Like freeze-timestamps.php this is eval()'d inside `php artisan tinker`, so no
// opening tag and fully-qualified names.
//
// The console reads the provider from fleet-ops/settings/map, which prefers the COMPANY
// setting (company.<uuid>.fleet-ops.map-settings) and falls back to the system one — and the
// company lookup falls back to a defaults array that already says "leaflet", so writing only
// the system setting changes nothing. Both are written, merged over whatever is there.
//
// Env: SHOWCASE_MAP_PROVIDER = leaflet | google, SHOWCASE_ORG_NAME.

try {
    $provider = getenv('SHOWCASE_MAP_PROVIDER') ?: 'leaflet';
    if (!in_array($provider, ['leaflet', 'google'], true)) {
        throw new \RuntimeException('unknown map provider: ' . $provider);
    }

    $merge = function (string $key) use ($provider) {
        $existing = \Fleetbase\Models\Setting::lookup($key, []);
        $existing = is_array($existing) ? $existing : [];
        \Fleetbase\Models\Setting::configure($key, array_merge($existing, ['mapProvider' => $provider]));
    };

    $merge('fleet-ops.map-settings');

    $company = \Fleetbase\Models\Company::where('name', getenv('SHOWCASE_ORG_NAME') ?: 'Fleetbase Pte Ltd')->first();
    if ($company) {
        $merge('company.' . $company->uuid . '.fleet-ops.map-settings');
    }

    echo PHP_EOL . 'MAP_PROVIDER=' . $provider . PHP_EOL;
} catch (\Throwable $e) {
    echo PHP_EOL . 'MAP_PROVIDER_ERROR=' . $e->getMessage() . PHP_EOL;
}
