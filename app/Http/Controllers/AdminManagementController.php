<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\Lease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminManagementController extends Controller
{
    // ==================== LEASES ====================

    public function getLeases(Request $request): JsonResponse
    {
        $query = Lease::with(['apartment', 'owner', 'tenant']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter expiring soon
        if ($request->has('expiring_soon')) {
            $days = $request->expiring_soon;
            $query->where('status', 'active')
                ->where('end_date', '<=', now()->addDays($days))
                ->where('end_date', '>=', now());
        }

        // Filter overdue
        if ($request->has('overdue')) {
            $query->where('status', 'active')
                ->where('end_date', '<', now());
        }

        $leases = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return $this->successResponse($leases);
    }

    public function getLease($id): JsonResponse
    {
        $lease = Lease::with(['apartment', 'owner', 'tenant', 'payments'])
            ->findOrFail($id);

        return $this->successResponse($lease);
    }

    public function updateLease(Request $request, $id): JsonResponse
    {
        $lease = Lease::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:draft,pending_signatures,active,expiring_soon,expired,terminated,renewed',
            'terms' => 'nullable|string',
            'special_conditions' => 'nullable|array',
            'auto_renew' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $lease->update($request->only(['status', 'terms', 'special_conditions', 'auto_renew']));

        return $this->successResponse($lease, 'Lease updated successfully');
    }

    // ==================== SETTINGS ====================

    public function getSettings(Request $request): JsonResponse
    {
        $group = $request->get('group');

        if ($group) {
            $settings = AdminSetting::byGroup($group)->editable()->get();
        } else {
            $settings = AdminSetting::editable()
                ->get()
                ->groupBy('group');
        }

        return $this->successResponse($settings);
    }

    public function getAllSettings(): JsonResponse
    {
        return $this->successResponse(AdminSetting::allSettings());
    }

    public function updateSetting(Request $request, $key): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'value' => 'required',
            'type' => 'nullable|in:string,integer,boolean,json,array',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $type = $request->type ?? 'string';
        $success = AdminSetting::set($key, $request->value, $type);

        if (!$success) {
            return $this->errorResponse('Setting not found or not editable', 404);
        }

        return $this->successResponse(null, 'Setting updated successfully');
    }

    public function bulkUpdateSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'required',
            'settings.*.type' => 'nullable|in:string,integer,boolean,json,array',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $results = [];
        foreach ($request->settings as $setting) {
            $type = $setting['type'] ?? 'string';
            $results[$setting['key']] = AdminSetting::set($setting['key'], $setting['value'], $type);
        }

        return $this->successResponse($results, 'Settings updated');
    }
}
