<?php

namespace Database\Seeders\Showcase\General;

use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\General\Concerns\BelongsToGeneral;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The console's own screens: the notifications tray and page, and the chat tray.
 *
 * Everything else in the general area — dashboard, navigation, locale, account and
 * organization settings — renders from data other areas already seed.
 *
 * Notifications live in Laravel's `notifications` table, which has neither `_key` nor `meta`,
 * so they are tagged inside `data` and purged by that tag. Chat messages and participants
 * have no tag column either and are purged through their seeded channel.
 */
class GeneralSeeder extends Seeder
{
    use SeedsShowcaseData;
    use BelongsToGeneral;

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        $this->purgeSeedData();

        $owner = $company->owner_uuid ? User::where('uuid', $company->owner_uuid)->first() : null;
        if (!$owner) {
            $this->command?->warn('General fixtures skipped: the showcase company has no owner.');

            return;
        }

        $this->seedNotifications($owner);
        $this->seedChat($company, $owner);

        $this->command?->info('Seeded ' . $this->seedName() . ' fixtures for company: ' . $company->public_id);
    }

    public function purgeSeedData(): void
    {
        DB::table('notifications')->where('data', 'like', '%"showcase_seed":"' . $this->seedName() . '"%')->delete();

        $channels = $this->seededQuery(ChatChannel::class)->pluck('uuid');
        if ($channels->isNotEmpty()) {
            ChatMessage::whereIn('chat_channel_uuid', $channels)->forceDelete();
            ChatParticipant::whereIn('chat_channel_uuid', $channels)->forceDelete();
        }
        $this->purgeModel(ChatChannel::class);
    }

    protected function seedNotifications(User $owner): void
    {
        // Newest first in the tray, so the hour offsets run backwards from the fixture clock.
        $notifications = [
            //  subject                                   message                                                             hours  read
            ['Order FLT-2007 picked up',                 'Amara Chen collected the parcels at Raffles Quay and is heading to the first stop.', 9, false],
            ['Delivery attempt failed — FLT-2012',       'Recipient unavailable at Tampines Mall. The order is returning to the depot.',        8, false],
            ['Maintenance overdue — WRK-03',             'The scheduled service for the Isuzu NPR is two days overdue.',                        7, false],
            ['Trailer TRL-01 attached to JUR-07',        'Dry Van TRL-01 was coupled at the west depot for the Jurong linehaul run.',          5, true],
            ['New user joined Fleetbase Pte Ltd',        'Marcus Lim accepted the invitation and joined as Fleetbase Developer.',                      3, true],
            ['Low stock — Bendix CT-3 brake pads',       'Three sets remain against a reorder point of five.',                                 2, true],
        ];

        foreach ($notifications as $index => [$subject, $message, $hours, $read]) {
            $at = $this->timestamp($hours);
            DB::table('notifications')->insert([
                'id'              => (string) Str::uuid(),
                'type'            => 'Fleetbase\\Notifications\\ShowcaseNotification',
                'notifiable_type' => User::class,
                'notifiable_id'   => $owner->uuid,
                'data'            => json_encode([
                    'subject'       => $subject,
                    'message'       => $message,
                    'showcase_seed' => $this->seedName(),
                ]),
                'read_at'    => $read ? $at->copy()->addMinutes(20) : null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    protected function seedChat(Company $company, User $owner): void
    {
        $members = array_filter([
            $owner,
            $this->userByName($company, 'Priya Nair'),
            $this->userByName($company, 'Kenji Tan'),
        ]);

        // All chat records are created without model events. The participant observer logs
        // who added each member (ChatLog::participantAdded) from the signed-in user, and a
        // seeder has none — it threw on null and took the whole seed down. Without events the
        // public ids are not generated either, so they are set here, derived from seed ids.
        /** @var ChatChannel $channel */
        $channel = $this->createRecord(ChatChannel::class, [
            'public_id'       => $this->publicId('chat_channel', 'dispatch_team'),
            'company_uuid'    => $company->uuid,
            'created_by_uuid' => $owner->uuid,
            'name'            => 'Dispatch Team',
            'slug'            => 'dispatch-team',
            'meta'            => $this->meta('chat_dispatch_team'),
        ], true);

        // A message's sender is the PARTICIPANT, not the user: chat_messages.sender_uuid is a
        // foreign key to chat_participants.uuid.
        $participants = [];
        foreach (array_values($members) as $index => $member) {
            $participants[$index] = $this->createRecord(ChatParticipant::class, [
                'public_id'         => $this->publicId('chat_participant', 'dispatch_team_' . $index),
                'company_uuid'      => $company->uuid,
                'chat_channel_uuid' => $channel->uuid,
                'user_uuid'         => $member->uuid,
            ], true);
        }

        $messages = [
            [1, 'FLT-2007 is picked up — Amara is on the way to Rochor.'],
            [0, 'Thanks. Can we move FLT-2012 to tomorrow morning? The recipient called back.'],
            [2, 'Done. I\'ve rescheduled it for 09:00 and put it on the east route.'],
            [0, 'Perfect. TRL-01 is coupled to JUR-07 for the linehaul, so the west depot is clear.'],
        ];
        foreach ($messages as $index => [$speaker, $content]) {
            $sender = $participants[$speaker] ?? $participants[0];
            $this->createRecord(ChatMessage::class, [
                'public_id'         => $this->publicId('chat_message', 'dispatch_team_' . $index),
                'company_uuid'      => $company->uuid,
                'chat_channel_uuid' => $channel->uuid,
                'sender_uuid'       => $sender->uuid,
                'content'           => $content,
                'created_at'        => $this->timestamp(9)->addMinutes(5 * $index),
                'updated_at'        => $this->timestamp(9)->addMinutes(5 * $index),
            ], true);
        }
    }

    protected function publicId(string $type, string $seedId): string
    {
        return $type . '_' . substr(hash('sha256', $this->seedName() . ':' . $seedId), 0, 10);
    }

    protected function userByName(Company $company, string $name): ?User
    {
        return User::where('company_uuid', $company->uuid)->where('name', $name)->first();
    }
}
