<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Hash};

class RepairLocalOwner extends Command
{
    protected $signature = 'clinic:repair-local-owner';
    protected $description = 'Restore the local preview owner login from CLINIC_OWNER_PASSWORD';

    public function handle(): int
    {
        if (! app()->environment('local')) { $this->error('This command is limited to local development.'); return self::FAILURE; }
        $password = env('CLINIC_OWNER_PASSWORD');
        if (! $password || strlen($password) < 12) { $this->error('Set a local owner password of at least 12 characters.'); return self::FAILURE; }
        $owner = DB::table('users')->where('email', 'owner@aroma.local')->first();
        if (! $owner) { $this->error('The preview owner account does not exist.'); return self::FAILURE; }
        $matches = Hash::check($password, $owner->password);
        $this->info('Existing local password match: '.($matches ? 'yes' : 'no').'; account active: '.($owner->active ? 'yes' : 'no'));
        DB::table('users')->where('id', $owner->id)->update(['password' => Hash::make($password), 'active' => true, 'updated_at' => now()]);
        DB::table('sessions')->where('user_id', $owner->id)->delete();
        $this->info('Local owner login restored and previous owner sessions invalidated.');
        return self::SUCCESS;
    }
}
