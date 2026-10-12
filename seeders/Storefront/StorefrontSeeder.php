<?php

namespace Database\Seeders\Showcase\Storefront;

use Database\Seeders\Showcase\Concerns\AnnouncesFixtures;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\CatalogSubject;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Storefront: a single store (StoreSeeder) and a marketplace network of stores
 * (NetworkSeeder), each with catalogs, products, customers, payment gateways and order
 * history. Both are vendored from Storefront's own testing seeders; see their headers.
 *
 * Upstream seeds no food trucks and no push-notification channels, and Storefront has a
 * screen for each, so they are added here. Food trucks reuse Fleet-Ops vehicles and service
 * areas rather than creating new ones, so no Fleet-Ops screen changes because of them: one for
 * the single store, and one for a network member store (Rochor Noodle House) so the Storefront
 * app's trucks home can be captured in a Network too.
 *
 * Runs after Fleet-Ops: Storefront orders are Fleet-Ops orders, and the truck needs a vehicle.
 */
class StorefrontSeeder extends Seeder
{
    use AnnouncesFixtures;

    protected const TAG = 'showcase-storefront';

    public function run(): void
    {
        $this->call([
            StoreSeeder::class,
            NetworkSeeder::class,
        ]);

        $store   = $this->seededStore();
        $network = Network::where('options->seed', 'showcase-storefront-network')->first();

        if ($store) {
            $this->seedFoodTruck($store, 'GBB-1006', 'market_truck');
            $this->seedNotificationChannel($store);
        }

        $memberStore = $this->seededNetworkStore('rochor-noodle-house');
        if ($memberStore) {
            // CEN-02: an online van in the central service area, near the store.
            $this->seedFoodTruck($memberStore, 'GBB-1010', 'rochor_truck');
        }

        $this->announceFixtures(array_filter([
            'storefront-store'           => $store?->public_id,
            'network-details'            => $network?->public_id,
            'network-invite-stores'      => $network?->public_id,
        ]));
    }

    public function purgeSeedData(): void
    {
        // Neither table has a tag column of its own: the truck goes with its seeded store, the
        // channel is tagged inside `options`.
        $stores = array_filter([$this->seededStore(), $this->seededNetworkStore('rochor-noodle-house')]);
        foreach ($stores as $store) {
            $truckUuids = FoodTruck::where('store_uuid', $store->uuid)->pluck('uuid')->all();
            CatalogSubject::whereIn('subject_uuid', $truckUuids)->forceDelete();
            FoodTruck::where('store_uuid', $store->uuid)->forceDelete();
        }
        NotificationChannel::where('options->seed', static::TAG)->forceDelete();

        (new NetworkSeeder())->purgeSeedData();
        (new StoreSeeder())->purgeSeedData();
    }

    protected function seededStore(): ?Store
    {
        return Store::where('meta->seed', 'showcase-storefront-store')->orderBy('created_at')->first();
    }

    protected function seededNetworkStore(string $key): ?Store
    {
        return Store::where('meta->seed_id', 'store:' . $key)->orderBy('created_at')->first();
    }

    protected function publicId(string $type, string $seedId): string
    {
        return $type . '_' . substr(hash('sha256', static::TAG . ':' . $seedId), 0, 10);
    }

    protected function seedFoodTruck(Store $store, string $plate, string $seedId): void
    {
        $vehicle = Vehicle::where('company_uuid', $store->company_uuid)->where('plate_number', $plate)->first();
        $area    = ServiceArea::where('company_uuid', $store->company_uuid)->where('name', 'Singapore Central')->first();
        $zone    = $area ? Zone::where('service_area_uuid', $area->uuid)->orderBy('created_at')->first() : null;
        if (!$vehicle) {
            $this->command?->warn("Food truck skipped: Fleet-Ops vehicle {$plate} not found.");

            return;
        }

        $truck = new FoodTruck();
        $truck->forceFill([
            'uuid'              => (string) Str::uuid(),
            'public_id'         => $this->publicId('food_truck', $seedId),
            'company_uuid'      => $store->company_uuid,
            'created_by_uuid'   => $store->created_by_uuid,
            'store_uuid'        => $store->uuid,
            'vehicle_uuid'      => $vehicle->uuid,
            'service_area_uuid' => $area?->uuid,
            'zone_uuid'         => $zone?->uuid,
            'status'            => 'active',
        ]);
        FoodTruck::withoutEvents(fn () => $truck->save());

        // A truck sells from its store's catalogs; without one its menu in the app is empty.
        $truck->setCatalogs(Catalog::where('store_uuid', $store->uuid)->get()->all());
    }

    protected function seedNotificationChannel(Store $store): void
    {
        $channel = new NotificationChannel();
        $channel->forceFill([
            'uuid'            => (string) Str::uuid(),
            'company_uuid'    => $store->company_uuid,
            'created_by_uuid' => $store->created_by_uuid,
            'owner_uuid'      => $store->uuid,
            'owner_type'      => Store::class,
            'name'            => 'Customer App — Android',
            'scheme'          => 'android',
            'app_key'         => 'channel_' . substr(hash('sha256', static::TAG . ':fcm'), 0, 24),
            'config'          => ['firebase_credentials_json' => '{"project_id":"fleetbase-market-demo"}', 'firebase_project_id' => 'fleetbase-market-demo'],
            'options'         => ['seed' => static::TAG, 'seed_id' => 'fcm_channel'],
        ]);
        NotificationChannel::withoutEvents(fn () => $channel->save());
    }
}
