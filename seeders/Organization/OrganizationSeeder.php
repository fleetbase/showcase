<?php

namespace Database\Seeders\Showcase\Organization;

use Database\Seeders\Showcase\Concerns\AnnouncesFixtures;
use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\Seeder;

/**
 * The organization every other seeder and the capture itself depend on.
 *
 * Blocking for two separate reasons, both of which fail in ways that do not look like failures:
 *
 *   * Every other showcase seeder resolves its tenant through prepareCompany() and aborts
 *     without one.
 *   * OnboardController::shouldOnboard() answers `Company::doesntExist()`, and the console
 *     redirects EVERY route to /onboard while that is true. Without this seeder a capture run
 *     produces a directory of identical onboarding-wizard screenshots and still exits 0.
 *
 * So creating the company is also what takes the install out of onboarding. Nothing here needs
 * to touch a setting to achieve that.
 *
 * Idempotent: rerunning reasserts the same rows rather than duplicating them, so re-seeding a
 * stack that is already up does not need a fresh database.
 */
class OrganizationSeeder extends Seeder
{
    use SeedsShowcaseData;
    use AnnouncesFixtures;

    public function run(): void
    {
        $identity = getenv('SHOWCASE_IDENTITY') ?: 'demo@fleetbase.io';
        $password = getenv('SHOWCASE_PASSWORD');

        if (empty($password)) {
            // Refuse to invent one. A generated password would authenticate this seed and then
            // be unreachable, so the capture's login would fail with "invalid_credentials" and
            // nothing about that error would point back here.
            echo PHP_EOL . 'SHOWCASE_ERROR=SHOWCASE_PASSWORD is not set' . PHP_EOL;

            return;
        }

        try {
            $company = Company::firstOrCreate(['name' => $this->organizationName()]);

            $user = User::where('email', $identity)->withoutGlobalScopes()->first();
            if (!$user) {
                $user               = new User();
                $user->email        = $identity;
                $user->company_uuid = $company->uuid;
            }

            // Reasserted every run: a previous session can leave the account in a state the
            // next run cannot log into. `password` is guarded against mass assignment, so
            // direct assignment is what runs the hashing mutator.
            $user->name     = 'Fleetbase Demo';
            $user->type     = 'user';
            $user->status   = 'active';
            $user->password = $password;

            // Without this, POST auth/login answers 400 {"code":"not_verified"} and every
            // capture fails at the first request. AuthController checks
            // `$user->isNotVerified() && $user->isNotAdmin()`, and User::isVerified() is
            // satisfied by either email_verified_at or phone_verified_at being set.
            //
            // Verifying the email is the right lever rather than making the account type
            // 'admin' (which would also pass the check): an admin renders a different console
            // — extra navigation, different permissions — and these screenshots are supposed
            // to show what an ordinary operator sees.
            //
            // A fixed timestamp rather than now(), so nothing derived from it moves between runs.
            $user->email_verified_at = $this->timestamp(-24);

            $user->save();

            // Ownership BEFORE membership, and the order is load-bearing. User::assignCompany()
            // checks `isNotAdmin() && !isCompanyOwner($company)` and, when both hold, sends an
            // invite email and notifies the owner. Establishing ownership first makes that
            // branch unreachable, so a stack with a real mail transport sends nothing.
            if (empty($company->owner_uuid)) {
                $company->setOwner($user, true);
                $company->save();
            }

            // Creates the CompanyUser pivot the organization switcher reads, and assigns the
            // Administrator role — which is what grants the policies created by
            // `php artisan fleetbase:create-permissions`. Without the role the console renders
            // empty tables, which screenshots as a successful but useless run.
            $user->assignCompany($company, 'Administrator');

            $this->command?->info('Showcase organization ready: ' . $company->public_id);

            $this->announceFixtures([
                'organization' => $company->public_id,
                'owner'        => $user->public_id,
            ]);
        } catch (\Throwable $e) {
            // The whole seeder runs inside one try, so the first throw silently skips
            // everything after it. scripts/seed.sh greps for this line and fails the step.
            echo PHP_EOL . 'SHOWCASE_ERROR=' . $e->getMessage() . PHP_EOL;
            echo $e->getTraceAsString() . PHP_EOL;
        }
    }
}
