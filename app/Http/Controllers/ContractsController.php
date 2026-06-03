<?php

namespace App\Http\Controllers;

use App\Models\TenantContract;
use App\Models\Apartment;
use App\Models\Notification;
use App\Models\PaymentOrder;
use App\Jobs\SendNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Controllers\AdminVerificationController;

class ContractsController extends Controller
{
    /** GET /api/contracts — tenant's own contracts */
    public function tenantIndex(Request $request)
    {
        $user = $request->user();
        $perPage = min($request->input('per_page', 50), 100); // Max 100, default 50
        
        $contracts = TenantContract::where('user_id', $user->id)
            ->with('apartment')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $contracts->each(function ($contract) use ($user) {
            $contract->payment_order = PaymentOrder::where('user_id', $user->id)
                ->where('apartment_id', $contract->apartment_id)
                ->whereIn('status', ['pending', 'unpaid'])
                ->orderByDesc('created_at')
                ->first();
        });

        return response()->json(['data' => $contracts]);
    }

    /** GET /api/contracts/owner — owner sees contracts for their apartments */
    public function ownerIndex(Request $request)
    {
        $apartmentIds = Apartment::where('owner_id', $request->user()->id)->pluck('id');
        $perPage = min($request->input('per_page', 50), 100);
        
        $contracts = TenantContract::whereIn('apartment_id', $apartmentIds)
            ->with(['user.profile', 'apartment'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
        
        return response()->json([
            'data' => $contracts->items(),
            'pagination' => [
                'current_page' => $contracts->currentPage(),
                'per_page' => $contracts->perPage(),
                'total' => $contracts->total(),
                'last_page' => $contracts->lastPage(),
            ]
        ]);
    }

    /** GET /api/admin/contracts — admin sees all contracts */
    public function adminIndex(Request $request)
    {
        if (!$request->user()->isAdmin()) abort(403);
        $perPage = min($request->input('per_page', 50), 100);
        
        $contracts = TenantContract::with(['user.profile', 'apartment.owner'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $contracts->items(),
            'pagination' => [
                'current_page' => $contracts->currentPage(),
                'per_page' => $contracts->perPage(),
                'total' => $contracts->total(),
                'last_page' => $contracts->lastPage(),
            ]
        ]);
    }

    /** GET /api/contracts/{id} — show one contract */
    public function show(Request $request, $id)
    {
        $contract = TenantContract::with(['user.profile', 'apartment'])->findOrFail($id);
        $user = $request->user();
        if (!$user->isAdmin() && $contract->user_id !== $user->id && $contract->apartment->owner_id !== $user->id) {
            abort(403);
        }
        return response()->json(['data' => $contract]);
    }

    /** GET /api/apartments/{apartmentId}/contracts — tenant's contract for one apartment */
    public function showForApartment(Request $request, $apartmentId)
    {
        $contract = TenantContract::where('user_id', $request->user()->id)
            ->where('apartment_id', $apartmentId)
            ->with('apartment')
            ->first();
        if (!$contract) {
            return response()->json(['message' => 'No contract found.'], Response::HTTP_NOT_FOUND);
        }
        return response()->json(['data' => $contract]);
    }

    /** DELETE /api/apartments/{apartmentId}/contracts — tenant deletes own contract */
    public function destroyForApartment(Request $request, $apartmentId)
    {
        $contract = TenantContract::where('user_id', $request->user()->id)
            ->where('apartment_id', $apartmentId)
            ->first();
        if (!$contract) {
            return response()->json(['message' => 'No contract found.'], Response::HTTP_NOT_FOUND);
        }
        if (Storage::disk('public')->exists($contract->path)) Storage::disk('public')->delete($contract->path);
        $contract->delete();
        return response()->json(['message' => 'Contract deleted.']);
    }

    /** DELETE /api/contracts/{id} — delete by id */
    public function destroy(Request $request, $id)
    {
        $contract = TenantContract::findOrFail($id);
        $user = $request->user();
        if (!$user->isAdmin() && $contract->user_id !== $user->id) abort(403);
        if (Storage::disk('public')->exists($contract->path)) Storage::disk('public')->delete($contract->path);
        $contract->delete();
        return response()->json(['message' => 'Contract deleted.']);
    }

    /** PUT /api/contracts/{id} — replace contract file */
    public function update(Request $request, $id)
    {
        $contract = TenantContract::findOrFail($id);
        $user = $request->user();
        if (!$user->isAdmin() && $contract->user_id !== $user->id) abort(403);

        if ($request->hasFile('document')) {
            if (Storage::disk('public')->exists($contract->path)) Storage::disk('public')->delete($contract->path);
            $path = $request->file('document')->store('tenants_contracts', 'public');
            $contract->update([
                'path'   => $path,
                'type'   => $request->input('type', $contract->type),
                'status' => 'pending',
            ]);
        }
        return response()->json(['message' => 'Contract updated.', 'data' => $contract]);
    }

    /** POST /api/contracts/{id}/accept */
    public function accept(Request $request, $id)
    {
        $user     = $request->user();
        $contract = TenantContract::findOrFail($id);
        $apartment = $contract->apartment;
        if (!$user->isAdmin() && $apartment->owner_id !== $user->id) abort(403);

        $contract->update(['status' => 'accepted']);

        Notification::create([
            'user_id'    => $contract->user_id,
            'type'       => 'contract_accepted',
            'dedupe_key' => 'contract_accepted_' . $contract->id,
            'data'       => ['title' => 'Contract Accepted', 'body' => 'Your rental contract has been accepted.', 'contract_id' => $contract->id],
            'status'     => 'pending',
        ]);
        SendNotification::dispatch($contract->user_id, 'Contract Accepted', 'Your rental contract has been accepted.', [], ['fcm']);

        // Trigger payment flow if all contracts for this apartment are now accepted
        app(AdminVerificationController::class)->checkAndTriggerPaymentFlow($contract->apartment_id);

        return response()->json(['message' => 'Contract accepted.', 'data' => $contract]);
    }

    /** POST /api/contracts/{id}/refuse */
    public function refuse(Request $request, $id)
    {
        $validator = Validator::make($request->all(), ['reason' => 'required|string|max:1000']);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user     = $request->user();
        $contract = TenantContract::findOrFail($id);
        $apartment = $contract->apartment;
        if (!$user->isAdmin() && $apartment->owner_id !== $user->id) abort(403);

        $reason = $request->input('reason');
        $contract->update(['status' => 'refused', 'rejection_reason' => $reason]);

        Notification::create([
            'user_id'    => $contract->user_id,
            'type'       => 'contract_refused',
            'dedupe_key' => 'contract_refused_' . $contract->id,
            'data'       => ['title' => 'Contract Refused', 'body' => "Your contract was refused: $reason", 'contract_id' => $contract->id],
            'status'     => 'pending',
        ]);
        SendNotification::dispatch($contract->user_id, 'Contract Refused', "Your contract was refused: $reason", [], ['fcm']);

        return response()->json(['message' => 'Contract refused.', 'data' => $contract]);
    }
}
