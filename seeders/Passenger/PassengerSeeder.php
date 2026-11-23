<?php

namespace Database\Seeders\Showcase\Passenger;

use Database\Seeders\Showcase\Concerns\AnnouncesFixtures;
use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\Passenger\Concerns\BelongsToPassenger;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTemplates;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * The Passenger app's world: what a ride-hailing operator on Fleet-Ops needs for a rider to book,
 * and for a driver to take, a trip near Marina Bay Sands.
 *
 *   - the system `passenger` order config (Fleet-Ops' Passenger template);
 *   - two car classes, Standard (sedans) and XL (MPVs), each a service rate priced from the trip's
 *     distance and time, plus their vehicles;
 *   - drivers online around the pickup, one per vehicle, and one further away;
 *   - the rider Sophie Lim, who signs in with the review-account code, her saved places (Home and
 *     Work) and a few finished trips for her history.
 *
 * Its own area, kept out of `ShowcaseSeeder`: online drivers in the CBD and a second order config
 * would change the console's Fleet-Ops screenshots. `./scripts/seed.sh passenger`, after the rest.
 *
 * The capture's driver actor (scripts/mobile/passenger-driver.py) accepts the rider's request as
 * whichever of these drivers holds the offer and moves the trip along between Maestro flows.
 */
class PassengerSeeder extends Seeder
{
    use SeedsShowcaseData;
    use BelongsToPassenger;
    use AnnouncesFixtures;

    /** The rider's number. The stack accepts the sign-in bypass code only for it (FLEETOPS_CUSTOMER_REVIEW_ACCOUNTS). */
    public const RIDER_PHONE = '+6591000301';

    /** Where the rider stands: Marina Bay Sands, Bayfront Avenue. The capture puts the device here. */
    public const PICKUP = [1.28345, 103.86072];

    /**
     * How long each driver has to accept an offer, in seconds: Fleet-Ops' maximum. The
     * template's 20 seconds is right for real riders, but the capture photographs "Finding your
     * driver" first and then accepts, and a slow runner can take longer than that.
     */
    protected const OFFER_TIMEOUT = 120;

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        try {
            $this->purgeSeedData();

            $config  = $this->passengerConfig($company);
            $rates   = $this->seedServiceRates($company, $config);
            $drivers = $this->seedDrivers($company);
            $rider   = $this->seedRider($company);
            $places  = $this->seedPlaces($company, $rider);
            $this->seedTrips($company, $config, $rates, $drivers, $rider, $places);
            $this->ensureSupportPhone($company);

            $this->command?->info('Seeded ' . $this->seedName() . ' fixtures for company: ' . $company->public_id);
            $this->announceFixtures([
                'passenger-order-config' => $config->public_id,
                'passenger-rider'        => $rider->public_id,
            ]);
        } catch (\Throwable $e) {
            echo PHP_EOL . 'SHOWCASE_ERROR=' . $e->getMessage() . PHP_EOL;
            echo $e->getTraceAsString() . PHP_EOL;
        }
    }

    public function purgeSeedData(): void
    {
        // As ShowcaseSeeder does: the rows point at each other (orders at drivers, drivers at
        // vehicles, the rider's user at her contact), so no delete order satisfies every key.
        Schema::disableForeignKeyConstraints();
        try {
            $this->purgeRows();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    protected function purgeRows(): void
    {
        $orderUuids = $this->seededQuery(Order::class)->pluck('uuid');
        // The capture's own trips: booked by the seeded rider, so purged with her.
        $rider = $this->seededModel(Contact::class, 'rider');
        if ($rider) {
            if ($rider->user_uuid) {
                User::where('uuid', $rider->user_uuid)->forceDelete();
            }
            $orderUuids = $orderUuids->merge(Order::withoutGlobalScopes()->where('customer_uuid', $rider->uuid)->pluck('uuid'));
        }
        $orders = Order::withoutGlobalScopes()->whereIn('uuid', $orderUuids)->get();
        foreach ($orders as $order) {
            TrackingStatus::where('tracking_number_uuid', $order->tracking_number_uuid)->forceDelete();
            TrackingNumber::where('uuid', $order->tracking_number_uuid)->forceDelete();
            PurchaseRate::where('uuid', $order->purchase_rate_uuid)->forceDelete();
            Payload::where('uuid', $order->payload_uuid)->forceDelete();
        }
        Order::withoutGlobalScopes()->whereIn('uuid', $orderUuids)->forceDelete();

        $this->purgeModel(ServiceQuoteItem::class);
        $this->purgeModel(ServiceQuote::class);
        $this->purgeModel(ServiceRate::class);
        $this->purgeModel(Place::class);
        $this->purgeModel(Driver::class);
        $this->purgeModel(Vehicle::class);
        $this->purgeModel(Contact::class);
        $this->purgeModel(User::class);
    }

    // ── Order config ────────────────────────────────────────────────────────────────────

    /**
     * The company's system Passenger config. Every organization gets it from Fleet-Ops once
     * fleetops#433 is released; until then it is made here, and this is a lookup once it exists.
     */
    protected function passengerConfig(Company $company): OrderConfig
    {
        if (class_exists(OrderConfigTemplates::class) && OrderConfigTemplates::has('passenger')) {
            $config = OrderConfigTemplates::create('passenger', $company);
        } else {
            Artisan::call('fleetops:create-order-config', ['template' => 'passenger', '--company' => $company->uuid]);
            $config = OrderConfig::where('company_uuid', $company->uuid)->where('key', 'passenger')->firstOrFail();
        }

        $policies = $config->policies ?? [];
        if (is_array($policies) && data_get($policies, 'dispatch.adhoc.offer_timeout') !== static::OFFER_TIMEOUT) {
            data_set($policies, 'dispatch.adhoc.offer_timeout', static::OFFER_TIMEOUT);
            $config->policies = $policies;
            $config->save();
        }

        return $config;
    }

    // ── Car classes ─────────────────────────────────────────────────────────────────────

    /**
     * service_type => [name, base fee, algorithm]. Amounts are cents: S$3.90 to start, then per
     * kilometre and per minute of the trip. The app lists each as a car class, in this order.
     */
    protected function rateFixtures(): array
    {
        return [
            'sedan' => ['Standard', 390, '{distance_km} * 72 + {time_min} * 28'],
            'mpv'   => ['XL', 590, '{distance_km} * 98 + {time_min} * 36'],
        ];
    }

    protected function seedServiceRates(Company $company, OrderConfig $config): array
    {
        $rates = [];
        foreach ($this->rateFixtures() as $type => [$name, $baseFee, $algorithm]) {
            $rates[$type] = $this->createRecord(ServiceRate::class, [
                '_key'                    => $this->fixtureKey('rate_' . $type),
                'company_uuid'            => $company->uuid,
                'order_config_uuid'       => $config->uuid,
                'service_name'            => $name,
                'service_type'            => $type,
                'base_fee'                => $baseFee,
                'rate_calculation_method' => 'algo',
                'algorithm'               => $algorithm,
                'currency'                => 'SGD',
                'duration_terms'          => 'Priced from the trip\'s distance and time.',
                'has_cod_fee'             => 0,
                'has_peak_hours_fee'      => 0,
                'created_at'              => $this->timestamp(-24 * 60 + array_search($type, array_keys($this->rateFixtures()), true)),
            ]);
        }

        return $rates;
    }

    // ── Drivers and their cars ──────────────────────────────────────────────────────────

    /**
     * seed id => [name, phone, vehicle type, seats, make, model, colour, plate, lat, lng, online].
     * The two nearest the pickup drive Standard cars, so a Standard request goes to Marcus first.
     */
    protected function driverFixtures(): array
    {
        return [
            'marcus' => ['Marcus Lee', '+6581000301', 'sedan', 4, 'Toyota', 'Corolla Altis', 'White', 'SLA 4821 K', 1.28092, 103.85741, true],
            'aisyah' => ['Nur Aisyah', '+6581000302', 'sedan', 4, 'Hyundai', 'Ioniq 5', 'Grey', 'SMB 1907 J', 1.28911, 103.85402, true],
            'ravi'   => ['Ravi Kumar', '+6581000303', 'mpv', 6, 'Toyota', 'Alphard', 'Black', 'SKV 6650 D', 1.27904, 103.85320, true],
            'wei'    => ['Tan Wei Ming', '+6581000304', 'sedan', 4, 'Honda', 'Civic', 'Blue', 'SJH 3318 C', 1.30044, 103.83924, false],
        ];
    }

    protected function seedDrivers(Company $company): array
    {
        $drivers = [];
        foreach ($this->driverFixtures() as $seedId => [$name, $phone, $type, $seats, $make, $model, $colour, $plate, $lat, $lng, $online]) {
            $user = $this->createRecord(User::class, [
                '_key'         => $this->fixtureKey('driver_user_' . $seedId),
                'company_uuid' => $company->uuid,
                'name'         => $name,
                'email'        => $seedId . '.driver@demo.fleetbase.io',
                'phone'        => $phone,
                'password'     => Hash::make($this->seedName()),
                'type'         => 'driver',
                'status'       => 'active',
                'timezone'     => 'Asia/Singapore',
                'country'      => 'SG',
                'meta'         => $this->meta('driver_user_' . $seedId),
            ]);

            $vehicle = $this->createRecord(Vehicle::class, [
                '_key'             => $this->fixtureKey('vehicle_' . $seedId),
                'company_uuid'     => $company->uuid,
                'make'             => $make,
                'model'            => $model,
                'year'             => '2025',
                'trim'             => $colour,
                'color'            => $colour,
                'type'             => $type,
                'seating_capacity' => $seats,
                'status'           => 'available',
                'online'           => $online,
                'plate_number'     => $plate,
                'location'         => $this->point($lat, $lng),
                'currency'         => 'SGD',
                'fuel_type'        => $model === 'Ioniq 5' ? 'electric' : 'petrol',
                'meta'             => $this->meta('vehicle_' . $seedId),
            ]);

            $drivers[$seedId] = $this->createRecord(Driver::class, [
                '_key'                   => $this->fixtureKey('driver_' . $seedId),
                'company_uuid'           => $company->uuid,
                'user_uuid'              => $user->uuid,
                'vehicle_uuid'           => $vehicle->uuid,
                'drivers_license_number' => 'S' . substr(preg_replace('/\D/', '', $phone), -7) . 'P',
                'location'               => $this->point($lat, $lng),
                'country'                => 'SG',
                'currency'               => 'SGD',
                'city'                   => 'Singapore',
                'online'                 => $online,
                'status'                 => 'available',
                'current_status'         => $online ? 'available' : 'off_duty',
                'meta'                   => $this->meta('driver_' . $seedId),
            ]);
        }

        return $drivers;
    }

    // ── The rider ───────────────────────────────────────────────────────────────────────

    protected function seedRider(Company $company): Contact
    {
        // The customer API signs a rider in by their user's phone. Contact's observer makes the
        // user when the contact is saved with events, as the console does.
        /** @var Contact $rider */
        $rider = $this->createRecord(Contact::class, [
            '_key'         => $this->fixtureKey('rider'),
            'company_uuid' => $company->uuid,
            'name'         => 'Sophie Lim',
            'email'        => 'sophie.lim@example.test',
            'phone'        => static::RIDER_PHONE,
            'type'         => 'customer',
            'meta'         => $this->meta('rider'),
        ]);

        $user = $rider->user_uuid ? User::where('uuid', $rider->user_uuid)->first() : null;
        if (!$user) {
            $user = $this->createRecord(User::class, [
                '_key'         => $this->fixtureKey('rider_user'),
                'company_uuid' => $company->uuid,
                'name'         => 'Sophie Lim',
                'email'        => 'sophie.lim@example.test',
                'phone'        => static::RIDER_PHONE,
                'type'         => 'customer',
                'status'       => 'active',
                'timezone'     => 'Asia/Singapore',
                'country'      => 'SG',
                'meta'         => $this->meta('rider_user'),
            ]);
            Contact::withoutEvents(fn () => $rider->forceFill(['user_uuid' => $user->uuid])->save());
        }
        // Tagged, so the purge removes the observer's user too.
        User::where('uuid', $user->uuid)->update([
            '_key'              => $this->fixtureKey('rider_user'),
            'phone'             => static::RIDER_PHONE,
            'status'            => 'active',
            'phone_verified_at' => $this->timestamp(-24 * 90),
        ]);

        return $rider;
    }

    /**
     * seed id => [name, street, lat, lng, owned by the rider]. The rider's own are her saved
     * places; the rest are where her past trips went.
     */
    protected function placeFixtures(): array
    {
        return [
            'home'          => ['Home', '78 Guan Chuan Street', 1.28521, 103.83073, true],
            'work'          => ['Work', '1 Fusionopolis Way', 1.29891, 103.78744, true],
            'pickup'        => ['Marina Bay Sands', '10 Bayfront Avenue', 1.28345, 103.86072, false],
            'changi'        => ['Changi Airport Terminal 3', '65 Airport Boulevard', 1.35553, 103.98637, false],
            'botanic'       => ['Singapore Botanic Gardens', '1 Cluny Road', 1.31384, 103.81589, false],
            'jewel'         => ['Jewel Changi Airport', '78 Airport Boulevard', 1.36017, 103.98953, false],
            'national'      => ['National Gallery Singapore', '1 St Andrew\'s Road', 1.29014, 103.85155, false],
        ];
    }

    protected function seedPlaces(Company $company, Contact $rider): array
    {
        $places = [];
        foreach ($this->placeFixtures() as $seedId => [$name, $street, $lat, $lng, $owned]) {
            $places[$seedId] = $this->createRecord(Place::class, [
                '_key'         => $this->fixtureKey('place_' . $seedId),
                'company_uuid' => $company->uuid,
                'owner_uuid'   => $owned ? $rider->uuid : null,
                'owner_type'   => $owned ? Contact::class : null,
                'name'         => $name,
                'street1'      => $street,
                'city'         => 'Singapore',
                'country'      => 'SG',
                'location'     => $this->point($lat, $lng),
                'meta'         => $this->meta('place_' . $seedId),
            ]);
        }

        return $places;
    }

    // ── Trip history ────────────────────────────────────────────────────────────────────

    /**
     * seed id => [days ago, hour, from, to, class, driver, distance m, time s, payment, status].
     * Newest first, as the app lists them. Cash: a card trip would read "Card payment not
     * completed" without a settled Ledger transaction behind it.
     */
    protected function tripFixtures(): array
    {
        return [
            'trip_gallery'  => [1, 19, 'work', 'national', 'sedan', 'aisyah', 9800, 1260, 'cash', 'completed'],
            'trip_airport'  => [4, 7, 'home', 'changi', 'mpv', 'ravi', 24600, 1680, 'cash', 'completed'],
            'trip_canceled' => [6, 18, 'pickup', 'home', 'sedan', null, 0, 0, 'cash', 'canceled'],
            'trip_gardens'  => [9, 10, 'home', 'botanic', 'sedan', 'marcus', 4200, 780, 'cash', 'completed'],
            'trip_jewel'    => [15, 14, 'pickup', 'jewel', 'sedan', 'marcus', 21300, 1500, 'cash', 'completed'],
        ];
    }

    protected function seedTrips(Company $company, OrderConfig $config, array $rates, array $drivers, Contact $rider, array $places): void
    {
        foreach ($this->tripFixtures() as $seedId => [$daysAgo, $hour, $from, $to, $type, $driverSeedId, $distance, $time, $payment, $status]) {
            // Singapore time, stored in UTC like every other timestamp the API writes.
            $at     = $this->timestamp()->subDays($daysAgo)->setTime($hour, 12)->utc();
            $rate   = $rates[$type];
            $driver = $driverSeedId ? $drivers[$driverSeedId] : null;

            $payload = $this->createRecord(Payload::class, [
                '_key'           => $this->fixtureKey($seedId . '_payload'),
                'company_uuid'   => $company->uuid,
                'pickup_uuid'    => $places[$from]->uuid,
                'dropoff_uuid'   => $places[$to]->uuid,
                'type'           => 'passenger',
                'payment_method' => $payment,
                'meta'           => $this->meta($seedId . '_payload'),
            ]);

            $purchaseRate = null;
            if ($status === 'completed') {
                $purchaseRate = $this->purchase($company, $rate, $rider, $payload, $seedId, $distance, $time, $at);
            }

            $order = $this->createRecord(Order::class, [
                '_key'                  => $this->fixtureKey($seedId),
                'company_uuid'          => $company->uuid,
                'payload_uuid'          => $payload->uuid,
                'order_config_uuid'     => $config->uuid,
                'purchase_rate_uuid'    => $purchaseRate?->uuid,
                'customer_uuid'         => $rider->uuid,
                'customer_type'         => Contact::class,
                'driver_assigned_uuid'  => $driver?->uuid,
                'vehicle_assigned_uuid' => $driver?->vehicle_uuid,
                'type'                  => $config->key,
                'status'                => $status,
                'adhoc'                 => true,
                'dispatched'            => $status === 'completed',
                'dispatched_at'         => $status === 'completed' ? $at->copy()->addMinute() : null,
                'started'               => $status === 'completed',
                'started_at'            => $status === 'completed' ? $at->copy()->addMinutes(2) : null,
                'distance'              => $distance,
                'time'                  => $time,
                'required_vehicle_type' => $type,
                'meta'                  => $this->meta($seedId),
                'created_at'            => $at,
                'updated_at'            => $at->copy()->addSeconds($time + 600),
            ]);

            // The tracking number observer resets the status to `created`; put it back.
            Order::withoutGlobalScopes()->whereKey($order->getKey())->update(['status' => $status]);
        }
    }

    /**
     * The fare a finished trip was bought at: a service quote with its lines, from the rate's
     * own algorithm on the trip's distance and time, and the purchase rate that names it.
     */
    protected function purchase(Company $company, ServiceRate $rate, Contact $rider, Payload $payload, string $seedId, int $distance, int $time, $at): PurchaseRate
    {
        $baseFee  = (int) $rate->getRawOriginal('base_fee');
        $tripFee  = (int) round(\Fleetbase\FleetOps\Support\Algo::exec($rate->algorithm, ['distance_m' => $distance, 'time_s' => $time], true));
        $total    = $baseFee + $tripFee;

        $quote = $this->createRecord(ServiceQuote::class, [
            '_key'              => $this->fixtureKey($seedId . '_quote'),
            'company_uuid'      => $company->uuid,
            'service_rate_uuid' => $rate->uuid,
            'payload_uuid'      => $payload->uuid,
            'amount'            => $total,
            'currency'          => 'SGD',
            'distance'          => $distance,
            'time'              => $time,
            'meta'              => $this->meta($seedId . '_quote', ['repriced' => true]),
            'created_at'        => $at,
            'updated_at'        => $at,
        ]);

        foreach ([['Base fare', 'BASE_FEE', $baseFee], ['Distance and time', 'SERVICE_FEE', $tripFee]] as $index => [$details, $code, $amount]) {
            $this->createRecord(ServiceQuoteItem::class, [
                '_key'               => $this->fixtureKey($seedId . '_quote_item_' . $index),
                'service_quote_uuid' => $quote->uuid,
                'amount'             => $amount,
                'currency'           => 'SGD',
                'details'            => $details,
                'code'               => $code,
                'created_at'         => $at,
                'updated_at'         => $at,
            ]);
        }

        return $this->createRecord(PurchaseRate::class, [
            '_key'               => $this->fixtureKey($seedId . '_purchase'),
            'company_uuid'       => $company->uuid,
            'customer_uuid'      => $rider->uuid,
            'customer_type'      => Contact::class,
            'service_quote_uuid' => $quote->uuid,
            'payload_uuid'       => $payload->uuid,
            'status'             => 'paid',
            'meta'               => $this->meta($seedId . '_purchase', ['repriced' => true]),
            'created_at'         => $at,
            'updated_at'         => $at,
        ]);
    }

    /** The app's Help screen shows the operator's phone number. Set only where none is. */
    protected function ensureSupportPhone(Company $company): void
    {
        if (empty($company->phone)) {
            Company::where('uuid', $company->uuid)->update(['phone' => '+6561000100']);
        }
    }
}
