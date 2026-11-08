<?php

namespace App\Http\Controllers;

use App\Services\{ClinicAccess, OutboxService};
use Illuminate\Http\{JsonResponse, Request};
use App\Services\BranchDB as DB;

class WorkflowController extends Controller
{
    public function campaign(Request $request, int $id, ClinicAccess $access, OutboxService $outbox): JsonResponse
    {
        $access->authorize($request->user(), 'campaigns', 'edit');
        $count = $outbox->prepare($id); $access->audit($request->user(), 'prepared', 'campaigns', $id);
        return response()->json(['message' => $count.' message(s) prepared. Delivery requires provider configuration.']);
    }

    public function convertLead(Request $request, int $id, ClinicAccess $access): JsonResponse
    {
        $access->authorize($request->user(), 'leads', 'edit'); $access->authorize($request->user(), 'clients', 'create');
        $clientId = DB::transaction(function () use ($id): int {
            $lead = DB::table('leads')->lockForUpdate()->find($id); abort_unless($lead, 404);
            if ($lead->client_id) { return $lead->client_id; }
            $clientId = $lead->email ? DB::table('clients')->where('email', $lead->email)->value('id') : null;
            $clientId ??= DB::table('clients')->insertGetId(['name' => $lead->name, 'email' => $lead->email, 'phone' => $lead->phone, 'status' => 'active', 'marketing_consent' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('leads')->where('id', $id)->update(['client_id' => $clientId, 'status' => 'converted', 'updated_at' => now()]); return $clientId;
        });
        $access->audit($request->user(), 'converted', 'leads', $id);
        return response()->json(['id' => $clientId, 'message' => 'Lead linked to client profile.']);
    }
}
