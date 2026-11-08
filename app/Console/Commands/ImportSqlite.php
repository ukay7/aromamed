<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class ImportSqlite extends Command
{
    protected $signature = 'clinic:import-sqlite {path}';
    protected $description = 'Copy legacy clinic data into an empty, migrated MySQL database';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') { $this->error('The destination must be MySQL.'); return self::FAILURE; }
        if (DB::table('users')->exists() || DB::table('roles')->exists()) { $this->error('Refusing to overwrite populated MySQL tables.'); return self::FAILURE; }
        $path = realpath($this->argument('path'));
        if (! $path) { $this->error('Source database does not exist.'); return self::FAILURE; }
        $source = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = ['users','roles','permissions','permission_role','menu_items','settings','clients','service_categories','services','staff','service_staff','resources','packages','client_packages','appointments','package_redemptions','blocked_times','waitlist','treatment_records','record_amendments','form_templates','consents','photos','suppliers','products','stock_movements','invoices','invoice_lines','payment_methods','payments','membership_plans','memberships','reward_entries','tasks','leads','campaigns','message_outbox','website_pages','audit_logs'];
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::transaction(function () use ($source, $tables): void {
                foreach ($tables as $table) {
                    $rows = $source->query('SELECT * FROM "'.$table.'"')->fetchAll(PDO::FETCH_ASSOC);
                    foreach (array_chunk($rows, 200) as $batch) { DB::table($table)->insert($batch); }
                    $this->line($table.': '.count($rows).' records copied');
                }
            });
        } finally { DB::statement('SET FOREIGN_KEY_CHECKS=1'); }
        $this->info('Clinic data copied. The source SQLite database was not modified.');
        return self::SUCCESS;
    }
}
