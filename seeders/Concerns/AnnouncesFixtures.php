<?php

namespace Database\Seeders\Showcase\Concerns;

/**
 * The SEEDED_FIXTURES echo protocol.
 *
 * Detail-page screenshots need a record's public_id in their URL, and public_id is generated
 * fresh on every seed — so the capture resolves it at runtime by looking the record up through
 * the internal API by a stable handle (a name, an internal_id).
 *
 * This is the second opinion. Each area seeder echoes the public_ids it actually created, the
 * capture compares them against what it resolved independently, and a disagreement fails the
 * run. Without it, a manifest and a fixture set that have drifted apart produce a screenshot
 * of the wrong record — which survives review, because nothing about it looks broken.
 *
 * Follows the MINTED_ and SEEDED_ line protocol that the monorepo's scripts/ci/mint-api-key.php
 * established, so scripts/seed.sh can grep for it the same way.
 */
trait AnnouncesFixtures
{
    /**
     * @param array<string, string|null> $fixtures manifest entry id => public_id
     */
    protected function announceFixtures(array $fixtures): void
    {
        // array_filter drops entries whose record did not resolve. Announcing a null would
        // make the capture's cross-check pass vacuously, which is worse than announcing
        // nothing at all.
        echo PHP_EOL . 'SEEDED_FIXTURES=' . json_encode(array_filter($fixtures)) . PHP_EOL;
    }
}
