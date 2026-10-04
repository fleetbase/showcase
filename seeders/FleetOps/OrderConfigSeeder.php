<?php

namespace Database\Seeders\Showcase\FleetOps;

use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\FleetOps\Concerns\BelongsToFleetOps;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;

/**
 * A custom order configuration with a real activity flow.
 *
 * The stock Transport config has five steps — created, dispatched, enroute, started,
 * completed — which is enough to run a delivery and not enough to show one. Two screenshots
 * the README leads with depend on there being more: the order board, whose columns are the
 * flow's activities, and the Activity Flow editor, which is a graph of them. This config gives
 * both something to draw.
 *
 * The structure of each activity mirrors FleetOps::transportConfigDefaults() exactly, because
 * the flow editor and the order activity engine both read it; a key missing here renders as a
 * blank node or a status that cannot be advanced. Keep it in step with upstream when that
 * shape changes.
 */
class OrderConfigSeeder extends Seeder
{
    use SeedsShowcaseData;
    use BelongsToFleetOps;

    /** The seed id OrdersSeeder resolves this config by. */
    public const CONFIG_SEED_ID = 'last_mile_config';

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        $this->purgeSeedData();

        /** @var User|null $author */
        $author = $this->seededModel(User::class, 'dispatcher_user');

        $this->createRecord(OrderConfig::class, [
            'company_uuid' => $company->uuid,
            'author_uuid'  => $author?->uuid,
            'name'         => 'Last-Mile Delivery',
            'key'          => 'last-mile-delivery',
            'namespace'    => 'showcase:order-config:last-mile-delivery',
            'description'  => 'Parcel delivery with pickup, en-route, arrival and proof-of-delivery steps, plus a failed-attempt branch.',
            'core_service' => 0,
            'status'       => 'private',
            'version'      => '0.0.1',
            'tags'         => ['delivery', 'last-mile', 'parcel'],
            'entities'     => [],
            'meta'         => $this->meta(static::CONFIG_SEED_ID),
            'flow'         => $this->flow(),
        ]);

        $this->command?->info('Seeded ' . $this->seedName() . ' order config for company: ' . $company->public_id);
    }

    public function purgeSeedData(): void
    {
        $this->purgeModel(OrderConfig::class);
    }

    /**
     * The activity graph, in the shape the engine stores it.
     *
     *   created → dispatched → enroute_pickup → arrived_pickup → picked_up
     *           → enroute_dropoff → arrived_dropoff → delivered
     *                                               ↘ failed → returned
     *
     * `activities` on each node lists the keys it can advance to; that is what the editor
     * draws as edges and what the engine offers a driver as the next step.
     */
    public static function flow(): array
    {
        $steps = [
            //  key                color      status                      details                                          next                      complete  pod
            ['created',         '#1f2937', 'Order Created',            'New delivery order created.',                    ['dispatched'],            false, false],
            ['dispatched',      '#2563eb', 'Order Dispatched',         'Assigned to a driver and dispatched.',           ['enroute_pickup'],        false, false],
            ['enroute_pickup',  '#0ea5e9', 'En Route to Pickup',       'Driver is on the way to the pickup location.',   ['arrived_pickup'],        false, false],
            ['arrived_pickup',  '#06b6d4', 'Arrived at Pickup',        'Driver has arrived at the pickup location.',     ['picked_up'],             false, false],
            ['picked_up',       '#8b5cf6', 'Picked Up',                'Parcels collected and loaded.',                  ['enroute_dropoff'],       false, false],
            ['enroute_dropoff', '#a855f7', 'En Route to Dropoff',      'Driver is on the way to the customer.',          ['arrived_dropoff'],       false, false],
            ['arrived_dropoff', '#d946ef', 'Arrived at Dropoff',       'Driver has arrived at the delivery address.',    ['delivered', 'failed'],   false, false],
            ['delivered',       '#059669', 'Delivered',                'Parcels handed over with proof of delivery.',    [],                        true,  true],
            ['failed',          '#dc2626', 'Delivery Attempt Failed',  'Recipient unavailable; delivery could not be completed.', ['returned'],     false, false],
            ['returned',        '#ea580c', 'Returned to Depot',        'Parcels returned to the depot for re-dispatch.', [],                        true,  false],
        ];

        $flow = [];
        foreach ($steps as $sequence => [$key, $color, $status, $details, $next, $complete, $pod]) {
            $flow[$key] = [
                'key'         => $key,
                'code'        => $key,
                'color'       => $color,
                'logic'       => [],
                'events'      => [],
                'status'      => $status,
                'actions'     => [],
                'details'     => $details,
                'options'     => [],
                'complete'    => $complete,
                'entities'    => [],
                'sequence'    => $sequence,
                'activities'  => $next,
                // Deterministic, unlike upstream's Str::uuid(): the editor keys its nodes by
                // this, and a value that changes every seed would churn the DOM hash the
                // capture uses to tell real changes from rendering noise.
                // Ramsey directly: Laravel's Str has no name-based uuid (Str::uuid5 does not
                // exist — that was the first CI failure of this seeder).
                'internalId'  => Uuid::uuid5(Uuid::NAMESPACE_URL, 'fleetbase-showcase:last-mile-delivery:' . $key)->toString(),
                'pod_method'  => 'scan',
                'require_pod' => $pod,
            ];
        }

        return $flow;
    }

    /** Activity keys in walk order, for building a tracking history up to a given step. */
    public static function path(string $to): array
    {
        $main   = ['created', 'dispatched', 'enroute_pickup', 'arrived_pickup', 'picked_up', 'enroute_dropoff', 'arrived_dropoff', 'delivered'];
        $branch = ['created', 'dispatched', 'enroute_pickup', 'arrived_pickup', 'picked_up', 'enroute_dropoff', 'arrived_dropoff', 'failed', 'returned'];

        $route = in_array($to, ['failed', 'returned'], true) ? $branch : $main;
        $index = array_search($to, $route, true);

        return $index === false ? ['created'] : array_slice($route, 0, $index + 1);
    }
}
