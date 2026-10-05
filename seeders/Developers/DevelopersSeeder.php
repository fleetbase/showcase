<?php

namespace Database\Seeders\Showcase\Developers;

use Database\Seeders\Showcase\Concerns\AnnouncesFixtures;
use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\Developers\Concerns\BelongsToDevelopers;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\ApiEvent;
use Fleetbase\Models\ApiRequestLog;
use Fleetbase\Models\Company;
use Fleetbase\Models\WebhookEndpoint;
use Fleetbase\Models\WebhookRequestLog;
use Illuminate\Database\Seeder;

/**
 * The Developers console: API keys, webhooks with delivery attempts, API events, and request
 * logs.
 *
 * Everything is created without model events. Two reasons: the observers on these models
 * dispatch webhook jobs and broadcast socket events, which would reach out to the fixture URLs
 * and write more rows behind the seeder's back; and the keys and public ids would otherwise
 * be random, which is pixels that change on every run for no reason. So they are derived from
 * the seed id instead.
 *
 * The hostnames are under .example, reserved for documentation — nothing is ever sent there.
 */
class DevelopersSeeder extends Seeder
{
    use SeedsShowcaseData;
    use BelongsToDevelopers;
    use AnnouncesFixtures;

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        $this->purgeSeedData();

        $credentials = $this->seedApiCredentials($company);
        $webhooks    = $this->seedWebhooks($company, $credentials['key_production']);
        $events      = $this->seedEvents($company, $credentials['key_production']);
        $this->seedWebhookAttempts($company, $webhooks['webhook_orders'], $credentials['key_production'], $events);
        $this->seedRequestLogs($company, $credentials);

        $this->command?->info('Seeded ' . $this->seedName() . ' fixtures for company: ' . $company->public_id);

