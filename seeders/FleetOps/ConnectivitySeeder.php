<?php

// PORTED from fleetbase/fleetops server/seeders/Testing/ConnectivitySeeder.php.
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

use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\GeofenceEventLog;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\FleetOps\Concerns\BelongsToFleetOps;
use Database\Seeders\Showcase\FleetOps\Concerns\ResolvesFleetOpsFixtures;
use Fleetbase\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ConnectivitySeeder extends Seeder
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
            $telematics = $this->seedTelematics($company);
            $devices    = $this->seedDevices($company, $telematics);
            $this->seedSensors($company, $devices);
            $this->seedDeviceEvents($company, $devices);
            $this->seedGeofenceEvents($company);

            $this->command?->info('Seeded ' . $this->seedName() . ' connectivity fixtures for company: ' . $company->public_id);
        });
    }

    public function purgeSeedData(): void
    {
        // The log has no tag column; its rows go with the seeded geofences they point at.
        $geofences = array_filter(array_merge(
            array_map(fn ($id) => $this->seededServiceArea($id)?->uuid, ['central_service_area', 'east_service_area', 'west_service_area']),
            array_map(fn ($id) => $this->seededZone($id)?->uuid, ['cbd_zone', 'orchard_zone', 'airport_zone', 'warehouse_zone'])
        ));
        if ($geofences) {
            GeofenceEventLog::whereIn('geofence_uuid', $geofences)->delete();
        }

        $this->purgeModel(DeviceEvent::class);
        $this->purgeModel(Sensor::class);
        $this->purgeModel(Device::class);
        $this->purgeModel(Telematic::class);
    }

    protected function seedTelematics(Company $company): array
    {
        $telematics = [
            'telematic_samsara'    => ['Samsara Sandbox', 'samsara', 'VG34', 'TM-SAM-001', 'active'],
            'telematic_calculated' => ['Calculated Provider', 'calculated', 'SIM', 'TM-CALC-001', 'connected'],
        ];

        $models = [];
        foreach ($telematics as $seedId => [$name, $provider, $model, $serial, $status]) {
            $models[$seedId] = $this->createRecord(Telematic::class, [
                '_key'             => $this->fixtureKey($seedId),
                'company_uuid'     => $company->uuid,
                'name'             => $name,
                'provider'         => $provider,
                'model'            => $model,
                'serial_number'    => $serial,
                'firmware_version' => '2026.01',
                'status'           => $status,
                'imei'             => '3569380356438' . ($provider === 'samsara' ? '01' : '02'),
                'last_seen_at'     => $this->timestamp(1),
                'last_metrics'     => ['signal' => 88, 'battery' => 94],
                'config'           => ['fixture' => true],
                'credentials'      => ['token' => 'redacted'],
                'meta'             => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    protected function seedDevices(Company $company, array $telematics): array
    {
        $vehicleCentral = $this->seededModel(Vehicle::class, 'van_central');
        $vehicleEast    = $this->seededModel(Vehicle::class, 'van_east');
        $devices        = [
            'device_van_central' => ['CEN-01 OBD', 'OBD-II', 'Fleetbase Labs', 'DEV-CEN-001', 'telematic_samsara', $vehicleCentral, 1.2821, 103.8510, true, 'online'],
            'device_van_east'    => ['EAS-01 Reefer', 'ColdChain-GW', 'Fleetbase Labs', 'DEV-EAST-001', 'telematic_calculated', $vehicleEast, 1.3522, 103.9442, true, 'online'],
        ];

        $models = [];
        foreach ($devices as $seedId => [$name, $model, $manufacturer, $serial, $telematicSeedId, $vehicle, $lat, $lng, $online, $status]) {
            $models[$seedId] = $this->createRecord(Device::class, [
                '_key'              => $this->fixtureKey($seedId),
                'company_uuid'      => $company->uuid,
                'telematic_uuid'    => $telematics[$telematicSeedId]?->uuid,
                'attachable_uuid'   => $vehicle?->uuid,
                'attachable_type'   => Vehicle::class,
                'type'              => 'tracker',
                'device_id'         => strtoupper($seedId),
                'internal_id'       => strtoupper($seedId),
                'name'              => $name,
                'model'             => $model,
                'manufacturer'      => $manufacturer,
                'serial_number'     => $serial,
                'last_position'     => $this->point($lat, $lng),
                'installation_date' => $this->timestamp(-720)->toDateString(),
                'last_online_at'    => $this->timestamp(2),
                'online'            => $online,
                'status'            => $status,
                'data_frequency'    => 60,
                'notes'             => $this->fixtureNote('device'),
                'data'              => ['speed_kmh' => 28, 'heading' => 92],
                'options'           => ['fixture' => true],
                'meta'              => $this->meta($seedId),
            ]);
        }

        return $models;
    }

    protected function seedSensors(Company $company, array $devices): void
    {
        $sensors = [
            'sensor_temperature' => ['device_van_east', 'Cargo Temperature', 'temperature', 'C', -2, 8, '4.2'],
            'sensor_door'        => ['device_van_central', 'Cargo Door', 'door', 'state', 0, 1, 'closed'],
        ];

        foreach ($sensors as $seedId => [$deviceSeedId, $name, $sensorType, $unit, $min, $max, $lastValue]) {
            $device = $devices[$deviceSeedId] ?? null;
            $this->createRecord(Sensor::class, [
                '_key'                => $this->fixtureKey($seedId),
                'company_uuid'        => $company->uuid,
                'device_uuid'         => $device?->uuid,
                'name'                => $name,
                'sensor_type'         => $sensorType,
                'type'                => $sensorType,
                'unit'                => $unit,
                'min_threshold'       => $min,
                'max_threshold'       => $max,
                'threshold_inclusive' => true,
                'last_position'       => $device?->last_position ?? $this->point(0, 0),
                'last_reading_at'     => $this->timestamp(2),
                'last_value'          => $lastValue,
                'sensorable_type'     => Device::class,
                'sensorable_uuid'     => $device?->uuid,
                'status'              => 'active',
                'meta'                => $this->meta($seedId),
            ]);
        }
    }

    protected function seedDeviceEvents(Company $company, array $devices): void
    {
        $events = [
            'event_ignition_on'         => ['device_van_central', 'ignition_on', 'info', 'Vehicle ignition on'],
            'event_temperature_warning' => ['device_van_east', 'temperature_threshold', 'warning', 'Cargo temperature near threshold'],
        ];

        foreach ($events as $seedId => [$deviceSeedId, $eventType, $severity, $reason]) {
            $device = $devices[$deviceSeedId] ?? null;
            $this->createRecord(DeviceEvent::class, [
                '_key'         => $this->fixtureKey($seedId),
                'company_uuid' => $company->uuid,
                'device_uuid'  => $device?->uuid,
                'payload'      => ['fixture' => true, 'event' => $eventType],
                'meta'         => $this->meta($seedId),
                'event_type'   => $eventType,
                'severity'     => $severity,
                'ident'        => strtoupper($seedId),
                'protocol'     => 'testing',
                'provider'     => $this->seedName(),
                'state'        => $severity,
                'code'         => strtoupper($eventType),
                'reason'       => $reason,
                'occurred_at'  => $this->timestamp(2),
            ]);
        }
    }

    /**
     * A morning of geofence crossings for the live map's Geofences drawer: three drivers and
     * their vans moving between the service areas and zones NetworkSeeder draws. Positions are
     * on the boundaries they cross. occurred_at hours after the fixture anchor, oldest first.
     */
    protected function seedGeofenceEvents(Company $company): void
    {
        $order = $this->seededModel(Order::class, 'order_dispatched');
        $areas = fn (string $id) => $this->seededServiceArea($id);
        $zones = fn (string $id) => $this->seededZone($id);

        // [driver seed id, vehicle seed id, geofence, type, event, lat, lng, speed, dwell minutes, minutes after 08:00]
        $feed = [
            ['driver_ava', 'van_central', $areas('central_service_area'), 'service_area', 'entered', 1.2905, 103.8790, 38, null, 12],
            ['driver_ava', 'van_central', $zones('cbd_zone'), 'zone', 'entered', 1.2920, 103.8650, 24, null, 21],
            ['driver_ken', 'van_east', $areas('east_service_area'), 'service_area', 'entered', 1.3420, 103.8925, 44, null, 26],
            ['driver_ava', 'van_central', $zones('cbd_zone'), 'zone', 'dwelled', 1.2816, 103.8510, 0, 18, 40],
            ['driver_ken', 'van_east', $zones('airport_zone'), 'zone', 'entered', 1.3480, 103.9640, 36, null, 47],
            ['driver_mira', null, $areas('west_service_area'), 'service_area', 'entered', 1.3470, 103.7880, 41, null, 55],
            ['driver_ava', 'van_central', $zones('cbd_zone'), 'zone', 'exited', 1.3005, 103.8585, 27, null, 63],
            ['driver_ava', 'van_central', $zones('orchard_zone'), 'zone', 'entered', 1.3000, 103.8375, 19, null, 71],
            ['driver_mira', null, $zones('warehouse_zone'), 'zone', 'entered', 1.3380, 103.7480, 32, null, 78],
            ['driver_ken', 'van_east', $zones('airport_zone'), 'zone', 'dwelled', 1.3644, 103.9915, 0, 25, 86],
            ['driver_mira', null, $zones('warehouse_zone'), 'zone', 'dwelled', 1.3345, 103.7420, 0, 14, 97],
            ['driver_ken', 'van_east', $zones('airport_zone'), 'zone', 'exited', 1.3660, 103.9660, 33, null, 104],
        ];

        foreach ($feed as $i => [$driverSeedId, $vehicleSeedId, $geofence, $geofenceType, $eventType, $lat, $lng, $speed, $dwell, $minutes]) {
            $driver  = $this->seededModel(Driver::class, $driverSeedId);
            $vehicle = $vehicleSeedId ? $this->seededModel(Vehicle::class, $vehicleSeedId) : null;
            if (!$driver || !$geofence) {
                continue;
            }

            $this->createRecord(GeofenceEventLog::class, [
                'uuid'                   => $this->derivedUuid(GeofenceEventLog::class, ['_key' => $this->fixtureKey('geofence_event_' . ($i + 1))]),
                'company_uuid'           => $company->uuid,
                'driver_uuid'            => $driver->uuid,
                'vehicle_uuid'           => $vehicle?->uuid,
                'order_uuid'             => $driverSeedId === 'driver_ava' ? $order?->uuid : null,
                'subject_uuid'           => $driver->uuid,
                'subject_type'           => 'driver',
                'subject_name'           => $driver->name,
                'geofence_uuid'          => $geofence->uuid,
                'geofence_type'          => $geofenceType,
                'geofence_name'          => $geofence->name,
                'event_type'             => $eventType,
                'latitude'               => $lat,
                'longitude'              => $lng,
                'speed_kmh'              => $speed,
                'dwell_duration_minutes' => $dwell,
                'occurred_at'            => $this->timestamp()->addMinutes($minutes),
                'created_at'             => $this->timestamp()->addMinutes($minutes),
            ]);
        }
    }
}
