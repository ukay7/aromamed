<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->foreignId('required_room_id')->nullable()->constrained('resources');
            $table->foreignId('required_device_id')->nullable()->constrained('resources');
        });
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('waitlist_id')->nullable()->unique()->constrained('waitlist');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void { $table->dropConstrainedForeignId('waitlist_id'); });
        Schema::table('services', function (Blueprint $table): void { $table->dropConstrainedForeignId('required_room_id'); $table->dropConstrainedForeignId('required_device_id'); });
    }
};