        // Detail screens are addressed straight from these (manifest `fromSeed`): webhook
        // endpoints by uuid, events and request logs by the public ids derived above.
        $this->announceFixtures([
            'webhook-details'     => $webhooks['webhook_orders']->uuid,
            'event-details'       => $events['event_order_dispatched']->public_id,
            'request-log-details' => $this->publicId('request', 'request_log_5'),
        ]);
    }

    public function purgeSeedData(): void
    {
        $this->purgeModel(WebhookRequestLog::class);
        $this->purgeModel(ApiRequestLog::class);
        $this->purgeModel(ApiEvent::class);
        $this->purgeModel(WebhookEndpoint::class);
        $this->purgeModel(ApiCredential::class);
    }

    /** A deterministic token derived from the seed id: same pixels every run. */
    protected function token(string $seedId, int $length = 32): string
    {
        return substr(hash('sha256', $this->seedName() . ':' . $seedId), 0, $length);
    }

    protected function publicId(string $type, string $seedId): string
    {
        return $type . '_' . substr(hash('sha256', $this->seedName() . ':' . $seedId), 0, 10);
    }

    protected function seedApiCredentials(Company $company): array
    {
        $owner = $company->owner_uuid;
        $keys  = [
            //  seed id              name                      test   origins                                   last used (hours)
            'key_production' => ['Production Backend',      false, [],                                       10],
            'key_mobile'     => ['Customer Mobile App',     false, ['https://app.fleetbase-demo.example'],   9],
            'key_staging'    => ['Staging Integration',     true,  [],                                       4],
        ];

        $models = [];
        foreach ($keys as $seedId => [$name, $test, $origins, $lastUsed]) {
            $models[$seedId] = $this->createRecord(ApiCredential::class, [
                '_key'            => $this->fixtureKey($seedId),
                'company_uuid'    => $company->uuid,
                'user_uuid'       => $owner,
                'name'            => $name,
                'key'             => ($test ? 'flb_test_' : 'flb_live_') . $this->token($seedId . ':key', 24),
                'secret'          => '$' . ($test ? 'flb_test_' : 'flb_live_') . $this->token($seedId . ':secret', 40),
                'test_mode'       => $test,
                'api'             => 'v1',
                'browser_origins' => $origins,
                'last_used_at'    => $this->timestamp($lastUsed),
                'created_at'      => $this->timestamp(-720),
                'updated_at'      => $this->timestamp($lastUsed),
            ], true);
        }

        return $models;
    }

    protected function seedWebhooks(Company $company, ApiCredential $credential): array
    {
        $hooks = [
            //  seed id               url                                                         events                                                                         description
            'webhook_orders' => ['https://hooks.fleetbase-demo.example/fleetbase/orders',  ['order.created', 'order.dispatched', 'order.completed', 'order.failed'], 'Order lifecycle events for the customer notification service'],
            'webhook_erp'    => ['https://erp.fleetbase-demo.example/integrations/fleetbase', ['*'],                                                                   'Every event, mirrored into the ERP'],
        ];

        $models = [];
        foreach ($hooks as $seedId => [$url, $events, $description]) {
            $models[$seedId] = $this->createRecord(WebhookEndpoint::class, [
                '_key'                => $this->fixtureKey($seedId),
                'company_uuid'        => $company->uuid,
                'created_by_uuid'     => $company->owner_uuid,
                'updated_by_uuid'     => $company->owner_uuid,
                'api_credential_uuid' => $credential->uuid,
                'url'                 => $url,
                'mode'                => 'live',
                'version'             => 'v1',
                'description'         => $description,
                'events'              => $events,
                'status'              => 'enabled',
                'created_at'          => $this->timestamp(-600),
                'updated_at'          => $this->timestamp(-600),
            ], true);
        }

        return $models;
    }

    protected function seedEvents(Company $company, ApiCredential $credential): array
    {
        $events = [
            //  seed id                     event                   description                                        hours
            'event_order_created'    => ['order.created',      'Order FLT-2007 was created',                      8],
            'event_order_dispatched' => ['order.dispatched',   'Order FLT-2007 was dispatched to Amara Chen',     8.5],
            'event_driver_assigned'  => ['driver.assigned',    'Amara Chen was assigned to FLT-2007',             8.6],
            'event_order_updated'    => ['order.updated',      'Order FLT-2007 moved to Picked Up',              9],
            'event_order_completed'  => ['order.completed',    'Order FLT-2004 was delivered',                    9.5],
            'event_order_failed'     => ['order.failed',       'Delivery attempt failed for FLT-2012',            10],
        ];

        $models = [];
        foreach ($events as $seedId => [$event, $description, $hours]) {
            [$resource] = explode('.', $event);
            $models[$seedId] = $this->createRecord(ApiEvent::class, [
                '_key'                => $this->fixtureKey($seedId),
                'public_id'           => $this->publicId('event', $seedId),
                'company_uuid'        => $company->uuid,
                'api_credential_uuid' => $credential->uuid,
                'event'               => $event,
                'source'              => 'api',
                'method'              => $event === 'order.created' ? 'POST' : 'PUT',
                'description'         => $description,
                'data'                => [
                    'id'          => $resource === 'driver' ? 'driver_amara' : 'order_flt2007',
                    'internal_id' => $event === 'order.completed' ? 'FLT-2004' : ($event === 'order.failed' ? 'FLT-2012' : 'FLT-2007'),
                    'status'      => explode('.', $event)[1],
                ],
                'created_at' => $this->timestamp(0)->addMinutes((int) round($hours * 60)),
                'updated_at' => $this->timestamp(0)->addMinutes((int) round($hours * 60)),
            ], true);
        }

        return $models;
    }

    protected function seedWebhookAttempts(Company $company, WebhookEndpoint $webhook, ApiCredential $credential, array $events): void
    {
        $attempts = [
            //  event seed id              status  reason                   ms    attempt
            ['event_order_created',     200, 'OK',                    142,  1],
            ['event_order_dispatched',  200, 'OK',                    118,  1],
            ['event_order_updated',     503, 'Service Unavailable',   3004, 1],
            ['event_order_updated',     200, 'OK',                    131,  2],
            ['event_order_completed',   200, 'OK',                    127,  1],
            ['event_order_failed',      200, 'OK',                    139,  1],
        ];

        foreach ($attempts as $index => [$eventSeedId, $status, $reason, $ms, $attempt]) {
            $event  = $events[$eventSeedId];
            $seedId = 'webhook_attempt_' . $index;
            $this->createRecord(WebhookRequestLog::class, [
                '_key'                => $this->fixtureKey($seedId),
                'public_id'           => $this->publicId('webhook_request_log', $seedId),
                'company_uuid'        => $company->uuid,
                'webhook_uuid'        => $webhook->uuid,
                'api_credential_uuid' => $credential->uuid,
                'api_event_uuid'      => $event->uuid,
                'method'              => 'POST',
                'status_code'         => $status,
                'reason_phrase'       => $reason,
                // Seconds: the column is decimal(17,14), three digits before the point.
                'duration'            => round($ms / 1000, 3),
                'url'                 => $webhook->url,
                'attempt'             => $attempt,
                'response'            => $status === 200 ? ['received' => true] : ['error' => 'upstream timeout'],
                'status'              => $status === 200 ? 'successful' : 'failed',
                'headers'             => ['Content-Type' => 'application/json', 'X-Fleetbase-Event' => $event->event],
                'sent_at'             => $event->created_at->copy()->addSeconds(2 + 60 * ($attempt - 1)),
                'created_at'          => $event->created_at->copy()->addSeconds(2 + 60 * ($attempt - 1)),
                'updated_at'          => $event->created_at->copy()->addSeconds(2 + 60 * ($attempt - 1)),
            ], true);
        }
    }

    protected function seedRequestLogs(Company $company, array $credentials): void
    {
        $base = 'https://api.fleetbase-demo.example';
        $logs = [
            //  key                 method    path                              status reason                    ms   hours
            ['key_production', 'POST',   '/v1/orders',                         201, 'Created',               212, 8],
            ['key_production', 'GET',    '/v1/orders/order_flt2007',           200, 'OK',                     48, 8.4],
            ['key_mobile',     'GET',    '/v1/orders?status=dispatched',       200, 'OK',                     61, 8.7],
            ['key_production', 'PATCH',  '/v1/orders/order_flt2007',           200, 'OK',                     97, 9],
            ['key_mobile',     'GET',    '/v1/drivers/driver_amara/location',  200, 'OK',                     35, 9.2],
            ['key_production', 'POST',   '/v1/orders',                         422, 'Unprocessable Entity',   74, 9.6],
            ['key_staging',    'GET',    '/v1/service-quotes',                 200, 'OK',                    156, 10],
            ['key_production', 'DELETE', '/v1/webhooks/webhook_legacy',        204, 'No Content',             52, 10.4],
        ];

        foreach ($logs as $index => [$keySeedId, $method, $path, $status, $reason, $ms, $hours]) {
            $seedId = 'request_log_' . $index;
            $at     = $this->timestamp(0)->addMinutes((int) round($hours * 60));
            $body   = $status === 422 ? ['errors' => ['payload.dropoff' => ['The dropoff field is required.']]] : ['ok' => true];
            $this->createRecord(ApiRequestLog::class, [
                '_key'                => $this->fixtureKey($seedId),
                'public_id'           => $this->publicId('request', $seedId),
                'company_uuid'        => $company->uuid,
                'api_credential_uuid' => $credentials[$keySeedId]->uuid,
                'method'              => $method,
                'path'                => ltrim(parse_url($path, PHP_URL_PATH), '/'),
                'full_url'            => $base . $path,
                'status_code'         => $status,
                'reason_phrase'       => $reason,
                // Seconds: the column is decimal(17,14), three digits before the point.
                'duration'            => round($ms / 1000, 3),
                'ip_address'          => '203.0.113.' . (20 + $index),
                'version'             => 'v1',
                'source'              => 'api',
                'content_type'        => 'application/json',
                'query_params'        => (array) (parse_url($path, PHP_URL_QUERY) ? ['status' => 'dispatched'] : []),
                'request_headers'     => ['Accept' => 'application/json', 'Authorization' => 'Bearer flb_live_••••••••'],
                'request_body'        => in_array($method, ['POST', 'PATCH'], true) ? ['internal_id' => 'FLT-2007'] : [],
                'response_headers'    => ['Content-Type' => 'application/json'],
                'response_body'       => $body,
                'created_at'          => $at,
                'updated_at'          => $at,
            ], true);
        }
    }
}
