<?php

namespace App\Http\Controllers;

use App\Models\ManualVerificationRequest;
use App\Models\Notification;
use App\Jobs\SendNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ManualVerificationController extends Controller
{
    protected function notifyUser($userId, string $title, string $body, array $data = [])
    {
        Notification::create([
            'user_id'    => $userId,
            'type'       => $data['type'] ?? 'verification_update',
            'dedupe_key' => 'manual_verify_' . uniqid(),
            'data'       => array_merge(['title' => $title, 'body' => $body], $data),
            'status'     => 'pending',
        ]);
        SendNotification::dispatch($userId, $title, $body, $data, ['fcm']);
    }

    /** POST /api/identity/manual-verification-request */
    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->is_verified) {
            return response()->json(['message' => 'You are already verified.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = ManualVerificationRequest::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'A manual verification request is already pending or approved.',
                'data'    => $existing,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $req = ManualVerificationRequest::updateOrCreate(
            ['user_id' => $user->id],
            ['status' => 'pending', 'admin_note' => null]
        );

        return response()->json(['message' => 'Manual verification request submitted.', 'data' => $req], Response::HTTP_CREATED);
    }

    /** GET /api/admin/manual-verification-requests */
    public function index(Request $request)
    {
        if (!$request->user()->isAdmin()) abort(403);

        $requests = ManualVerificationRequest::with(['user.profile'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 1 WHEN 'rejected' THEN 2 WHEN 'approved' THEN 3 ELSE 4 END")
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $requests]);
    }

    /** POST /api/admin/manual-verification-requests/{id}/approve */
    public function approve(Request $request, $id)
    {
        if (!$request->user()->isAdmin()) abort(403);

        $req = ManualVerificationRequest::findOrFail($id);
        $req->update(['status' => 'approved', 'admin_note' => null]);

        $user = $req->user;
        $user->update(['is_verified' => true, 'liveness_passed' => true, 'face_match_passed' => true]);
        Cache::forget("auth_user_profile_{$user->id}");

        $this->notifyUser($user->id, 'Identity Verified', 'Your identity has been manually verified by an admin.', [
            'type' => 'identity_verified', 'status' => 'approved',
        ]);

        return response()->json(['message' => 'User manually verified.', 'data' => $req]);
    }

    /** POST /api/admin/manual-verification-requests/{id}/reject */
    public function reject(Request $request, $id)
    {
        if (!$request->user()->isAdmin()) abort(403);

        $note = $request->input('note', '');
        $req  = ManualVerificationRequest::findOrFail($id);
        $req->update(['status' => 'rejected', 'admin_note' => $note]);

        $this->notifyUser($req->user_id, 'Manual Verification Rejected',
            'Your manual verification request was rejected.' . ($note ? " Reason: $note" : ''), [
                'type' => 'identity_rejected', 'status' => 'rejected', 'note' => $note,
            ]);

        return response()->json(['message' => 'Request rejected.', 'data' => $req]);
    }
}
