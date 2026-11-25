<?php

namespace Database\Seeders\Showcase\Extensions;

use Fleetbase\RegistryBridge\Models\RegistryExtension;
use Illuminate\Database\Seeder;

/**
 * The Extensions screens (registry-bridge, /extensions).
 *
 * registry-bridge 0.3.0 (Fleetbase v0.9.0) replaced the marketplace an instance kept in its own
 * database with the Registry Service, and removed the marketplace's models. Which seeder runs
 * therefore depends on the release in the image:
 *
 *   before 0.3.0   MarketplaceSeeder: writes the catalogue into registry_extensions.
 *   0.3.0 onwards  RegistryServiceSeeder: registers this instance with the registry stand-in
 *                  (docker/registry) and records what the organization has installed. The
 *                  catalogue itself is catalog.json, which the stand-in serves.
 *
 * The check is the marketplace model, not a version number: it is the thing MarketplaceSeeder
 * cannot run without.
 */
class ExtensionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(class_exists(RegistryExtension::class) ? MarketplaceSeeder::class : RegistryServiceSeeder::class);
    }
}
