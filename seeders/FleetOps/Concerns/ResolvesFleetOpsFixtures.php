<?php

namespace Database\Seeders\Showcase\FleetOps\Concerns;

use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Zone;
use Illuminate\Database\Eloquent\Model;

/**
 * Typed lookups for the two Fleet-Ops fixtures that other fixtures reference by seed id.
 *
 * Kept out of SeedsShowcaseData deliberately: that concern is shared by every area and must
 * not import from the fleetops package, or IAM and Ledger would start depending on an
 * extension they have nothing to do with.
 */
trait ResolvesFleetOpsFixtures
{
    protected function seededServiceArea(string $seedId): ?ServiceArea
    {
        /** @var ServiceArea|null $model */
        $model = $this->seededModel(ServiceArea::class, $seedId);

        return $model;
    }

    protected function seededZone(string $seedId): ?Zone
    {
        /** @var Zone|null $model */
        $model = $this->seededModel(Zone::class, $seedId);

        return $model;
    }

    abstract protected function seededModel(string $modelClass, string $seedId): ?Model;
}
