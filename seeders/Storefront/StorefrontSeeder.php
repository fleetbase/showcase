<?php

namespace Database\Seeders\Showcase\Storefront;

use Database\Seeders\Showcase\Concerns\AnnouncesFixtures;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\CatalogCategory;
use Fleetbase\Storefront\Models\CatalogSubject;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Invite;
use Fleetbase\Storefront\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            $this->seedFoodTruck($memberStore, 'GBB-1010', 'rochor_truck', $network);
        }

        if ($network) {
            $this->seedNetworkMembership($network);
        }
        if ($store) {
            $this->seedCatalogOverrides($store);
        }

        $orchard = $this->seededNetworkStore('orchard-grocers');
        $this->announceFixtures(array_filter(array_merge([
            'storefront-store'           => $store?->public_id,
            'network-details'            => $network?->public_id,
            'network-invite-stores'      => $network?->public_id,
        ], $this->v0425Fixtures($store, $network, $orchard))));
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
        Invite::where('reason', 'join_storefront_network')->where('meta->seed', static::TAG)->forceDelete();

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

    protected function seedFoodTruck(Store $store, string $plate, string $seedId, ?Network $network = null): void
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
            'uuid'              => $this->seedUuid('food_truck:' . $seedId),
            'public_id'         => $this->publicId('food_truck', $seedId),
            'company_uuid'      => $store->company_uuid,
            'created_by_uuid'   => $store->created_by_uuid,
            'store_uuid'        => $store->uuid,
            'vehicle_uuid'      => $vehicle->uuid,
            'service_area_uuid' => $area?->uuid,
            'zone_uuid'         => $zone?->uuid,
            'status'            => 'active',
        ]);
        // Storefront v0.4.25: a truck can belong to a network, and the network's Trucks page
        // lists those. The column does not exist before, so it is only set where it does.
        if ($network && $this->hasStorefrontColumn('food_trucks', 'network_uuid')) {
            $truck->network_uuid = $network->uuid;
        }
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

    protected function seedUuid(string $seedId): string
    {
        $h = hash('sha256', static::TAG . ':uuid:' . $seedId);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    protected function hasStorefrontColumn(string $table, string $column): bool
    {
        $connection = config('storefront.connection.db');

        return Schema::connection($connection)->hasTable($table) && Schema::connection($connection)->hasColumn($table, $column);
    }

    /**
     * Storefront v0.4.25's network Stores page has status tabs: Active, Offline, Invited,
     * Suspended and Awaiting approval. Give each a row: Marina Flowers suspended, Bayfront
     * Pharmacy awaiting approval, and two open invitations (one pending, one declined).
     * Membership status is a v0.4.25 column; invitations work on any version.
     */
    protected function seedNetworkMembership(Network $network): void
    {
        if ($this->hasStorefrontColumn('network_stores', 'status')) {
            $statuses = ['marina-flowers' => 'suspended', 'bayfront-pharmacy' => 'pending'];
            foreach ($statuses as $key => $status) {
                if ($member = $this->seededNetworkStore($key)) {
                    DB::connection(config('storefront.connection.db'))->table('network_stores')
                        ->where('network_uuid', $network->uuid)->where('store_uuid', $member->uuid)
                        ->update(['status' => $status]);
                }
            }
        }

        $invitations = [
            ['hello@kallang-roasters.example', 'Kallang Coffee Roasters', 'pending', 2],
            ['owner@joo-chiat-bakehouse.example', 'Joo Chiat Bakehouse', 'declined', 6],
        ];
        foreach ($invitations as $i => [$email, $storeName, $status, $daysAgo]) {
            $invite = new Invite();
            $invite->forceFill([
                'uuid'            => $this->seedUuid('invite:' . $i),
                'public_id'       => $this->publicId('invite', 'invite:' . $i),
                'company_uuid'    => $network->company_uuid,
                'created_by_uuid' => $network->created_by_uuid,
                'subject_uuid'    => $network->uuid,
                'subject_type'    => \Fleetbase\Support\Utils::getMutationType($network),
                'protocol'        => 'email',
                'recipients'      => [$email],
                'reason'          => 'join_storefront_network',
                'uri'             => substr(hash('sha256', static::TAG . ':invite-uri:' . $i), 0, 12),
                'code'            => strtoupper(substr(hash('sha256', static::TAG . ':invite-code:' . $i), 0, 6)),
                'meta'            => ['status' => $status, 'store_name' => $storeName, 'seed' => static::TAG, 'seed_id' => 'invite:' . $i],
                'created_at'      => \Illuminate\Support\Carbon::parse('2026-01-20 10:00:00', 'Asia/Singapore')->subDays($daysAgo)->utc(),
                'updated_at'      => \Illuminate\Support\Carbon::parse('2026-01-20 10:00:00', 'Asia/Singapore')->subDays($daysAgo)->utc(),
            ]);
            // No expiry: the server derives "expired" from its own clock, which is not the capture's.
            Invite::withoutEvents(fn () => $invite->save());
        }
    }

    /**
     * Storefront v0.4.25 lets a catalog override a product's price and availability. Give the
     * store's catalog one of each so the catalog editor shows a catalog price and a product
     * not sold there. v0.4.25 columns only.
     */
    protected function seedCatalogOverrides(Store $store): void
    {
        if (!$this->hasStorefrontColumn('catalog_category_products', 'price')) {
            return;
        }

        $catalog = Catalog::where('store_uuid', $store->uuid)->orderBy('created_at')->first();
        if (!$catalog) {
            return;
        }

        $overrides = ['iced-latte' => ['price' => 590], 'unavailable-item' => ['is_available' => false]];
        foreach ($overrides as $productKey => $values) {
            $product = Product::where('store_uuid', $store->uuid)->where('meta->seed_id', 'product:fleetbase-market:' . $productKey)->first();
            if (!$product) {
                continue;
            }
            DB::connection(config('storefront.connection.db'))->table('catalog_category_products')
                // Catalog categories are core `categories` rows owned by the catalog.
                ->whereIn('catalog_category_uuid', CatalogCategory::where('owner_uuid', $catalog->uuid)->pluck('uuid'))
                ->where('product_uuid', $product->uuid)
                ->update($values);
        }
    }

    /**
     * Records the v0.4.25 entries open directly (manifest `fromSeed`). The network store page
     * needs two ids, so its value is the path segment after /networks/.
     */
    protected function v0425Fixtures(?Store $store, ?Network $network, ?Store $orchard): array
    {
        $fixtures = [];
        if ($network) {
            foreach (['network-overview', 'network-stores', 'network-stores-approval', 'network-stores-invite', 'network-orders', 'network-order-create', 'network-trucks', 'network-customers', 'network-categories', 'network-promotions', 'network-campaigns', 'network-segments', 'network-redemptions', 'network-settings'] as $key) {
                $fixtures[$key] = $network->public_id;
            }
            if ($orchard) {
                $fixtures['network-store-details'] = $network->public_id . '/stores/' . $orchard->public_id;
            }
        }
        if ($store) {
            $product  = Product::where('store_uuid', $store->uuid)->where('meta->seed_id', 'product:fleetbase-market:orchard-box')->first();
            $catalog  = Catalog::where('store_uuid', $store->uuid)->orderBy('created_at')->first();
            $order    = Order::where('meta->storefront_id', $store->public_id)->where('status', 'created')->orderBy('created_at')->first()
                ?? Order::where('meta->storefront_id', $store->public_id)->orderBy('created_at', 'desc')->first();
            $customer = $order?->customer_uuid ? Contact::where('uuid', $order->customer_uuid)->first() : null;

            $fixtures['product-details'] = $product?->public_id;
            $fixtures['catalog-editor']  = $catalog?->public_id;
            $fixtures['order-details']   = $order?->public_id;
            $fixtures['customer-details'] = $customer?->public_id;
        }

        return $fixtures;
    }
}
