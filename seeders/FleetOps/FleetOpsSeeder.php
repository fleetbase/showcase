<?php

namespace Database\Seeders\Showcase\FleetOps;

use Database\Seeders\Showcase\Concerns\AnnouncesFixtures;
use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\FleetOps\Concerns\BelongsToFleetOps;
use Database\Seeders\Showcase\FleetOps\Concerns\ResolvesFleetOpsFixtures;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
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

        $this->announceFixtures([
            // Both are resolved independently by the capture through the internal API — by
            // name for the driver, by internal_id for the order. Announcing them here gives
            // that lookup a second source to agree with, so a manifest and a fixture set that
            // have drifted apart fail the run instead of quietly publishing the wrong record.
            'driver-details' => $this->seededModel(Driver::class, 'driver_ava')?->public_id,
            // FLT-1004 — the "started" order, which has a driver, a vehicle and a live route,
            // so its detail page renders with something on the map.
            'order-details' => $this->seededModel(Order::class, 'order_started')?->public_id,
        ]);
    }

    public function purgeSeedData(): void
    {
        foreach (array_reverse(static::AREAS) as $class) {
            (new $class())->purgeSeedData();
        }
    }
}
