<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InitializeOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_creates_a_working_owner_without_sample_records_and_cannot_reset_it(): void
    {
        $this->artisan('clinic:initialize-owner')
            ->expectsQuestion('Owner name', 'Clinic Administrator')
            ->expectsQuestion('Owner email', 'admin@example.test')
            ->expectsQuestion('Owner password (at least 12 characters, mixed case, number and symbol)', 'Test-Only-Owner-2026!')
            ->expectsQuestion('Confirm owner password', 'Test-Only-Owner-2026!')
            ->assertSuccessful();

        $owner = DB::table('users')->where('email', 'admin@example.test')->first();
        $this->assertTrue(Hash::check('Test-Only-Owner-2026!', $owner->password));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $branchId = DB::table('branch_user')->where('user_id', $owner->id)->value('branch_id');
        $this->assertDatabaseHas('payment_methods', ['branch_id' => $branchId, 'name' => 'Cash']);
        $this->assertGreaterThan(0, DB::table('permission_role')->count());

        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'Test-Only-Owner-2026!'])->assertRedirect('/admin');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.is_org_owner', true);
        $this->getJson('/api/dashboard')->assertOk();

        $this->artisan('clinic:initialize-owner')->assertFailed();
        $this->assertSame($owner->password, DB::table('users')->where('id', $owner->id)->value('password'));
    }

    public function test_invalid_credentials_do_not_partially_initialize_the_clinic(): void
    {
        $this->artisan('clinic:initialize-owner')
            ->expectsQuestion('Owner name', 'Clinic Administrator')
            ->expectsQuestion('Owner email', 'invalid-email')
            ->expectsQuestion('Owner password (at least 12 characters, mixed case, number and symbol)', 'short')
            ->expectsQuestion('Confirm owner password', 'different')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('roles', 0);
    }
}
