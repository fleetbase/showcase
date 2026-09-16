<?php

namespace Database\Seeders\Showcase\Iam;

use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\Iam\Concerns\BelongsToIam;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Database\Seeder;

/**
 * The people on the IAM users screen.
 *
 * Roles are NOT seeded here. `php artisan fleetbase:create-permissions` — which deploy.sh
 * already runs — creates 27 of them (Administrator, Fleet Supervisor, Dispatcher, …) along
 * with the policy set. So iam-roles-management and iam-policy-management capture correctly
 * with no showcase fixtures at all; what the platform does not give us is people to assign
 * them to, which is all this seeder does.
 *
 * Every user is verified and active on purpose. An unverified user renders a warning banner
 * that would date the screenshot, and a real organization's user list is mostly people who
 * have finished signing up.
 */
class UsersSeeder extends Seeder
{
    use SeedsShowcaseData;
    use BelongsToIam;

    /**
     * [name, email, phone, role, status].
     *
     * Deliberately varied roles, because the point of the screenshot is to show that roles
     * differ per person — a table where every row says "Administrator" demonstrates nothing.
     * The @demo.fleetbase.io domain is one Fleetbase controls, so nothing here can reach a
     * real inbox even if a mail transport were configured.
     */
    protected function userFixtures(): array
    {
        return [
            'ops_lead'      => ['Amara Chen', 'amara.chen@demo.fleetbase.io', '+6581000011', 'Operations Manager', 'active'],
            'dispatcher'    => ['Priya Nair', 'priya.nair@demo.fleetbase.io', '+6581000012', 'Service Coordinator', 'active'],
            'fleet_super'   => ['Kenji Tan', 'kenji.tan@demo.fleetbase.io', '+6581000013', 'Fleet Supervisor', 'active'],
            'maintenance'   => ['Rahul Menon', 'rahul.menon@demo.fleetbase.io', '+6581000014', 'Maintenance Technician', 'active'],
            'finance'       => ['Grace Wong', 'grace.wong@demo.fleetbase.io', '+6581000015', 'Accountant', 'active'],
            'support'       => ['Siti Nurhaliza', 'siti.n@demo.fleetbase.io', '+6581000016', 'Customer Service', 'active'],
            'developer'     => ['Marcus Lim', 'marcus.lim@demo.fleetbase.io', '+6581000017', 'Fleetbase Developer', 'active'],
            'inventory'     => ['Aisha Karim', 'aisha.karim@demo.fleetbase.io', '+6581000018', 'Inventory Manager', 'active'],
            'coordinator'   => ['Daniel Ong', 'daniel.ong@demo.fleetbase.io', '+6581000019', 'Driver Coordinator', 'active'],
        ];
    }

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        $this->purgeSeedData();
        $this->seedUsers($company);

        $this->command?->info('Seeded ' . $this->seedName() . ' users for ' . $company->public_id);
    }

    public function purgeSeedData(): void
    {
        // Pivot before principal: a CompanyUser row pointing at a deleted user would leave the
        // organization switcher listing a member who no longer exists.
        $this->purgeModel(CompanyUser::class);
        $this->purgeModel(User::class);
    }

    protected function seedUsers(Company $company): array
    {
        $models   = [];
        $password = getenv('SHOWCASE_PASSWORD') ?: null;

        foreach ($this->userFixtures() as $seedId => [$name, $email, $phone, $role, $status]) {
            $user = $this->createRecord(User::class, [
                '_key'              => $this->fixtureKey($seedId),
                'company_uuid'      => $company->uuid,
                'name'              => $name,
                'email'             => $email,
                'phone'             => $phone,
                'type'              => 'user',
                'status'            => $status,
                'timezone'          => 'Asia/Singapore',
                'country'           => 'SG',
                // Same reason the organization owner needs it: an unverified user is a warning
                // banner in the UI, and AuthController refuses to log them in.
                'email_verified_at' => $this->timestamp(-48),
                'last_login'        => $this->timestamp(-2),
                'meta'              => $this->meta($seedId),
            ]);

            // Only set when we have one, so this seeder does not fabricate a credential. These
            // accounts exist to populate a list; the capture logs in as the owner.
            if ($password) {
                $user->password = $password;
                $user->save();
            }

            $this->createRecord(CompanyUser::class, [
                '_key'         => $this->fixtureKey($seedId . '_membership'),
                'company_uuid' => $company->uuid,
                'user_uuid'    => $user->uuid,
                'status'       => 'active',
            ]);

            // Roles come from create-permissions, so a typo here is silent — assignSingleRole
            // simply finds nothing. Report it rather than shipping a users table with an empty
            // role column.
            try {
                $user->assignSingleRole($role);
            } catch (\Throwable $e) {
                $this->command?->warn("Could not assign role '{$role}' to {$name}: " . $e->getMessage());
            }

            $models[$seedId] = $user;
        }

        return $models;
    }
}
