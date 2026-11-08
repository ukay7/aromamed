<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BranchProvisioner;
use Database\Seeders\ClinicSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BranchThemeTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed(ClinicSeeder::class);
        $this->actingAs(User::where('is_org_owner',true)->firstOrFail());
    }

    public function test_branch_palettes_persist_and_switch_without_affecting_combined_view(): void
    {
        $data=$this->getJson('/api/settings')->assertOk()->json();
        $this->putJson('/api/settings',$data+['theme_key'=>'rose'])->assertOk();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('theme.key','rose')->assertJsonPath('theme.primary','#963d67');
        $this->get('/book/aroma-north-york')->assertOk()->assertSee('--sage:#963d67',false);
        $branch=app(BranchProvisioner::class)->create(1,['name'=>'Theme branch','slug'=>'theme-branch','location'=>'Test','currency'=>'CAD','timezone'=>'America/Toronto']);
        $this->postJson('/api/branch-selection',['branch_id'=>$branch])->assertOk();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('theme.key','sage');
        $data=$this->getJson('/api/settings')->assertOk()->json();
        $this->putJson('/api/settings',$data+['theme_key'=>'ocean'])->assertOk();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('theme.key','ocean');
        $this->postJson('/api/branch-selection',['branch_id'=>1])->assertOk();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('theme.key','rose');
        $this->postJson('/api/branch-selection',['branch_id'=>'all'])->assertOk();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('theme.key','sage');
        $this->putJson('/api/settings',$data+['theme_key'=>'amber'])->assertStatus(409);
    }

    public function test_custom_colors_are_validated_and_read_only_users_cannot_change_them(): void
    {
        $data=$this->getJson('/api/settings')->assertOk()->json();
        $custom=['theme_key'=>'custom','theme_primary'=>'#205080','theme_sidebar'=>'#203040','theme_background'=>'#f5f6fa'];
        $this->putJson('/api/settings',$data+$custom)->assertOk();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('theme.primary','#205080');
        $this->putJson('/api/settings',$data+array_replace($custom,['theme_primary'=>'#ffffff']))->assertUnprocessable();
        $this->putJson('/api/settings',$data+array_replace($custom,['theme_background'=>'#000000']))->assertUnprocessable();
        $this->putJson('/api/settings',$data+array_replace($custom,['theme_sidebar'=>'red;display:none']))->assertUnprocessable();
        $this->putJson('/api/settings',$data+['theme_key'=>'invalid'])->assertUnprocessable();
        $role=DB::table('roles')->insertGetId(['branch_id'=>1,'name'=>'Theme viewer','is_owner'=>false]);
        DB::table('permission_role')->insert(['role_id'=>$role,'permission_id'=>DB::table('permissions')->where('name','settings.view')->value('id')]);
        $user=User::factory()->create(['organization_id'=>1,'role_id'=>$role]);
        DB::table('branch_user')->insert(['branch_id'=>1,'user_id'=>$user->id,'role_id'=>$role,'active'=>true]);
        $this->actingAs($user)->getJson('/api/settings')->assertOk();
        $this->putJson('/api/settings',$data+['theme_key'=>'sage'])->assertForbidden();
    }
}
