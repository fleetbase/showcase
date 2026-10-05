<?php

namespace Database\Seeders\Showcase\Extensions;

use Database\Seeders\Showcase\Concerns\AnnouncesFixtures;
use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\Extensions\Concerns\BelongsToExtensions;
use Fleetbase\Models\Category;
use Fleetbase\Models\Company;
use Fleetbase\Models\File;
use Fleetbase\RegistryBridge\Models\RegistryExtension;
use Fleetbase\RegistryBridge\Models\RegistryExtensionBundle;
use Fleetbase\RegistryBridge\Models\RegistryExtensionInstall;
use Fleetbase\RegistryBridge\Models\RegistryExtensionPurchase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * The extension marketplace (registry-bridge, /extensions).
 *
 * A self-hosted instance's Explore page lists only what is published to its OWN registry —
 * nothing is synced from registry.fleetbase.io — so on a fresh stack every extensions screen
 * is an empty state. This seeds a catalogue of the real Fleetbase extensions (the packages
 * beside fleetops in the monorepo), in registry-bridge's own core categories, with some
 * installed and purchased by the showcase organization.
 *
 * The publisher is a separate "Fleetbase" company. If the showcase organization were the
 * author, is_author would be true and the extension modal would show author controls instead
 * of the install flow a customer sees; and it cannot share the showcase organization's name,
 * which the capture scripts look the organization up by.
 *
 * Icons are generated tiles (Font Awesome glyph on a gradient) in ./icons; drop a real logo
 * in as icons/<slug>.jpg to replace one. Prices are in cents.
 */
class ExtensionsSeeder extends Seeder
{
    use SeedsShowcaseData;
    use BelongsToExtensions;
    use AnnouncesFixtures;

    /**
     * slug => [name, subtitle, category internal_id, price (cents, 0 = free), version, installed, purchased, description, website].
     */
    protected function catalogue(): array
    {
        return [
            'pallet' => ['Pallet', 'Inventory & warehouse management', 'FLBWAREHOUSEMANAGEMENT', 0, '0.0.2', false, false,
                'Manage warehouses, stock levels and inventory movements alongside your fleet. Receive goods, track bins and batches, and fulfil orders straight into Fleet-Ops.', 'https://github.com/fleetbase/pallet'],
            'billing' => ['Billing', 'Multi-tenant billing & subscriptions', 'FLBACCOUNTING', 5000, '0.1.27', false, false,
                'Charge your own customers for using your Fleetbase-powered platform: plans, subscriptions, usage-based billing and invoices across every tenant.', 'https://fleetbase.io'],
            'customer-portal' => ['Customer Portal', 'Self-service portal for your customers', 'FLBCRM', 5000, '0.0.15', true, true,
                'An extendable, white-label portal where customers place and track orders, view invoices and manage their own account — without calling dispatch.', 'https://fleetbase.io'],
            'ai' => ['AI', 'Operations copilot & task automation', 'FLBANALYTICS', 0, '0.0.5', true, false,
                'An operations copilot for Fleetbase: ask questions about orders, drivers and vehicles in plain language, and automate routine dispatch tasks.', 'https://fleetbase.io'],
            'valhalla' => ['Valhalla', 'Open-source routing engine', 'FLBROUTEOPTIMIZATION', 0, '0.0.4', true, false,
                'Integrates the Valhalla open-source routing engine for turn-by-turn routing, travel-time matrices and map matching on your own infrastructure.', 'https://github.com/valhalla/valhalla'],
            'vroom' => ['VROOM', 'Vehicle routing optimization', 'FLBROUTEOPTIMIZATION', 0, '0.0.4', true, false,
                'Solves vehicle routing problems with VROOM: assign and sequence stops across your fleet against capacities, skills and time windows in seconds.', 'https://github.com/VROOM-Project/vroom'],
            'samsara' => ['Samsara', 'Vehicle data & location sync', 'FLBTELEMATICS', 0, '0.0.3', true, false,
                'Imports vehicles from Samsara and keeps their live locations in sync with Fleet-Ops, so dispatch sees every connected vehicle on one map.', 'https://www.samsara.com'],
            'flespi' => ['flespi', 'Universal telematics gateway, parser & API', 'FLBTELEMATICS', 4000, '0.1.16', true, true,
                'Connect GPS trackers and telematics devices of almost any make through flespi. Messages are parsed and normalised into Fleet-Ops positions and events in real time.', 'https://flespi.com'],
            'loconav' => ['LocoNav', 'Fleet telematics integration', 'FLBTELEMATICS', 0, '0.0.1', false, false,
                'Brings LocoNav vehicle tracking and telematics data into Fleetbase, with live positions and trip history for every connected vehicle.', 'https://loconav.com'],
            'solid' => ['Solid', 'Store and share data with Solid pods', 'FLBSECURITY', 0, '0.0.8', false, false,
                'Uses the Solid protocol to store and share Fleetbase data in pods your customers and partners control, with fine-grained access they can revoke.', 'https://solidproject.org'],
        ];
    }

