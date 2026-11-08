<?php

namespace App\Services;

use App\Services\BranchDB as DB;

class OutboxService
{
    public function prepare(int $id): int
    {
        return DB::transaction(function () use ($id): int {
            $campaign = DB::table('campaigns')->lockForUpdate()->find($id); abort_unless($campaign, 404);
            if ($campaign->scheduled_at && now()->lt($campaign->scheduled_at)) { DB::table('campaigns')->where('id', $id)->update(['status' => 'scheduled']); return 0; }
            $clients = DB::table('clients')->where('status', 'active')->where('marketing_consent', true);
            if ($campaign->audience === 'birthday_month') { $clients->whereMonth('birth_date', now()->month); }
            if ($campaign->audience === 'active_members') { $clients->whereIn('id', DB::table('memberships')->where('status', 'active')->whereDate('starts_on', '<=', today())->whereDate('expires_on', '>=', today())->select('client_id')); }
            $clients->whereNotNull($campaign->channel === 'email' ? 'email' : 'phone'); $count = 0;
            foreach ($clients->get() as $client) {
                $count += DB::table('message_outbox')->insertOrIgnore(['client_id' => $client->id, 'campaign_id' => $id, 'channel' => $campaign->channel, 'subject' => $campaign->subject, 'body' => str_replace('{name}', $client->name, $campaign->body), 'status' => 'pending_configuration', 'dedup_key' => 'campaign-'.$id.'-'.$client->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('campaigns')->where('id', $id)->update(['status' => 'prepared']); return $count;
        });
    }
}
