<?php

namespace App\Http\Controllers;

use App\Services\{BranchContext, BranchProvisioner};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    private function owner(Request $request): int
    {
        abort_unless($request->user()->is_org_owner,403,'Organization owner access is required.');
        return (int)$request->user()->organization_id;
    }

    public function index(Request $request): JsonResponse
    {
        $org=$this->owner($request);
        $branches=DB::table('branches')->where('organization_id',$org)->orderBy('id')->get()->map(function ($branch) {
            $branch->users=DB::table('branch_user')->where('branch_id',$branch->id)->where('active',true)->count();
            $branch->clients=DB::table('clients')->where('branch_id',$branch->id)->count();
            $branch->appointments=DB::table('appointments')->where('branch_id',$branch->id)->count();
            return $branch;
        });
        $memberships=DB::table('branch_user')->join('branches','branches.id','=','branch_user.branch_id')->join('users','users.id','=','branch_user.user_id')->join('roles','roles.id','=','branch_user.role_id')->where('branches.organization_id',$org)->where('users.organization_id',$org)->select('branch_user.*','users.name as user','users.email','branches.name as branch','roles.name as role')->get();
        return response()->json(['organization'=>DB::table('organizations')->find($org),'branches'=>$branches,'users'=>DB::table('users')->where('organization_id',$org)->get(['id','name','email','is_org_owner']),'memberships'=>$memberships]);
    }

    public function save(Request $request, BranchProvisioner $provisioner, ?int $id = null): JsonResponse
    {
        $org=$this->owner($request);
        if ($id) { abort_unless(DB::table('branches')->where('organization_id',$org)->where('id',$id)->exists(),404); }
        $data=$request->validate(['name'=>['required','string','max:100',Rule::unique('branches')->where('organization_id',$org)->ignore($id)],'slug'=>['required','alpha_dash','max:100',Rule::unique('branches')->ignore($id)],'location'=>'required|string|max:150','currency'=>'required|in:CAD,USD,GBP,PKR,AED,EUR','timezone'=>'required|timezone','active'=>'boolean']);
        $id=DB::transaction(function () use ($id,$org,$data,$provisioner): int {
            DB::table('organizations')->where('id',$org)->lockForUpdate()->first();
            if (! $id) { return $provisioner->create($org,$data); }
            if (isset($data['active']) && ! $data['active'] && ! DB::table('branches')->where('organization_id',$org)->where('id','!=',$id)->where('active',true)->exists()) { abort(422,'Keep at least one active branch.'); }
            $existing=DB::table('branches')->find($id);
            if ($existing->currency !== $data['currency'] && DB::table('invoices')->where('branch_id',$id)->exists()) { abort(422,'The currency cannot change after invoices exist. Create a separate branch for a different currency.'); }
            DB::table('branches')->where('id',$id)->update($data+['updated_at'=>now()]);
            foreach (['location','currency','timezone'] as $key) { DB::table('settings')->updateOrInsert(['branch_id'=>$id,'key'=>$key],['value'=>$data[$key]]); }
            return $id;
        });
        return response()->json(['id'=>$id,'message'=>'Branch saved. Its records and permissions are independent.']);
    }

    public function rename(Request $request): JsonResponse
    {
        $org=$this->owner($request); $data=$request->validate(['name'=>'required|string|max:100']);
        DB::table('organizations')->where('id',$org)->update($data+['updated_at'=>now()]);
        return response()->json(['message'=>'Organization name updated.']);
    }

    public function select(Request $request, BranchContext $context): JsonResponse
    {
        $data=$request->validate(['branch_id'=>'required']); $selection=$data['branch_id'];
        if ($selection === 'all') { abort_unless($request->user()->is_org_owner,403); }
        else { abort_unless($context->accessible($request->user())->contains('id',(int)$selection),403,'You do not have access to this branch.'); $selection=(int)$selection; }
        $request->session()->put('branch_id',$selection);
        return response()->json(['message'=>'Workspace switched.']);
    }

    public function roles(Request $request, int $id): JsonResponse
    {
        $org=$this->owner($request); abort_unless(DB::table('branches')->where('organization_id',$org)->where('id',$id)->exists(),404);
        return response()->json(DB::table('roles')->where('branch_id',$id)->get(['id','name']));
    }

    public function membership(Request $request): JsonResponse
    {
        $org=$this->owner($request);
        $data=$request->validate(['branch_id'=>['required','integer',Rule::exists('branches','id')->where('organization_id',$org)],'user_id'=>['required','integer',Rule::exists('users','id')->where('organization_id',$org)],'role_id'=>['required','integer',Rule::exists('roles','id')->where('branch_id',$request->integer('branch_id'))],'active'=>'required|boolean']);
        abort_if(DB::table('users')->where('id',$data['user_id'])->value('is_org_owner'),422,'Organization owners already have access to every branch.');
        DB::table('branch_user')->updateOrInsert(['branch_id'=>$data['branch_id'],'user_id'=>$data['user_id']],['role_id'=>$data['role_id'],'active'=>$data['active'],'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Branch membership saved.']);
    }
}
