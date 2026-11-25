<?php

namespace Database\Seeders\Showcase\Extensions;

use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\Extensions\Concerns\BelongsToExtensions;
use Fleetbase\Models\Setting;
use Fleetbase\RegistryBridge\Models\RegistryServiceInstall;
use Fleetbase\RegistryBridge\Support\InstanceRegistration;
use Fleetbase\RegistryBridge\Support\RegistryClient;
use Fleetbase\RegistryBridge\Support\RegistrySettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;

/**
 * The Extensions screens from registry-bridge 0.3.0 (Fleetbase v0.9.0).
 *
 * The catalogue is no longer in this database. The API reads it from the Registry Service,
 * which in this stack is the stand-in at docker/registry/server.mjs serving catalog.json (the
 * file beside this one). So there is no catalogue to seed here, only the two things the
 * instance itself holds:
 *
 * 1. Its registration. Without one every button reads "Not connected". This runs the same
 *    registration an admin does from Registry Config, against the stand-in, so the token, the
 *    service URL and the organization are stored exactly as on a real instance.
 * 2. Its install records, which carry each extension's auto-update policy. The stand-in
 *    reports the same extensions as installed, from the same file.
 *
 * Ids are derived, not stored: `ext_` plus ten hex characters of sha1("showcase:extension:<slug>"),
 * the rule publicId() in server.mjs uses. Both sides compute them from catalog.json.
 */
class RegistryServiceSeeder extends Seeder
{
    use SeedsShowcaseData;
    use BelongsToExtensions;

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        if (!class_exists(RegistryServiceInstall::class)) {
            $this->command?->warn('registry-bridge is not installed in this image; skipping the Extensions fixtures.');

            return;
        }

        $catalog    = $this->catalog();
        $serviceUrl = $this->standInUrl();

        $this->purgeSeedData();
        $this->register($serviceUrl);

        $installed = 0;
        foreach ($catalog['extensions'] as $order => $extension) {
            if (!is_array($extension['installed'] ?? null)) {
                continue;
            }

            $slug      = $extension['slug'];
            $publisher = $extension['publisher'] ?? 'fleetbase';
            $version   = $extension['installed']['version'] ?? $extension['version'];

            $this->createRecord(RegistryServiceInstall::class, [
                'company_uuid'      => $company->uuid,
                'installed_by_uuid' => $company->owner_uuid,
                'extension_id'      => $this->serviceId('ext', 'extension:' . $slug),
                'slug'              => $slug,
                'name'              => $extension['name'],
                'npm_package'       => '@' . $publisher . '/' . $slug . '-engine',
                'composer_package'  => $publisher . '/' . $slug . '-api',
                'version'           => $version,
                'release_id'        => $this->serviceId('rel', 'release:' . $slug . '@' . $version),
                'auto_update'       => $extension['installed']['auto_update'] ?? 'none',
                'service_url'       => $serviceUrl,
                'meta'              => $this->meta('install:' . $slug),
                // Spread over weeks, so the list does not read as one afternoon's work.
                'created_at'        => $this->timestamp(-24 * (20 + $order * 3)),
                'updated_at'        => $this->timestamp(-24 * (20 + $order * 3)),
            ], true);
            $installed++;
        }

        // The API caches the service's answers; a reseed must not show the previous run's.
        RegistryClient::flush();

        $this->command?->info('Registered with ' . $serviceUrl . ' and recorded ' . $installed . ' installed extensions for company: ' . $company->public_id);
    }

    public function purgeSeedData(): void
    {
        $this->purgeModel(RegistryServiceInstall::class);
    }

    /**
     * @return array{extensions: array<int, array<string, mixed>>}
     */
    protected function catalog(): array
    {
        $catalog = json_decode((string) file_get_contents(__DIR__ . '/catalog.json'), true);
        if (!is_array($catalog) || !is_array($catalog['extensions'] ?? null)) {
            throw new \RuntimeException('seeders/Extensions/catalog.json is missing or is not valid JSON.');
        }

        return $catalog;
    }

    /**
     * The Registry Service URL, once it is known to be the stand-in.
     *
     * The bridge's default is https://registry.fleetbase.io. Registering there would send a
     * made-up code to the real service from every showcase run, so nothing is sent anywhere
     * until /health answers with the stand-in's own signature.
     */
    protected function standInUrl(): string
    {
        $url = rtrim((string) config('registry-bridge.registry.service_url'), '/');

        try {
            $health = Http::timeout(10)->get($url . '/health');
        } catch (\Throwable $e) {
            throw new \RuntimeException('The registry stand-in at ' . $url . ' did not answer: ' . $e->getMessage() . '. Is the `registry` service up (scripts/up.sh)?');
        }

        if (!$health->ok() || $health->json('status') !== 'ok' || !is_int($health->json('extensions'))) {
            throw new \RuntimeException($url . ' is not the showcase registry stand-in (REGISTRY_SERVICE_URL in docker-compose.yml). Not registering with it.');
        }

        return $url;
    }

    /**
     * Register this instance, as Registry Config does. The stand-in accepts any code; a second
     * run sends the stored token and gets the same instance back.
     */
    protected function register(string $serviceUrl): void
    {
        $summary = InstanceRegistration::register('SHOWCASE', $serviceUrl, 'Fleetbase Showcase');

        // register() stamps the wall clock, and Registry Config prints it.
        Setting::configure(InstanceRegistration::SETTING_INSTANCE, array_merge($summary, ['registered_at' => $this->timestamp(-24 * 120)->toAtomString()]));
        RegistrySettings::forgetCached();
    }

    /** A service-style id, by the rule publicId() in docker/registry/server.mjs uses. */
    protected function serviceId(string $prefix, string $key): string
    {
        return $prefix . '_' . substr(sha1('showcase:' . $key), 0, 10);
    }
}
