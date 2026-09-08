<?php

namespace Database\Seeders\Showcase\Iam;

use Database\Seeders\Showcase\Concerns\AnnouncesFixtures;
use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Fleetbase\Models\User;
use Illuminate\Database\Seeder;

/**
 * IAM: people, groups and their access.
 *
 * Covers the eleven iam-*.webp files on fleetbase.io. Note what is NOT here: roles,
 * policies and permissions all come from `php artisan fleetbase:create-permissions`, which
 * deploy.sh runs during provisioning and which produces 27 roles and a full policy set. The
 * roles and policies screens therefore capture correctly against a bare provisioned stack —
 * what the platform cannot provide is people to assign them to.
 */
class IamSeeder extends Seeder
{
    use SeedsShowcaseData;
    use AnnouncesFixtures;

    public function run(): void
    {
        // Users first: groups resolve their members by seed id.
        $this->call([
            UsersSeeder::class,
            GroupsSeeder::class,
        ]);

        $this->announceFixtures([
            // The user-details screenshot navigates by public_id, resolved at capture time by
            // name. Announcing it here gives that lookup a second, independent source to agree
            // with — a disagreement fails the run instead of screenshotting the wrong person.
            'iam-user-details' => $this->seededModel(User::class, 'ops_lead')?->public_id,
        ]);
    }

    public function purgeSeedData(): void
    {
        // Reverse of the seed order.
        (new GroupsSeeder())->purgeSeedData();
        (new UsersSeeder())->purgeSeedData();
    }
}