    /** Explore sidebar categories. registry-bridge's own core set; only these are used. */
    protected array $categories = [
        'FLBTELEMATICS'          => ['Telematics', 'satellite-dish'],
        'FLBROUTEOPTIMIZATION'   => ['Route Optimization', 'route'],
        'FLBWAREHOUSEMANAGEMENT' => ['Warehouse Management', 'warehouse'],
        'FLBACCOUNTING'          => ['Accounting', 'cash-register'],
        'FLBCRM'                 => ['Customer Relationship Management (CRM)', 'users'],
        'FLBANALYTICS'           => ['Analytics and Reporting', 'chart-line'],
        'FLBSECURITY'            => ['Security and Risk Management', 'shield-alt'],
    ];

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        $this->purgeSeedData();

        $publisher  = $this->seedPublisher();
        $categories = $this->seedCategories();

        $extensions = [];
        $order      = 0;
        foreach ($this->catalogue() as $slug => [$name, $subtitle, $category, $price, $version, $installed, $purchased, $description, $website]) {
            $order++;
            $seedId    = 'extension:' . $slug;
            $extension = $this->createRecord(RegistryExtension::class, [
                '_key'             => $this->fixtureKey($seedId),
                'public_id'        => 'extension_' . substr(hash('sha256', $this->fixtureKey($seedId)), 0, 10),
                'company_uuid'     => $publisher->uuid,
                'publisher_type'   => 'cloud',
                'category_uuid'    => $categories[$category]->uuid ?? null,
                'name'             => $name,
                'subtitle'         => $subtitle,
                'slug'             => $slug,
                'version'          => $version,
                'description'      => $subtitle,
                'promotional_text' => $description,
                'payment_required' => $price > 0,
                'price'            => $price > 0 ? $price : null,
                'currency'         => 'USD',
                'website_url'      => $website,
                'copyright'        => 'Fleetbase Pte Ltd',
                'primary_language' => 'English',
                'tags'             => [],
                'languages'        => ['English'],
                'self_managed'     => false,
                'core_extension'   => false,
                'status'           => 'published',
                'published_at'     => $this->daysAgo($order * 11),
                'meta'             => $this->meta($seedId),
                // Spread over months so "Last modified" reads like a living catalogue.
                'created_at'       => $this->daysAgo(150 + $order * 5),
                'updated_at'       => $this->daysAgo($order * 11),
            ], true);

            $bundle = $this->createRecord(RegistryExtensionBundle::class, [
                '_key'           => $this->fixtureKey('bundle:' . $slug),
                'public_id'      => 'bundle_' . substr(hash('sha256', $this->fixtureKey('bundle:' . $slug)), 0, 10),
                'company_uuid'   => $publisher->uuid,
                'extension_uuid' => $extension->uuid,
                // Normally generated in the creating event, which is skipped here.
                'bundle_id'      => $this->deterministicUuid('bundle:' . $slug),
                'bundle_number'  => strtoupper($slug) . '-' . str_replace('.', '', $version),
                'version'        => $version,
                'status'         => 'approved',
                'meta'           => $this->meta('bundle:' . $slug),
            ], true);

            $icon = $this->attachIcon($publisher, $extension, $slug);

            $extension->forceFill(['current_bundle_uuid' => $bundle->uuid, 'icon_uuid' => $icon?->uuid]);
            RegistryExtension::withoutEvents(fn () => $extension->save());

            if ($installed) {
                $this->createRecord(RegistryExtensionInstall::class, [
                    'company_uuid'   => $company->uuid,
                    'extension_uuid' => $extension->uuid,
                    'meta'           => $this->meta('install:' . $slug),
                    'created_at'     => $this->daysAgo(20 + $order),
                ], true);
            }

            if ($purchased) {
                $this->createRecord(RegistryExtensionPurchase::class, [
                    'company_uuid'   => $company->uuid,
                    'purchaser_uuid' => $company->owner_uuid,
                    'extension_uuid' => $extension->uuid,
                    'locked_price'   => $price,
                    'meta'           => $this->meta('purchase:' . $slug),
                    'created_at'     => $this->daysAgo(21 + $order),
                ], true);
            }

            $extensions[$slug] = $extension;
        }

