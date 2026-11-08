<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OrganizationInsights
{
    public function overview(?string $from = null, ?string $to = null): array
    {
        $context=app(BranchContext::class);
        $branches=DB::table('branches')->where('organization_id',$context->organizationId)->whereIn('id',$context->branchIds)->get();
        $rows=[]; $currencies=[];
        foreach ($branches as $branch) {
            $day=CarbonImmutable::now($branch->timezone)->toDateString();
            $start=($from ?? $day).' 00:00:00'; $end=($to ?? $day).' 23:59:59';
            $appointments=DB::table('appointments')->where('branch_id',$branch->id)->whereBetween('starts_at',[$start,$end]);
            $row=['branch_id'=>$branch->id,'branch'=>$branch->name,'currency'=>$branch->currency,'timezone'=>$branch->timezone,
                'appointments'=>(clone $appointments)->whereNotIn('status',['cancelled','no_show'])->count(),
                'completed'=>(clone $appointments)->where('status','completed')->count(),
                'clients'=>DB::table('clients')->where('branch_id',$branch->id)->where('status','active')->count(),
                'invoiced_cents'=>(int)DB::table('invoices')->where('branch_id',$branch->id)->whereBetween('created_at',[$start,$end])->sum('total_cents'),
                'collected_cents'=>(int)DB::table('payments')->where('branch_id',$branch->id)->whereBetween('created_at',[$start,$end])->sum('amount_cents'),
                'outstanding_cents'=>(int)DB::table('invoices')->where('branch_id',$branch->id)->sum('total_cents')-(int)DB::table('payments')->where('branch_id',$branch->id)->sum('amount_cents'),
            ];
            $rows[]=$row;
            $currencies[$branch->currency] ??= ['currency'=>$branch->currency,'invoiced_cents'=>0,'collected_cents'=>0,'outstanding_cents'=>0];
            foreach (['invoiced_cents','collected_cents','outstanding_cents'] as $metric) { $currencies[$branch->currency][$metric]+=$row[$metric]; }
        }
        return ['branches'=>$rows,'currency_totals'=>array_values($currencies),'mixed_currencies'=>count($currencies)>1];
    }

    public function dashboard(): array
    {
        $summary=$this->overview(); $branches=$summary['branches'];
        $stats=['appointments'=>array_sum(array_column($branches,'appointments')),'completed'=>array_sum(array_column($branches,'completed')),'clients'=>array_sum(array_column($branches,'clients'))];
        if (! $summary['mixed_currencies'] && $summary['currency_totals']) { $stats+=array_intersect_key($summary['currency_totals'][0],array_flip(['collected_cents','outstanding_cents'])); }
        return $summary+['aggregate'=>true,'date'=>today()->toDateString(),'stats'=>$stats,'appointments'=>[],'week'=>[],'tasks'=>[],'services'=>[],'low_stock'=>[]];
    }

    public function reports(string $from, string $to): array
    {
        $summary=$this->overview($from,$to);
        $result=$summary+['aggregate'=>true,'from'=>$from,'to'=>$to];
        if (! $summary['mixed_currencies'] && $summary['currency_totals']) { $result+=array_intersect_key($summary['currency_totals'][0],array_flip(['invoiced_cents','collected_cents','outstanding_cents'])); }
        $ids=app(BranchContext::class)->branchIds;
        $result['statuses']=DB::table('appointments')->whereIn('branch_id',$ids)->whereBetween('starts_at',[$from.' 00:00:00',$to.' 23:59:59'])->selectRaw('status,COUNT(*) as count')->groupBy('status')->get();
        $result['practitioners']=DB::table('appointments')->join('staff','staff.id','=','appointments.staff_id')->join('branches','branches.id','=','appointments.branch_id')->whereIn('appointments.branch_id',$ids)->whereBetween('appointments.starts_at',[$from.' 00:00:00',$to.' 23:59:59'])->selectRaw('branches.name as branch,staff.name,COUNT(*) as appointments,SUM(CASE WHEN appointments.status = \'completed\' THEN 1 ELSE 0 END) as completed')->groupBy('branches.id','branches.name','staff.id','staff.name')->get();
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $result['services'] = DB::table('appointments')->join('services', 'services.id', '=', 'appointments.service_id')->join('branches', 'branches.id', '=', 'appointments.branch_id')->whereIn('appointments.branch_id', $ids)->whereBetween('appointments.starts_at', $range)->whereNotIn('appointments.status', ['cancelled', 'no_show'])->selectRaw('branches.name as branch, services.name, COUNT(*) as appointments')->groupBy('branches.id', 'branches.name', 'services.id', 'services.name')->get();
        $result['payment_methods'] = DB::table('payments')->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')->join('branches', 'branches.id', '=', 'payments.branch_id')->whereIn('payments.branch_id', $ids)->whereBetween('payments.created_at', $range)->selectRaw('branches.name as branch, branches.currency, payment_methods.name, SUM(payments.amount_cents) as amount_cents')->groupBy('branches.id', 'branches.name', 'branches.currency', 'payment_methods.id', 'payment_methods.name')->get();
        $result['stock'] = DB::table('products')->join('branches', 'branches.id', '=', 'products.branch_id')->whereIn('products.branch_id', $ids)->where('products.status', 'active')->get(['branches.name as branch', 'products.name', 'products.sku', 'products.stock', 'products.reorder_level']);
        $result['package_redemptions'] = DB::table('package_redemptions')->whereIn('branch_id', $ids)->whereBetween('created_at', $range)->count();
        return $result;
    }
}
