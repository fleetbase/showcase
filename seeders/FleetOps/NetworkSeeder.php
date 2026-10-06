<?php

// PORTED from fleetbase/fleetops server/seeders/Testing/NetworkSeeder.php.
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
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\ServiceRateFee;
use Fleetbase\FleetOps\Models\ServiceRateParcelFee;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Zone;
use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\FleetOps\Concerns\BelongsToFleetOps;
use Database\Seeders\Showcase\FleetOps\Concerns\ResolvesFleetOpsFixtures;
use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NetworkSeeder extends Seeder
{
    use SeedsShowcaseData;
    use BelongsToFleetOps;
    use ResolvesFleetOpsFixtures;

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        DB::transaction(function () use ($company) {
            $this->purgeSeedData();
            FleetOps::createTransportConfig($company);
            $this->seedServiceAreas($company);
            $this->seedZones($company);
            $places = $this->seedPlaces($company);
            $this->seedContacts($company, $places);
            $vendors = $this->seedVendors($company, $places);
            $this->seedServiceRates($company, $vendors);

            $this->command?->info('Seeded ' . $this->seedName() . ' network fixtures for company: ' . $company->public_id);
        });
    }

    public function purgeSeedData(): void
    {
        $this->purgeModel(ServiceRateParcelFee::class);
        $this->purgeModel(ServiceRateFee::class);
        $this->purgeModel(ServiceRate::class);
        $this->purgeModel(Vendor::class);
        $this->purgeModel(Contact::class);
        $this->purgeModel(Place::class);
        $this->purgeModel(Zone::class);
        $this->purgeModel(ServiceArea::class);
    }

    /**
     * Three service areas that tile the island along real district lines, and four zones
     * inside them. Shaped, not rectangles: these are drawn on the live map, in the service
     * area editor and behind the geofence feed, and the published screenshots showed
     * axis-aligned boxes over the sea. [lat, lng] rings, roughly following the coast and the
     * expressways.
     */
    protected function seedServiceAreas(Company $company): void
    {
        $areas = [
            'central_service_area' => [
                'name'   => 'Singapore Central',
                'border' => [[1.2655, 103.8025], [1.2705, 103.8340], [1.2760, 103.8610], [1.2905, 103.8790], [1.3120, 103.8935], [1.3420, 103.8925], [1.3690, 103.8770], [1.3805, 103.8450], [1.3760, 103.8120], [1.3470, 103.7880], [1.3110, 103.7795], [1.2830, 103.7840]],
                'color'  => '#2563eb',
            ],
            'east_service_area' => [
                'name'   => 'Singapore East',
                'border' => [[1.3120, 103.8935], [1.3020, 103.9190], [1.3080, 103.9520], [1.3200, 103.9790], [1.3400, 104.0080], [1.3720, 104.0140], [1.3930, 103.9890], [1.3960, 103.9480], [1.3870, 103.9080], [1.3690, 103.8770], [1.3420, 103.8925]],
                'color'  => '#059669',
            ],
            'west_service_area' => [
                'name'   => 'Singapore West',
                'border' => [[1.2830, 103.7840], [1.3110, 103.7795], [1.3470, 103.7880], [1.3760, 103.7620], [1.3840, 103.7180], [1.3640, 103.6720], [1.3300, 103.6440], [1.2960, 103.6500], [1.2780, 103.6880], [1.2730, 103.7380]],
                'color'  => '#ea580c',
            ],
        ];

        foreach ($areas as $seedId => $area) {
            $this->createRecord(ServiceArea::class, [
                '_key'                    => $this->fixtureKey($seedId),
                'company_uuid'            => $company->uuid,
                'name'                    => $area['name'],
                'type'                    => 'city',
                'country'                 => 'SG',
                'border'                  => $this->multiPolygon($area['border']),
                'color'                   => $area['color'],
                'stroke_color'            => $area['color'],
                'status'                  => 'active',
                'trigger_on_entry'        => true,
                'trigger_on_exit'         => true,
                'dwell_threshold_minutes' => 15,
                'speed_limit_kmh'         => 50,
            ]);
        }
    }

    protected function seedZones(Company $company): void
    {
        $zones = [
            'cbd_zone' => [
                'service_area' => 'central_service_area',
                'name'         => 'CBD',
                'border'       => [[1.2700, 103.8420], [1.2725, 103.8560], [1.2800, 103.8640], [1.2920, 103.8650], [1.3005, 103.8585], [1.2985, 103.8470], [1.2880, 103.8395], [1.2780, 103.8385]],
                'color'        => '#7c3aed',
            ],
            'orchard_zone' => [
                'service_area' => 'central_service_area',
                'name'         => 'Orchard',
                'border'       => [[1.2985, 103.8255], [1.3000, 103.8375], [1.3050, 103.8440], [1.3110, 103.8395], [1.3115, 103.8280], [1.3065, 103.8215]],
                'color'        => '#db2777',
            ],
            'airport_zone' => [
                'service_area' => 'east_service_area',
                'name'         => 'Changi',
                'border'       => [[1.3340, 103.9760], [1.3370, 104.0000], [1.3540, 104.0080], [1.3720, 104.0040], [1.3810, 103.9860], [1.3660, 103.9660], [1.3480, 103.9640]],
                'color'        => '#dc2626',
            ],
            'warehouse_zone' => [
                'service_area' => 'west_service_area',
                'name'         => 'Jurong',
                'border'       => [[1.3160, 103.7060], [1.3220, 103.7400], [1.3380, 103.7480], [1.3540, 103.7380], [1.3560, 103.7130], [1.3400, 103.6960], [1.3240, 103.6950]],
                'color'        => '#f59e0b',
            ],
        ];

        foreach ($zones as $seedId => $zone) {
            $serviceArea = $this->seededServiceArea($zone['service_area']);
            $this->createRecord(Zone::class, [
                '_key'                    => $this->fixtureKey($seedId),
                'company_uuid'            => $company->uuid,
                'service_area_uuid'       => $serviceArea?->uuid,
                'name'                    => $zone['name'],
                'description'             => $this->fixtureNote('zone'),
                'border'                  => $this->polygon($zone['border']),
                'color'                   => $zone['color'],
                'stroke_color'            => $zone['color'],
                'status'                  => 'active',
                'trigger_on_entry'        => true,
                'trigger_on_exit'         => true,
                'dwell_threshold_minutes' => 10,
                'speed_limit_kmh'         => 40,
            ]);
        }
    }

    protected function seedPlaces(Company $company): array
    {
        $places = [
            'central_depot' => ['Raffles Quay', '1 Raffles Quay', 'Singapore', 'SG', 1.2816, 103.8510],
            'west_depot'    => ['Jurong East', '2 Jurong East Street 21', 'Singapore', 'SG', 1.3345, 103.7420],
            'airport_hub'   => ['Changi Airfreight', '80 Airport Boulevard', 'Singapore', 'SG', 1.3644, 103.9915],
            'orchard_store' => ['ION Orchard', '2 Orchard Turn', 'Singapore', 'SG', 1.3048, 103.8318],
            'rochor_store'  => ['Rochor Canal', '1 Rochor Canal Road', 'Singapore', 'SG', 1.3039, 103.8520],
            'tampines_store'=> ['Tampines Mall', '4 Tampines Central 5', 'Singapore', 'SG', 1.3525, 103.9447],
        ];

        $models = [];
        foreach ($places as $seedId => [$name, $street1, $city, $country, $lat, $lng]) {
            $models[$seedId] = $this->createRecord(Place::class, [
                '_key'         => $this->fixtureKey($seedId),
                'company_uuid' => $company->uuid,
                'name'         => $name,
                'street1'      => $street1,
                'city'         => $city,
                'country'      => $country,
                'location'     => $this->point($lat, $lng),
                'meta'         => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    /**
     * [name, type, email, phone, place seed id] keyed by seed id. Overridable for the same
     * reason as userFixtures(): these names and addresses are rendered in the console.
     */
    protected function contactFixtures(): array
    {
        return [
            'customer_alice' => ['Alice Tan', 'customer', 'alice.tan@demo.fleetbase.io', '+6591000001', 'orchard_store'],
            'customer_ben'   => ['Ben Lim', 'customer', 'ben.lim@demo.fleetbase.io', '+6591000002', 'rochor_store'],
            'ops_manager'    => ['Nadia Rahman', 'contact', 'nadia.rahman@demo.fleetbase.io', '+6591000003', 'central_depot'],
            // The console splits this screen into Contacts and Customers tabs, so only the
            // `contact` rows appear on the one the screenshot targets. Upstream seeds a single
            // one, which renders as a one-row table — technically correct and useless as a
            // marketing image.
            //
            // Distinct people from the IAM users, on purpose. An earlier version reused three
            // IAM names and emails here; creating a Contact with an email that already belongs
            // to a User latches onto that User and overwrites its phone, so the IAM users
            // screenshot showed contact phone numbers against user rows.
            'warehouse_lead' => ['Wei Lin Teo', 'contact', 'weilin.teo@demo.fleetbase.io', '+6591000004', 'west_depot'],
            'airport_lead'   => ['Farhan Yusof', 'contact', 'farhan.yusof@demo.fleetbase.io', '+6591000005', 'airport_hub'],
            'retail_lead'    => ['Chloe Ng', 'contact', 'chloe.ng@demo.fleetbase.io', '+6591000006', 'orchard_store'],
        ];
    }

    protected function seedContacts(Company $company, array $places): void
    {
        $contacts = $this->contactFixtures();

        foreach ($contacts as $seedId => [$name, $type, $email, $phone, $placeSeedId]) {
            $this->createRecord(Contact::class, [
                '_key'         => $this->fixtureKey($seedId),
                'company_uuid' => $company->uuid,
                'place_uuid'   => $places[$placeSeedId]?->uuid,
                'name'         => $name,
                'title'        => $type === 'customer' ? 'Receiving Contact' : 'Operations Manager',
                'email'        => $email,
                'phone'        => $phone,
                'type'         => $type,
                'notes'        => $this->fixtureNote('contact'),
                'meta'         => $this->meta($seedId),
            ]);
        }
    }

    /** [name, type, email, phone, place seed id] keyed by seed id. */
    protected function vendorFixtures(): array
    {
        return [
            'facilitator_fastline' => ['Fastline Logistics', 'facilitator', 'fastline@demo.fleetbase.io', '+6592000001', 'central_depot'],
            'supplier_parts'       => ['Apex Parts Supply', 'supplier', 'apex@demo.fleetbase.io', '+6592000002', 'west_depot'],
        ];
    }

    protected function seedVendors(Company $company, array $places): array
    {
        $vendors = $this->vendorFixtures();

        $models = [];
        foreach ($vendors as $seedId => [$name, $type, $email, $phone, $placeSeedId]) {
            $models[$seedId] = $this->createRecord(Vendor::class, [
                '_key'         => $this->fixtureKey($seedId),
                'company_uuid' => $company->uuid,
                'place_uuid'   => $places[$placeSeedId]?->uuid,
                'name'         => $name,
                'email'        => $email,
                'phone'        => $phone,
                'country'      => 'SG',
                'status'       => 'active',
                'type'         => $type,
                'notes'        => $this->fixtureNote('vendor'),
                'meta'         => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    /**
     * One service rate per calculation method, each filled in the way the rate editor saves
     * it, so every docs page under operations/service-rates has a real screen: fixed distance
     * tiers, per kilometre, per drop-off ranges, multi-zone distance rules, parcel sizes and an
     * algorithm. Amounts in cents, SGD.
     */
    protected function seedServiceRates(Company $company, array $vendors): void
    {
        $orderConfig = FleetOps::createTransportConfig($company);

        $rate = function (string $seedId, array $attributes) use ($company, $orderConfig): ServiceRate {
            return $this->createRecord(ServiceRate::class, array_merge([
                '_key'              => $this->fixtureKey($seedId),
                'company_uuid'      => $company->uuid,
                'order_config_uuid' => $orderConfig->uuid,
                'service_type'      => 'transport',
                'currency'          => 'SGD',
                'duration_terms'    => 'days',
                'estimated_days'    => 0,
            ], $attributes));
        };
        $fee = function (ServiceRate $serviceRate, string $seedId, array $attributes): void {
            $this->createRecord(ServiceRateFee::class, array_merge([
                '_key'              => $this->fixtureKey($seedId),
                'service_rate_uuid' => $serviceRate->uuid,
                'currency'          => 'SGD',
            ], $attributes));
        };

        // Fixed rate: a flat fee per distance tier, up to 10 km.
        $sameDay = $rate('same_day_cbd', [
            'service_name'            => 'Same Day CBD',
            'service_area_uuid'       => $this->seededServiceArea('central_service_area')?->uuid,
            'zone_uuid'               => $this->seededZone('cbd_zone')?->uuid,
            'rate_calculation_method' => 'fixed_meter',
            'algorithm'               => 'fixed_meter',
            'base_fee'                => 400,
            'max_distance'            => 10,
            'max_distance_unit'       => 'km',
            'has_cod_fee'             => true,
            'cod_calculation_method'  => 'flat',
            'cod_flat_fee'            => 150,
        ]);
        foreach (range(1, 10) as $km) {
            $fee($sameDay, 'same_day_cbd_fee_' . $km, ['distance' => $km, 'distance_unit' => 'km', 'fee' => 500 + 100 * $km]);
        }

        // Per kilometre, with an evening peak surcharge.
        $rate('airport_express', [
            'service_name'                  => 'Airport Express',
            'service_area_uuid'             => $this->seededServiceArea('east_service_area')?->uuid,
            'zone_uuid'                     => $this->seededZone('airport_zone')?->uuid,
            'rate_calculation_method'       => 'per_meter',
            'algorithm'                     => 'per_meter',
            'base_fee'                      => 1200,
            'per_meter_flat_rate_fee'       => 95,
            'per_meter_unit'                => 'km',
            'has_peak_hours_fee'            => true,
            'peak_hours_calculation_method' => 'percentage',
            'peak_hours_percent'            => 20,
            'peak_hours_start'              => '17:00',
            'peak_hours_end'                => '20:00',
        ]);

        // Per drop-off: the fee grows with the number of stops on the order.
        $multiDrop = $rate('multi_drop_saver', [
            'service_name'            => 'Multi-Drop Saver',
            'rate_calculation_method' => 'per_drop',
            'algorithm'               => 'per_drop',
            'base_fee'                => 300,
        ]);
        foreach ([[1, 2, 500], [3, 5, 900], [6, 10, 1400], [11, 20, 2200]] as $i => [$min, $max, $amount]) {
            $fee($multiDrop, 'multi_drop_saver_fee_' . ($i + 1), ['min' => $min, 'max' => $max, 'unit' => 'waypoint', 'fee' => $amount]);
        }

        // Multi-zone distance: each stretch of the route priced by the area or zone it is in.
        $zonal = $rate('island_zonal', [
            'service_name'            => 'Island-wide Zonal',
            'rate_calculation_method' => 'multi_zone_distance',
            'algorithm'               => 'multi_zone_distance',
            'base_fee'                => 300,
        ]);
        $rules = [
            ['CBD premium', null, 'cbd_zone', 30, 200],
            ['Changi airport', null, 'airport_zone', 30, 180],
            ['Central district', 'central_service_area', null, 20, 125],
            ['East district', 'east_service_area', null, 20, 110],
            ['West district', 'west_service_area', null, 20, 105],
        ];
        foreach ($rules as $i => [$label, $areaSeedId, $zoneSeedId, $priority, $amount]) {
            $fee($zonal, 'island_zonal_rule_' . ($i + 1), [
                'label'             => $label,
                'service_area_uuid' => $areaSeedId ? $this->seededServiceArea($areaSeedId)?->uuid : null,
                'zone_uuid'         => $zoneSeedId ? $this->seededZone($zoneSeedId)?->uuid : null,
                'priority'          => $priority,
                'is_fallback'       => false,
                'distance_unit'     => 'km',
                'fee'               => $amount,
            ]);
        }
        $fee($zonal, 'island_zonal_fallback', ['label' => 'Outside service areas', 'priority' => 0, 'is_fallback' => true, 'distance_unit' => 'km', 'fee' => 100]);

        // Parcel: a fee per parcel size.
        $parcel = $rate('parcel_standard', [
            'service_name'            => 'Parcel Standard',
            'service_type'            => 'parcel',
            'rate_calculation_method' => 'parcel',
            'algorithm'               => 'parcel',
            'base_fee'                => 200,
            'estimated_days'          => 1,
        ]);
        $sizes = [
            ['small', 34, 18, 10, 2, 500],
            ['medium', 34, 32, 10, 4, 800],
            ['large', 34, 32, 34, 10, 1200],
            ['x-large', 50, 40, 40, 20, 1800],
        ];
        foreach ($sizes as [$size, $length, $width, $height, $weight, $amount]) {
            $this->createRecord(ServiceRateParcelFee::class, [
                '_key'              => $this->fixtureKey('parcel_standard_' . $size),
                'service_rate_uuid' => $parcel->uuid,
                'size'              => $size,
                'length'            => $length,
                'width'             => $width,
                'height'            => $height,
                'dimensions_unit'   => 'cm',
                'weight'            => $weight,
                'weight_unit'       => 'kg',
                'fee'               => $amount,
                'currency'          => 'SGD',
            ]);
        }

        // Algorithm: a formula over distance, extra stops and weight (result in cents).
        $rate('dynamic_pricing', [
            'service_name'            => 'Dynamic Pricing',
            'rate_calculation_method' => 'algo',
            'algorithm'               => '( {distance_km} * 90 ) + ( max( {stops} - 2, 0 ) * 250 ) + ( max( {weight_kg} - 20, 0 ) * 12 )',
            'base_fee'                => 300,
        ]);
    }
}
