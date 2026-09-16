<?php

namespace Database\Seeders\Showcase\Iam;

use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\Iam\Concerns\BelongsToIam;
use Fleetbase\Models\Company;
use Fleetbase\Models\Group;
use Fleetbase\Models\GroupUser;
use Fleetbase\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Groups and their membership — iam-groups-management and iam-manage-group-members.
 *
 * Depends on UsersSeeder: members are resolved by seed id, so this runs after it.
 */
class GroupsSeeder extends Seeder
{
    use SeedsShowcaseData;
    use BelongsToIam;

    /** [name, description, [member seed ids]] */
    protected function groupFixtures(): array
    {
        return [
            'operations' => [
                'Operations',
                'Dispatch, scheduling and day-to-day order management.',
                ['ops_lead', 'dispatcher', 'coordinator'],
            ],
            'fleet' => [
                'Fleet & Maintenance',
                'Vehicle readiness, servicing and workshop scheduling.',
                ['fleet_super', 'maintenance', 'inventory'],
            ],
            'back_office' => [
                'Back Office',
                'Finance, customer support and platform integration.',
                ['finance', 'support', 'developer'],
            ],
        ];
    }

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        $this->purgeSeedData();
        $this->seedGroups($company);

        $this->command?->info('Seeded ' . $this->seedName() . ' groups for ' . $company->public_id);
    }

    public function purgeSeedData(): void
    {
        $this->purgeModel(GroupUser::class);
        $this->purgeModel(Group::class);
    }

    protected function seedGroups(Company $company): void
    {
        foreach ($this->groupFixtures() as $seedId => [$name, $description, $memberSeedIds]) {
            $group = $this->createRecord(Group::class, [
                '_key'         => $this->fixtureKey($seedId),
                'company_uuid' => $company->uuid,
                'name'         => $name,
                'description'  => $description,
                'slug'         => Str::slug($name),
            ]);

            foreach ($memberSeedIds as $memberSeedId) {
                /** @var User|null $user */
                $user = $this->seededModel(User::class, $memberSeedId);

                // A missing member means UsersSeeder and this file have drifted apart. Warn
                // rather than throw: a group short one person still screenshots usefully, and
                // aborting here would take the whole area down with it.
                if (!$user) {
                    $this->command?->warn("Group '{$name}' references unknown user '{$memberSeedId}'");
                    continue;
                }

                $this->createRecord(GroupUser::class, [
                    '_key'       => $this->fixtureKey($seedId . '_' . $memberSeedId),
                    'group_uuid' => $group->uuid,
                    'user_uuid'  => $user->uuid,
                ]);
            }
        }
    }
}
