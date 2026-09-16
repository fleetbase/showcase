<?php

namespace Database\Seeders\Showcase\FleetOps\Concerns;

/**
 * Tags every Fleet-Ops row `showcase-fleet-ops`.
 *
 * EVERY seeder in this area must use it, including the orchestrator. An earlier version put
 * seedArea() on ResolvesFleetOpsFixtures, which only three of the six seeders needed — so
 * orders and maintenance were written under the plain `showcase` tag while places, fleets and
 * devices used `showcase-fleet-ops`. The set was split across two tags, and purging one left
 * the other behind to collide on the next run.
 *
 * Kept separate from ResolvesFleetOpsFixtures so the two concerns compose without a trait
 * method collision: PHP refuses when two traits in the same class declare the same method.
 */
trait BelongsToFleetOps
{
    protected function seedArea(): ?string
    {
        return 'fleet-ops';
    }
}
