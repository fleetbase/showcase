<?php

// PORTED from fleetbase/fleetops server/seeders/Testing/OrdersSeeder.php.
//
// Flattened rather than subclassed, and that is forced. The upstream seeders `use
// SeedsTestingData`, whose SEED_NAME is a trait CONSTANT — PHP rejects a class that redeclares
// a trait constant with a different value, so a subclass here would tag its rows
// `fleetops-testing` and be purged by fleetops' own TestingSeeder. See
// seeders/Concerns/SeedsShowcaseData.php.
//
// The bodies are upstream's. What differs is the visible strings: presentable names, real
// addresses, FLT- identifiers instead of TEST-. Keep structural changes in sync with upstream
// when it moves; the fixture DATA here is deliberately ours.

namespace Database\Seeders\Showcase\FleetOps;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Waypoint;
use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\FleetOps\Concerns\BelongsToFleetOps;
use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrdersSeeder extends Seeder
{
    use SeedsShowcaseData;
    use BelongsToFleetOps;

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        DB::transaction(function () use ($company) {
            $this->purgeSeedData();
            $this->seedOrders($company);
            $this->seedLastMileOrders($company);

            $this->command?->info('Seeded ' . $this->seedName() . ' order fixtures for company: ' . $company->public_id);
        });
    }

    public function purgeSeedData(): void
    {
        $this->purgeModel(TrackingStatus::class);
        $this->purgeModel(Order::class);
        $this->purgeModel(Entity::class);
        $this->purgeModel(Waypoint::class);
        $this->purgeModel(TrackingNumber::class);
        $this->purgeModel(Payload::class);
    }

    /**
     * The human-facing order reference. Overridable because it is the most visible string on
     * the orders table, and because src/resolve.mjs in the screenshots package looks a
     * specific order up by exactly this value.
     */
    protected const INTERNAL_IDS = [
        'order_created_unassigned' => 'FLT-1001',
        'order_scheduled'          => 'FLT-1002',
        'order_dispatched'         => 'FLT-1003',
        'order_started'            => 'FLT-1004',
        'order_completed'          => 'FLT-1005',
        'order_canceled'           => 'FLT-1006',
        'order_vehicle_assigned'   => 'FLT-1007',
        // Last-mile set — see seedLastMileOrders(). FLT-2007 is the multi-waypoint hero the
        // README leads with; capture/manifests/fleet-ops.mjs resolves it by this value.
        'lm_created_1'             => 'FLT-2001',
        'lm_created_2'             => 'FLT-2002',
        'lm_dispatched_1'          => 'FLT-2003',
        'lm_dispatched_2'          => 'FLT-2004',
        'lm_enroute_pickup'        => 'FLT-2005',
        'lm_arrived_pickup'        => 'FLT-2006',
        'lm_picked_up_1'           => 'FLT-2007',
        'lm_picked_up_2'           => 'FLT-2008',
        'lm_enroute_dropoff'       => 'FLT-2009',
        'lm_arrived_dropoff'       => 'FLT-2010',
        'lm_delivered_1'           => 'FLT-2011',
        'lm_delivered_2'           => 'FLT-2012',
        'lm_failed'                => 'FLT-2013',
        'lm_returned'              => 'FLT-2014',
        'lm_canceled'              => 'FLT-2015',
    ];

    /**
     * Sequential references in declaration order, rather than upstream's TEST-<SEED_ID>.
     *
     * capture/manifests/fleet-ops.mjs resolves the order-details screenshot by looking
     * FLT-1004 up through the internal API — records get a fresh public_id on every seed, so
     * the URL cannot be written into the manifest. Changing a value here breaks that lookup.
     * It fails loudly rather than screenshotting the wrong order, but it does fail.
     */
    protected function orderInternalId(string $seedId): string
    {
        return static::INTERNAL_IDS[$seedId]
            ?? $this->identifierPrefix() . '-' . strtoupper(str_replace('order_', '', $seedId));
    }

    /** The label shown for the nth item inside an order's payload. */
    protected function entityNameFor(string $seedId, int $index): string
    {
        // "Item 1" is fine for an assertion and poor on a marketing page.
        $names = ['Document envelope', 'Small parcel', 'Carton', 'Insulated tote', 'Pallet'];

        return $names[($index - 1) % count($names)];
    }

    protected function seedOrders(Company $company): void
    {
        $places = [
            'central_depot'  => $this->seededModel(Place::class, 'central_depot'),
            'west_depot'     => $this->seededModel(Place::class, 'west_depot'),
            'airport_hub'    => $this->seededModel(Place::class, 'airport_hub'),
            'orchard_store'  => $this->seededModel(Place::class, 'orchard_store'),
            'rochor_store'   => $this->seededModel(Place::class, 'rochor_store'),
            'tampines_store' => $this->seededModel(Place::class, 'tampines_store'),
        ];
        $customers = [
            'customer_alice' => $this->seededModel(Contact::class, 'customer_alice'),
            'customer_ben'   => $this->seededModel(Contact::class, 'customer_ben'),
        ];
        $drivers = [
            'driver_ava' => $this->seededModel(Driver::class, 'driver_ava'),
            'driver_ken' => $this->seededModel(Driver::class, 'driver_ken'),
        ];
        $vehicles = [
            'van_central' => $this->seededModel(Vehicle::class, 'van_central'),
            'van_east'    => $this->seededModel(Vehicle::class, 'van_east'),
        ];
        $facilitator = $this->seededModel(Vendor::class, 'facilitator_fastline');
        $dispatcher  = $this->seededModel(User::class, 'dispatcher_user');
        $orderConfig = FleetOps::createTransportConfig($company);

        $orders = [
            'order_created_unassigned' => ['created', null, null, 'customer_alice', 'central_depot', 'orchard_store', [], 2, 1, null, false, false],
            'order_scheduled'          => ['created', null, null, 'customer_ben', 'west_depot', 'rochor_store', [], 4, 1, $this->timestamp(30), false, false],
            'order_dispatched'         => ['dispatched', 'driver_ava', 'van_central', 'customer_alice', 'central_depot', 'orchard_store', ['rochor_store'], 6, 2, null, true, false],
            'order_started'            => ['started', 'driver_ken', 'van_east', 'customer_ben', 'airport_hub', 'tampines_store', [], 8, 1, null, true, true],
            'order_completed'          => ['completed', 'driver_ava', 'van_central', 'customer_alice', 'central_depot', 'orchard_store', [], -4, 1, null, true, true],
            'order_canceled'           => ['canceled', null, null, 'customer_ben', 'central_depot', 'rochor_store', [], -2, 1, null, false, false],
            'order_vehicle_assigned'   => ['created', null, 'van_central', 'customer_alice', 'west_depot', 'airport_hub', ['orchard_store', 'rochor_store'], 10, 3, null, false, false],
        ];

        foreach ($orders as $seedId => [$status, $driverSeedId, $vehicleSeedId, $customerSeedId, $pickupSeedId, $dropoffSeedId, $waypointSeedIds, $hourOffset, $entityCount, $scheduledAt, $dispatched, $started]) {
            $payload = $this->createPayload($company, $seedId, $places[$pickupSeedId], $places[$dropoffSeedId], array_map(fn ($placeSeedId) => $places[$placeSeedId], $waypointSeedIds));
            $order   = $this->createRecord(Order::class, [
                '_key'                  => $this->fixtureKey($seedId),
                'internal_id'           => $this->orderInternalId($seedId),
                'company_uuid'          => $company->uuid,
                'payload_uuid'          => $payload->uuid,
                'order_config_uuid'     => $orderConfig->uuid,
                'customer_uuid'         => $customers[$customerSeedId]?->uuid,
                'customer_type'         => Contact::class,
                'facilitator_uuid'      => $facilitator?->uuid,
                'facilitator_type'      => Vendor::class,
                'driver_assigned_uuid'  => $driverSeedId ? $drivers[$driverSeedId]?->uuid : null,
                'vehicle_assigned_uuid' => $vehicleSeedId ? $vehicles[$vehicleSeedId]?->uuid : null,
                'created_by_uuid'       => $dispatcher?->uuid,
                'updated_by_uuid'       => $dispatcher?->uuid,
                'scheduled_at'          => $scheduledAt,
                'dispatched_at'         => $dispatched ? $this->timestamp($hourOffset) : null,
                'dispatched'            => $dispatched,
                'started'               => $started,
                'started_at'            => $started ? $this->timestamp($hourOffset + 1) : null,
                'type'                  => 'transport',
                'status'                => $status,
                'notes'                 => $this->fixtureNote('order'),
                'pod_method'            => 'scan',
                'pod_required'          => $status === 'completed',
                'orchestrator_priority' => 50,
                'required_skills'       => $vehicleSeedId === 'van_east' ? ['cold_chain'] : [],
                'meta'                  => $this->meta($seedId),
                'created_at'            => $this->timestamp($hourOffset),
                'updated_at'            => $this->timestamp($hourOffset + 1),
            ]);

            $trackingNumber = $this->createTrackingNumber($company, $order, $seedId);
            $order->forceFill(['tracking_number_uuid' => $trackingNumber->uuid])->save();
            $this->seedEntities($company, $payload, $trackingNumber, $seedId, $entityCount, $places[$dropoffSeedId]);
        }
    }

    /**
     * Orders on the Last-Mile Delivery config, one or two at every activity in its flow.
     *
     * This is what makes the order board and the live map worth screenshotting: the board's
     * columns are the flow's activities, so a set that only reaches "started" is three columns
     * and a lot of empty space. Each order also carries a tracking-status history for every
     * step it has passed through, which is what the order details panel renders as the
     * Activity timeline.
     */
    protected function seedLastMileOrders(Company $company): void
    {
        /** @var OrderConfig|null $config */
        $config = $this->seededModel(OrderConfig::class, OrderConfigSeeder::CONFIG_SEED_ID);
        if (!$config) {
            $this->command?->warn('Last-Mile Delivery config not found; run OrderConfigSeeder first. Skipping last-mile orders.');

            return;
        }

        $places = [];
        foreach (['central_depot', 'west_depot', 'airport_hub', 'orchard_store', 'rochor_store', 'tampines_store'] as $id) {
            $places[$id] = $this->seededModel(Place::class, $id);
        }
        $customers = [
            'customer_alice' => $this->seededModel(Contact::class, 'customer_alice'),
            'customer_ben'   => $this->seededModel(Contact::class, 'customer_ben'),
        ];
        $drivers = [
            'driver_ava'  => $this->seededModel(Driver::class, 'driver_ava'),
            'driver_ken'  => $this->seededModel(Driver::class, 'driver_ken'),
            'driver_mira' => $this->seededModel(Driver::class, 'driver_mira'),
        ];
        $vehicles = [];
        foreach (['van_central', 'van_east', 'van_airport', 'van_central_2', 'van_returns'] as $id) {
            $vehicles[$id] = $this->seededModel(Vehicle::class, $id);
        }
        $dispatcher = $this->seededModel(User::class, 'dispatcher_user');
        $flow       = OrderConfigSeeder::flow();

        // seed id => [activity, driver, vehicle, customer, pickup, dropoff, waypoints, hour offset, items]
        //
        // The hour offset is when the order was created relative to the fixture clock; each
        // tracking step lands 40 minutes after the previous one, so an order deep in the flow
        // was created earlier in the day. Negative offsets are yesterday's completed work.
        $orders = [
            'lm_created_1'       => ['created',         null,          null,            'customer_alice', 'central_depot', 'orchard_store',  [],                                   3,  1],
            'lm_created_2'       => ['created',         null,          null,            'customer_ben',   'west_depot',    'tampines_store', ['rochor_store'],                     4,  2],
            'lm_dispatched_1'    => ['dispatched',      'driver_mira', 'van_returns',   'customer_alice', 'central_depot', 'rochor_store',   [],                                   2,  1],
            'lm_dispatched_2'    => ['dispatched',      'driver_ken',  'van_east',      'customer_ben',   'airport_hub',   'tampines_store', [],                                   2,  3],
            'lm_enroute_pickup'  => ['enroute_pickup',  'driver_ava',  'van_central',   'customer_alice', 'central_depot', 'orchard_store',  [],                                   1,  1],
            'lm_arrived_pickup'  => ['arrived_pickup',  'driver_ken',  'van_airport',   'customer_ben',   'airport_hub',   'rochor_store',   [],                                   1,  2],
            // The hero: three stops, a driver and a vehicle, mid-route.
            'lm_picked_up_1'     => ['picked_up',       'driver_ava',  'van_central',   'customer_alice', 'central_depot', 'orchard_store',  ['rochor_store', 'tampines_store'],   0,  3],
            'lm_picked_up_2'     => ['picked_up',       'driver_ken',  'van_east',      'customer_ben',   'west_depot',    'tampines_store', [],                                   0,  1],
            'lm_enroute_dropoff' => ['enroute_dropoff', 'driver_ava',  'van_central_2', 'customer_alice', 'central_depot', 'tampines_store', ['orchard_store'],                   -1,  2],
            'lm_arrived_dropoff' => ['arrived_dropoff', 'driver_ken',  'van_airport',   'customer_ben',   'airport_hub',   'orchard_store',  [],                                  -1,  1],
            'lm_delivered_1'     => ['delivered',       'driver_ava',  'van_central',   'customer_alice', 'central_depot', 'rochor_store',   [],                                  -6,  2],
            'lm_delivered_2'     => ['delivered',       'driver_ken',  'van_east',      'customer_ben',   'west_depot',    'orchard_store',  ['rochor_store'],                    -8,  1],
            'lm_failed'          => ['failed',          'driver_mira', 'van_returns',   'customer_alice', 'central_depot', 'tampines_store', [],                                  -5,  1],
            'lm_returned'        => ['returned',        'driver_ken',  'van_airport',   'customer_ben',   'airport_hub',   'rochor_store',   [],                                 -10,  2],
            'lm_canceled'        => ['canceled',        null,          null,            'customer_alice', 'west_depot',    'orchard_store',  [],                                  -3,  1],
        ];

        foreach ($orders as $seedId => [$activity, $driverSeedId, $vehicleSeedId, $customerSeedId, $pickupSeedId, $dropoffSeedId, $waypointSeedIds, $hourOffset, $entityCount]) {
            $history = $activity === 'canceled' ? ['created'] : OrderConfigSeeder::path($activity);
            $reached = fn (string $step) => in_array($step, $history, true);

            // Timestamps follow the history: dispatched when it passed "dispatched", started
            // when the driver set off, completed on a terminal step.
            $stepAt = fn (string $step) => $this->timestamp($hourOffset)->addMinutes(40 * max(0, array_search($step, $history, true)));

            $payload = $this->createPayload($company, $seedId, $places[$pickupSeedId], $places[$dropoffSeedId], array_map(fn ($id) => $places[$id], $waypointSeedIds));
            $order   = $this->createRecord(Order::class, [
                '_key'                  => $this->fixtureKey($seedId),
                'internal_id'           => $this->orderInternalId($seedId),
                'company_uuid'          => $company->uuid,
                'payload_uuid'          => $payload->uuid,
                'order_config_uuid'     => $config->uuid,
                'customer_uuid'         => $customers[$customerSeedId]?->uuid,
                'customer_type'         => Contact::class,
                'driver_assigned_uuid'  => $driverSeedId ? $drivers[$driverSeedId]?->uuid : null,
                'vehicle_assigned_uuid' => $vehicleSeedId ? $vehicles[$vehicleSeedId]?->uuid : null,
                'created_by_uuid'       => $dispatcher?->uuid,
                'updated_by_uuid'       => $dispatcher?->uuid,
                'dispatched'            => $reached('dispatched'),
                'dispatched_at'         => $reached('dispatched') ? $stepAt('dispatched') : null,
                'started'               => $reached('enroute_pickup'),
                'started_at'            => $reached('enroute_pickup') ? $stepAt('enroute_pickup') : null,
                'completed_at'          => $reached('delivered') || $reached('returned') ? $stepAt(end($history)) : null,
                'type'                  => 'last-mile-delivery',
                'status'                => $activity,
                'notes'                 => $this->fixtureNote('order'),
                'pod_method'            => 'scan',
                'pod_required'          => true,
                'orchestrator_priority' => 50,
                'meta'                  => $this->meta($seedId),
                'created_at'            => $this->timestamp($hourOffset),
                'updated_at'            => $stepAt(end($history)),
            ]);

            $trackingNumber = $this->createTrackingNumber($company, $order, $seedId);
            $order->forceFill(['tracking_number_uuid' => $trackingNumber->uuid])->save();
            $this->seedEntities($company, $payload, $trackingNumber, $seedId, $entityCount, $places[$dropoffSeedId]);

            // One tracking status per step reached, in order. This is the Activity timeline on
            // the order details panel and the "Dispatched at …" badge beside the status.
            $pickupPlace  = $places[$pickupSeedId];
            $dropoffPlace = $places[$dropoffSeedId];
            foreach ($history as $index => $step) {
                $definition = $flow[$step] ?? ['status' => ucfirst($step), 'details' => '', 'complete' => false];
                $atDropoff  = in_array($step, ['enroute_dropoff', 'arrived_dropoff', 'delivered', 'failed'], true);
                $place      = $atDropoff ? $dropoffPlace : $pickupPlace;

                $this->createRecord(TrackingStatus::class, [
                    '_key'                 => $this->fixtureKey($seedId . '_status_' . $index),
                    'company_uuid'         => $company->uuid,
                    'tracking_number_uuid' => $trackingNumber->uuid,
                    'status'               => $definition['status'],
                    'details'              => $definition['details'],
                    'code'                 => $step,
                    'complete'             => (bool) ($definition['complete'] ?? false),
                    'city'                 => 'Singapore',
                    'country'              => 'SG',
                    'location'             => $place?->location,
                    'created_at'           => $stepAt($step),
                    'updated_at'           => $stepAt($step),
                ], true);
            }

            if ($activity === 'canceled') {
                $this->createRecord(TrackingStatus::class, [
                    '_key'                 => $this->fixtureKey($seedId . '_status_canceled'),
                    'company_uuid'         => $company->uuid,
                    'tracking_number_uuid' => $trackingNumber->uuid,
                    'status'               => 'Order Canceled',
                    'details'              => 'Canceled by the customer before dispatch.',
                    'code'                 => 'canceled',
                    'complete'             => true,
                    'city'                 => 'Singapore',
                    'country'              => 'SG',
                    'location'             => $pickupPlace?->location,
                    'created_at'           => $this->timestamp($hourOffset)->addMinutes(25),
                    'updated_at'           => $this->timestamp($hourOffset)->addMinutes(25),
                ], true);
            }
        }
    }

    protected function createPayload(Company $company, string $seedId, ?Place $pickup, ?Place $dropoff, array $waypoints): Payload
    {
        /** @var Payload $payload */
        $payload = $this->createRecord(Payload::class, [
            '_key'         => $this->fixtureKey($seedId . '_payload'),
            'company_uuid' => $company->uuid,
            'pickup_uuid'  => $pickup?->uuid,
            'dropoff_uuid' => $dropoff?->uuid,
            'type'         => 'transport',
            'meta'         => $this->meta($seedId . '_payload'),
        ]);

        foreach ($waypoints as $index => $place) {
            $this->createRecord(Waypoint::class, [
                '_key'         => $this->fixtureKey($seedId . '_waypoint_' . ($index + 1)),
                '_import_id'   => $this->fixtureKey($seedId . ':waypoint:' . ($index + 1)),
                'company_uuid' => $company->uuid,
                'payload_uuid' => $payload->uuid,
                'place_uuid'   => $place?->uuid,
                'type'         => 'dropoff',
                'order'        => $index + 1,
                'service_time' => 300,
                'notes'        => $this->fixtureNote('waypoint'),
            ]);
        }

        return $payload;
    }

    protected function createTrackingNumber(Company $company, Order $order, string $seedId): TrackingNumber
    {
        /** @var TrackingNumber $trackingNumber */
        $trackingNumber = $this->createRecord(TrackingNumber::class, [
            '_key'            => $this->fixtureKey($seedId . '_tracking'),
            'company_uuid'    => $company->uuid,
            'owner_uuid'      => $order->uuid,
            'owner_type'      => Order::class,
            'tracking_number' => $this->identifierPrefix() . '-' . str_pad((string) crc32($seedId), 10, '0', STR_PAD_LEFT),
            'region'          => 'SG',
        ]);

        // TrackingNumberObserver::created inserts an "Order Created" tracking status (code
        // CREATED, location 0,0, no company) and calls updateOwnerStatus(), which rewrites the
        // order's status to `created` — after this seeder set it to picked_up or delivered.
        // That is how every last-mile order landed in the board's Created column. The
        // observer still has to run (it generates the tracking number, QR and barcode the
        // label view renders), so undo its two side effects instead: drop its status row —
        // the seeded history carries its own `created` step with a real location — and put
        // the seeded status back with a query update, which fires no model events.
        TrackingStatus::query()
            ->where('tracking_number_uuid', $trackingNumber->uuid)
            ->where('code', 'CREATED')
            ->delete();

        Order::query()->whereKey($order->getKey())->update([
            'status'     => $order->status,
            'updated_at' => $order->updated_at,
        ]);

        return $trackingNumber;
    }

    protected function seedEntities(Company $company, Payload $payload, TrackingNumber $trackingNumber, string $seedId, int $entityCount, ?Place $destination): void
    {
        for ($i = 1; $i <= $entityCount; $i++) {
            $this->createRecord(Entity::class, [
                '_key'                 => $this->fixtureKey($seedId . '_entity_' . $i),
                '_import_id'           => $this->fixtureKey($seedId . ':entity:' . $i),
                'public_id'            => Entity::generatePublicId(),
                'company_uuid'         => $company->uuid,
                'payload_uuid'         => $payload->uuid,
                'tracking_number_uuid' => $trackingNumber->uuid,
                'destination_uuid'     => $destination?->uuid,
                'internal_id'          => $this->identifierPrefix() . '-ENTITY-' . strtoupper($seedId) . '-' . $i,
                'name'                 => $this->entityNameFor($seedId, $i),
                'type'                 => 'parcel',
                'description'          => $this->fixtureNote('item'),
                'weight'               => 5 + $i,
                'weight_unit'          => 'kg',
                'length'               => 40,
                'width'                => 30,
                'height'               => 20,
                'dimensions_unit'      => 'cm',
                'currency'             => 'SGD',
                'declared_value'       => 1000 + ($i * 100),
                'meta'                 => $this->meta($seedId . '_entity_' . $i),
            ], true);
        }
    }
}
