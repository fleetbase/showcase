<?php

namespace Database\Seeders\Showcase\Iam\Concerns;

/**
 * Tags every IAM row `showcase-iam`.
 *
 * Both IAM and Fleet-Ops write user records, so a shared seed tag would let either area's
 * purge delete the other's rows. See SeedsShowcaseData::seedArea().
 */
trait BelongsToIam
{
    protected function seedArea(): ?string
    {
        return 'iam';
    }
}
