<?php

namespace App\Console\Commands;

use App\Services\{BranchContext, BranchDB, OutboxService};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareClinicMessages extends Command
{
    protected $signature='clinic:prepare-messages';
    protected $description='Prepare due campaigns and reminders separately for each active branch';

    public function handle(): int
    {
        $context=app(BranchContext::class); $count=0;
        try {
            foreach (DB::table('branches')->where('active',true)->get() as $branch) {
                $context->setBranch($branch); config(['app.timezone'=>$branch->timezone]); date_default_timezone_set($branch->timezone);
                foreach (BranchDB::table('campaigns')->where('status','scheduled')->where('scheduled_at','<=',now())->get() as $campaign) { $count+=app(OutboxService::class)->prepare($campaign->id); }
                $hours=(int)BranchDB::table('settings')->where('key','reminder_hours')->value('value');
                foreach (BranchDB::table('appointments')->where('status','confirmed')->whereBetween('starts_at',[now(),now()->addHours($hours)])->get() as $appointment) {
                    $count+=BranchDB::table('message_outbox')->insertOrIgnore(['client_id'=>$appointment->client_id,'appointment_id'=>$appointment->id,'channel'=>'email','subject'=>'Your upcoming appointment','body'=>'Your visit is scheduled for '.$appointment->starts_at.'. Please contact the clinic for changes.','status'=>'pending_configuration','dedup_key'=>'reminder-'.$appointment->id.'-'.$appointment->starts_at,'created_at'=>now(),'updated_at'=>now()]);
                }
            }
        } finally { $context->clear(); }
        $this->info($count.' messages prepared. No external provider is connected.'); return self::SUCCESS;
    }
}
