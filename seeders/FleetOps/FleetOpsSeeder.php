<?php

namespace Database\Seeders\Showcase\FleetOps;

use Database\Seeders\Showcase\Concerns\AnnouncesFixtures;
use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\FleetOps\Concerns\BelongsToFleetOps;
use Database\Seeders\Showcase\FleetOps\Concerns\ResolvesFleetOpsFixtures;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * The Fleet-Ops fixture set: service areas and zones, places, contacts and vendors, vehicles,
 * drivers and fleets, orders across the status pipeline, devices, and maintenance records.
 *
 * The area seeders are ports of fleetbase/fleetops' own Testing seeders, flattened against the
 * vendored SeedsShowcaseData concern — see the header of any of them for why subclassing is
 * not possible. Fixture DATA is ours; structure is upstream's.
 */
class FleetOpsSeeder extends Seeder
{
    use SeedsShowcaseData;
    use BelongsToFleetOps;
    use ResolvesFleetOpsFixtures;
    use AnnouncesFixtures;

    /**
     * Dependency order. Network first — places, service areas and zones are referenced by
     * fleets, orders and devices — then the fleet, then the orders that assign them.
     */
    protected const AREAS = [
        NetworkSeeder::class,
        FleetSeeder::class,
        TrailerSeeder::class,
        OrderConfigSeeder::class,
        OrdersSeeder::class,
        ConnectivitySeeder::class,
        MaintenanceSeeder::class,
    ];

    public function run(): void
    {
        // The individual seeders each purge their own rows at the start of run(), but they do
        // it in isolation. Purging everything up front, in reverse, means a partially-failed
        // previous run cannot leave an order pointing at a driver that the fleet seeder is
        // about to recreate with a new uuid.
        Schema::disableForeignKeyConstraints();
        try {
            foreach (array_reverse(static::AREAS) as $class) {
                (new $class())->purgeSeedData();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->call(static::AREAS);

        $company        = $this->prepareCompany();
        $trackingNumber = null;
        if ($company) {
            $this->seedTrackingPage($company);
            $trackingNumber = $this->trackingNumberFor($company, $this->seededModel(Order::class, 'lm_picked_up_1'));
        }

        $this->announceFixtures([
            // Fleet-Ops v0.6.72's public tracking page, for FLT-2007 (three stops, mid-route),
            // and the company's own page at its seeded slug (its lookup screen).
            'tracking-page'         => $trackingNumber,
            'tracking-page-mobile'  => $trackingNumber,
            'tracking-page-lookup'  => static::TRACKING_SLUG,
            // Both are resolved independently by the capture through the internal API — by
            // name for the driver, by internal_id for the order. Announcing them here gives
            // that lookup a second source to agree with, so a manifest and a fixture set that
            // have drifted apart fail the run instead of quietly publishing the wrong record.
            'driver-details' => $this->seededModel(Driver::class, 'driver_ava')?->public_id,
            // FLT-1004 — the "started" order, which has a driver, a vehicle and a live route,
            // so its detail page renders with something on the map.
            'order-details' => $this->seededModel(Order::class, 'order_started')?->public_id,
            // FLT-2007: the last-mile hero — three stops, mid-route, driver and vehicle assigned.
            'multi-waypoint-order' => $this->seededModel(Order::class, 'lm_picked_up_1')?->public_id,
            // TRL-01: towed by JUR-07, carrying equipment — the trailer whose detail tabs are
            // worth a screenshot.
            'trailer-details'     => $this->seededModel(Trailer::class, 'trailer_dry_van_1')?->public_id,
            'trailer-connections' => $this->seededModel(Trailer::class, 'trailer_dry_van_1')?->public_id,
            'trailer-equipment'   => $this->seededModel(Trailer::class, 'trailer_dry_van_1')?->public_id,
        ]);
    }

    public function purgeSeedData(): void
    {
        foreach (array_reverse(static::AREAS) as $class) {
            (new $class())->purgeSeedData();
        }
    }

    /** The company tracking page's slug ("fleetbase" itself is reserved). */
    protected const TRACKING_SLUG = 'fleetbase-sg';

    /**
     * Fleet-Ops v0.6.72's customer tracking page: the company's own page enabled at a slug, in
     * its colours, with support contacts — so both the settings screen and the page have
     * something real to show. Skipped on releases without the feature.
     */
    protected function seedTrackingPage(Company $company): void
    {
        $config = '\\Fleetbase\\FleetOps\\Support\\TrackingPage\\TrackingPageConfig';
        if (!class_exists($config)) {
            return;
        }

        $settings = $config::sanitize([
            'org_page' => ['enabled' => true, 'slug' => static::TRACKING_SLUG],
            'links'    => ['target' => 'org'],
            'branding' => [
                'display_name'  => $company->name,
                'accent'        => '#2563EB',
                'support_phone' => '+65 6100 0000',
                'support_email' => 'support@fleetbase.example',
                'website'       => 'https://fleetbase.io',
                'powered_by'    => true,
                'theme'         => 'system',
                'default_locale' => 'en-us',
                'locales'       => ['en-us', 'vi-vn', 'fr-fr'],
            ],
        ], [], $company->name);

        \Fleetbase\Models\Setting::configure('company.' . $company->uuid . '.' . $config::SETTING_KEY, $settings);
        \Fleetbase\Models\Setting::configure($config::SLUG_INDEX_PREFIX . static::TRACKING_SLUG, $company->uuid);
    }

    /**
     * The tracking number scripts/freeze-timestamps.php gives this order, computed the same way
     * (company prefix, ten digits from its public id, region): the freeze rewrites tracking
     * numbers after seeding, so the announced value has to be the one it will write.
     */
    protected function trackingNumberFor(Company $company, ?Order $order): ?string
    {
        if (!$order?->public_id) {
            return null;
        }
        $region = strtoupper((string) (\Illuminate\Support\Facades\DB::table('tracking_numbers')->where('uuid', $order->tracking_number_uuid)->value('region') ?: 'SG'));
        $digits = str_pad((string) (hexdec(substr(hash('sha256', 'tracking:' . $order->public_id), 0, 12)) % 10000000000), 10, '0', STR_PAD_LEFT);

        return strtoupper(substr($company->name, 0, 3)) . $digits . $region;
    }
}