        $this->command?->info('Seeded ' . count($extensions) . ' registry extensions for company: ' . $company->public_id);

        $this->announceFixtures([
            'extension-details' => $extensions['flespi']->public_id ?? null,
        ]);
    }

    /** A UUID-shaped value derived from the seed id, so it is the same on every run. */
    protected function deterministicUuid(string $seedId): string
    {
        $h = md5($this->fixtureKey($seedId));

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    /** Fixture time minus whole days; never after the capture date. */
    protected function daysAgo(int $days): \Illuminate\Support\Carbon
    {
        return $this->timestamp(-24 * $days);
    }

    public function purgeSeedData(): void
    {
        $this->purgeModel(RegistryExtensionPurchase::class);
        $this->purgeModel(RegistryExtensionInstall::class);
        $this->purgeModel(File::class);
        $this->purgeModel(RegistryExtensionBundle::class);
        $this->purgeModel(RegistryExtension::class);
        $this->purgeModel(Company::class);
    }

    protected function seedPublisher(): Company
    {
        return $this->createRecord(Company::class, [
            '_key'        => $this->fixtureKey('publisher'),
            'public_id'   => 'company_' . substr(hash('sha256', $this->fixtureKey('publisher')), 0, 10),
            'name'        => 'Fleetbase',
            'description' => 'Publisher of the official Fleetbase extensions.',
            'website_url' => 'https://fleetbase.io',
            'country'     => 'SG',
            'currency'    => 'USD',
            'timezone'    => 'Asia/Singapore',
            'type'        => 'organization',
            'status'      => 'active',
            'meta'        => $this->meta('publisher'),
        ], true);
    }

    /** @return array<string, Category> keyed by internal_id */
    protected function seedCategories(): array
    {
        $out = [];
        foreach ($this->categories as $internalId => [$name, $icon]) {
            // updateOrCreate on the same key registry-bridge's ExtensionsCategorySeeder uses,
            // so a stack where that seeder ran keeps one row per category, not two.
            $out[$internalId] = Category::updateOrCreate(
                ['internal_id' => $internalId, 'for' => 'extension_category'],
                ['name' => $name, 'icon' => $icon, 'core_category' => 1, 'tags' => []]
            );
        }

        return $out;
    }

    protected function attachIcon(Company $publisher, RegistryExtension $extension, string $slug): ?File
    {
        $source = __DIR__ . '/icons/' . $slug . '.jpg';
        if (!is_file($source)) {
            return null;
        }

        $path = 'showcase/extensions/' . $slug . '.jpg';
        Storage::disk('public')->put($path, file_get_contents($source));

        return $this->createRecord(File::class, [
            '_key'              => $this->fixtureKey('icon:' . $slug),
            'public_id'         => 'file_' . substr(hash('sha256', $this->fixtureKey('icon:' . $slug)), 0, 10),
            'company_uuid'      => $publisher->uuid,
            'subject_uuid'      => $extension->uuid,
            'subject_type'      => RegistryExtension::class,
            'disk'              => 'public',
            'path'              => $path,
            'original_filename' => $slug . '.jpg',
            'content_type'      => 'image/jpeg',
            'file_size'         => filesize($source),
            'type'              => 'extension_icon',
            'meta'              => $this->meta('icon:' . $slug),
        ], true);
    }
}
