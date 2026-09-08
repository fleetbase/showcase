<?php

namespace Database\Seeders\Showcase;

use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds every showcase area, in dependency order.
 *
 * Purge-then-seed, so a rerun replaces the fixture set rather than layering a second copy on
 * top of it. Both halves are scoped to `meta->seed = 'showcase'`, so nothing outside this
 * fixture set is touched — an install can carry fleetops' own `fleetops-testing` rows
 * alongside these and neither disturbs the other.
 *
 * Areas are listed as class names and skipped when the class is absent, because they land one
 * at a time (see the build order in the repo README). A missing area is a gap in coverage, not
 * a broken seed — the capture manifest for that area simply has nothing to shoot, and its
 * expectRows assertions fail loudly if it is asked to.
 */
class ShowcaseSeeder extends Seeder
{
    use SeedsShowcaseData;

    /**
     * Order matters. Organization is first because every other seeder resolves its tenant
     * through it. The rest follow the dependency graph: places and people before the orders
     * that reference them.
     */
    protected const AREAS = [
        Organization\OrganizationSeeder::class,
        General\GeneralSeeder::class,
        Iam\IamSeeder::class,
        FleetOps\FleetOpsSeeder::class,
        Developers\DevelopersSeeder::class,
        Storefront\StorefrontSeeder::class,
        Ledger\LedgerSeeder::class,
    ];

    public function run(): void
    {
        $present = array_values(array_filter(static::AREAS, 'class_exists'));
        $missing = array_diff(static::AREAS, $present);

        foreach ($missing as $class) {
            $this->command?->warn('Skipping ' . class_basename($class) . ' — not implemented yet.');
        }

        // Purge in reverse, so rows are removed before the rows they point at. Foreign keys
        // are disabled around it anyway, because the fixture graph is not a clean DAG (orders
        // reference drivers which reference vehicles which reference fleets).
        Schema::disableForeignKeyConstraints();
        try {
            foreach (array_reverse($present) as $class) {
                $seeder = new $class();
                if (method_exists($seeder, 'purgeSeedData')) {
                    $seeder->purgeSeedData();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->call($present);
    }
}
