<?php

namespace Database\Seeders\Showcase\Passenger\Concerns;

/**
 * Tags every Passenger row `showcase-passenger`, so the Fleet-Ops area's purge never touches it
 * and this area's purge never touches Fleet-Ops' rows.
 */
trait BelongsToPassenger
{
    protected function seedArea(): ?string
    {
        return 'passenger';
    }
}
