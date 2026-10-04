<?php

namespace Database\Seeders\Showcase\FleetOps;

use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\FleetOps\Concerns\BelongsToFleetOps;
use Database\Seeders\Showcase\FleetOps\Concerns\ResolvesFleetOpsFixtures;
use Fleetbase\FleetOps\Models\AssetConnection;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\Seeder;

/**
 * Trailers: a small fleet of them, one towed, one carrying equipment.
 *
 * Trailers are first-class assets since fleetops 0.6.x (the `assets` table, asset_class
 * `trailer`), with their own list, detail panel and tabs — positions, devices, equipment,
 * connections, schedules, work orders, maintenance. The docs have three pages on them and
 * the screenshot library has none, so this is the first area that exists purely to
 * create screenshots that never existed.
 *
 * Runs after FleetSeeder: the towing connection needs a seeded vehicle.
 */
class TrailerSeeder extends Seeder
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

        $this->purgeSeedData();

        $trailers = $this->seedTrailers($company);
        $this->seedConnections($company, $trailers);
        $this->seedEquipment($company, $trailers);

        $this->command?->info('Seeded ' . $this->seedName() . ' trailer fixtures for company: ' . $company->public_id);
    }

    public function purgeSeedData(): void
    {
        $this->purgeModel(Equipment::class);
        $this->purgeModel(AssetConnection::class);
        $this->purgeModel(Trailer::class);
    }

    protected function seedTrailers(Company $company): array
    {
        // Singapore plates, Singapore depots. Statuses cover the list's badge set; the
        // dry van is the one the detail screenshots open.
        $trailers = [
            //  seed id              name                 code      type         body       status            online  plate       make         model          year  lat      lng       refrigerated
            'trailer_dry_van_1' => ['Dry Van TRL-01',    'TRL-01', 'dry_van',   'box',     'in_use',         true,   'TRL-1001', 'Schmitz',   'S.KO',        2022, 1.3345, 103.7420, false],
            'trailer_dry_van_2' => ['Dry Van TRL-02',    'TRL-02', 'dry_van',   'box',     'available',      true,   'TRL-1002', 'Krone',     'Dry Liner',   2021, 1.3644, 103.9915, false],
            'trailer_reefer'    => ['Reefer TRL-03',     'TRL-03', 'reefer',    'box',     'available',      true,   'TRL-1003', 'Schmitz',   'S.KO COOL',   2023, 1.3525, 103.9447, true],
            'trailer_flatbed'   => ['Flatbed TRL-04',    'TRL-04', 'flatbed',   'flatbed', 'maintenance',    false,  'TRL-1004', 'Fontaine',  'Revolution',  2019, 1.3215, 103.7055, false],
        ];

        $models = [];
        foreach ($trailers as $seedId => [$name, $code, $type, $bodyType, $status, $online, $plate, $make, $model, $year, $lat, $lng, $refrigerated]) {
            $models[$seedId] = $this->createRecord(Trailer::class, [
                '_key'             => $this->fixtureKey($seedId),
                'company_uuid'     => $company->uuid,
                'name'             => $name,
                'code'             => $code,
                'type'             => $type,
                'body_type'        => $bodyType,
                'status'           => $status,
                'online'           => $online,
                'last_online_at'   => $online ? $this->timestamp()->addHours(2) : $this->timestamp()->subDays(3),
                'location'         => $this->point($lat, $lng),
                'plate_number'     => $plate,
                'vin'              => 'WSM' . str_pad((string) crc32($seedId), 14, '0', STR_PAD_LEFT),
                'make'             => $make,
                'model'            => $model,
                'year'             => $year,
                'color'            => 'white',
                'ownership_type'   => $seedId === 'trailer_flatbed' ? 'leased' : 'owned',
                'measurement_system' => 'metric',
                'length'           => $type === 'flatbed' ? 13.6 : 13.6,
                'width'            => 2.55,
                'height'           => $type === 'flatbed' ? 1.5 : 4.0,
                'tare_weight'      => $type === 'reefer' ? 8200 : 6800,
                'gvwr'             => 36000,
                'payload_capacity' => $type === 'reefer' ? 27800 : 29200,
                'cargo_volume'     => $type === 'flatbed' ? null : 90,
                'axle_count'       => 3,
                'tire_count'       => 12,
                'door_count'       => $type === 'flatbed' ? 0 : 2,
                'coupling_type'    => 'fifth_wheel',
                'brake_type'       => 'air',
                'abs_equipped'     => true,
                'ebs_equipped'     => $year >= 2021,
                'refrigerated'     => $refrigerated,
                'temperature_min'  => $refrigerated ? -25 : null,
                'temperature_max'  => $refrigerated ? 25 : null,
                'currency'         => 'SGD',
                'odometer'         => 48000 + (crc32($seedId) % 90000),
                'odometer_unit'    => 'km',
                'meta'             => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    /**
     * TRL-01 is towed by JUR-07 (the west-depot Hino). Mirrors TrailerController@attach
     * exactly — active_connected_uuid and active_connector_position are what make the
     * connection "current" for both the trailer's Connections tab and the vehicle's.
     */
    protected function seedConnections(Company $company, array $trailers): void
    {
        /** @var Vehicle|null $vehicle */
        $vehicle = $this->seededModel(Vehicle::class, 'truck_west');
        /** @var User|null $dispatcher */
        $dispatcher = $this->seededModel(User::class, 'dispatcher_user');
        $trailer    = $trailers['trailer_dry_van_1'] ?? null;

        if (!$vehicle || !$trailer) {
            $this->command?->warn('Trailer connection skipped: vehicle truck_west or trailer TRL-01 missing.');

            return;
        }

        $this->createRecord(AssetConnection::class, [
            '_key'                      => $this->fixtureKey('trailer_connection_1'),
            'company_uuid'              => $company->uuid,
            'connector_type'            => Vehicle::class,
            'connector_uuid'            => $vehicle->uuid,
            'connected_type'            => Trailer::class,
            'connected_uuid'            => $trailer->uuid,
            'active_connected_uuid'     => $trailer->uuid,
            'active_connector_position' => $vehicle->uuid . ':1',
            'relationship_type'         => 'towing',
            'position'                  => 1,
            'connected_at'              => $this->timestamp()->subDays(1)->setTime(6, 30),
            'source'                    => 'manual',
            'notes'                     => 'Coupled at the west depot for the Jurong linehaul run.',
            'created_by_uuid'           => $dispatcher?->uuid,
            'updated_by_uuid'           => $dispatcher?->uuid,
            'meta'                      => $this->meta('trailer_connection_1'),
        ]);
    }

    protected function seedEquipment(Company $company, array $trailers): void
    {
        $equipment = [
            //  seed id                   trailer              name                      code      type              manufacturer    model        serial
            'equipment_reefer_unit'  => ['trailer_reefer',    'Reefer Unit',            'EQ-001', 'refrigeration',  'Thermo King',  'SLXi-300',  'TK-SLX-2023-0412'],
            'equipment_load_bars'    => ['trailer_dry_van_1', 'Load Bars (set of 6)',   'EQ-002', 'cargo_control',  'Ancra',        'SL-48',     'AN-SL48-2022-0088'],
            'equipment_liftgate'     => ['trailer_dry_van_1', 'Tuck-away Liftgate',     'EQ-003', 'lifting',        'Maxon',        'TE-25',     'MX-TE25-2022-0193'],
        ];

        foreach ($equipment as $seedId => [$trailerSeedId, $name, $code, $type, $manufacturer, $model, $serial]) {
            $trailer = $trailers[$trailerSeedId] ?? null;
            if (!$trailer) {
                continue;
            }

            $this->createRecord(Equipment::class, [
                '_key'           => $this->fixtureKey($seedId),
                'company_uuid'   => $company->uuid,
                'name'           => $name,
                'code'           => $code,
                'type'           => $type,
                'status'         => 'active',
                'available'      => false,
                'serial_number'  => $serial,
                'manufacturer'   => $manufacturer,
                'model'          => $model,
                'equipable_type' => Trailer::class,
                'equipable_uuid' => $trailer->uuid,
                'purchased_at'   => $this->timestamp()->subYears(1)->toDateString(),
                'purchase_price' => $type === 'refrigeration' ? 18500 : 1200,
                'currency'       => 'SGD',
                'meta'           => $this->meta($seedId),
            ]);
        }
    }
}
