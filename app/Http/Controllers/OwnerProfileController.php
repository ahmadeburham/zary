<?php

namespace App\Http\Controllers;

use App\Models\IdentityDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class OwnerProfileController extends Controller
{
    /**
     * Update the owner's payout details.
     */
    public function updatePayout(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payout_info' => 'nullable|string|max:1000',
            'payout_type' => 'nullable|string|in:wallet,bank',
            'payout_number' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $request->user();
        $data = [];
        if ($request->has('payout_info')) {
            $data['payout_info'] = $request->input('payout_info');
        }
        if ($request->has('payout_type')) {
            $data['payout_type'] = $request->input('payout_type');
        }
        if ($request->has('payout_number')) {
            $data['payout_number'] = $request->input('payout_number');
        }
        
        if (!empty($data)) {
            $user->update($data);
        }

        // Invalidate user profile cache
        Cache::forget("auth_user_profile_{$user->id}");

        return response()->json([
            'message' => 'Payout details updated successfully.',
            'data' => [
                'payout_info' => $user->payout_info,
                'payout_type' => $user->payout_type,
                'payout_number' => $user->payout_number,
            ]
        ]);
    }

    /**
     * Upload user's identity document (National ID or Passport).
     * Enforces the 1:1 identity document per user constraint.
     */
    public function uploadIdentityDocument(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:national_id,passport,other',
            'document_number' => 'required|string|max:100',
            'file' => 'required|file|mimes:pdf,jpeg,png,jpg|max:10240', // 10MB limit
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $request->user();

        // 1:1 Constraint: check and replace existing document
        $existingDoc = IdentityDocument::where('user_id', $user->id)->first();
        if ($existingDoc) {
            // Delete old file
            if (Storage::disk('public')->exists($existingDoc->path)) {
                Storage::disk('public')->delete($existingDoc->path);
            }
            $existingDoc->delete();
        }

        // Store new file
        $filePath = $request->file('file')->store('identity_documents', 'public');

        // Create new document entry (pending by default)
        $document = IdentityDocument::create([
            'user_id' => $user->id,
            'type' => $request->input('type'),
            'document_number' => $request->input('document_number'),
            'path' => $filePath,
            'is_verified' => false,
            'status' => 'pending',
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Identity document uploaded successfully and is pending verification.',
            'data' => $document
        ], Response::HTTP_CREATED);
    }

    /**
     * Bulk upload identity documents.
     * POST /api/identity/documents
     * Accepts documents[0][file], documents[0][document_type], etc.
     */
    public function uploadIdentityDocuments(Request $request)
    {
        $user = $request->user();
        $uploaded = [];

        $files  = $request->file('documents', []);
        $fields = $request->input('documents', []);

        // Delete all existing docs once before storing new ones
        $existingDocs = IdentityDocument::where('user_id', $user->id)->get();
        foreach ($existingDocs as $existing) {
            if (Storage::disk('public')->exists($existing->path)) Storage::disk('public')->delete($existing->path);
            $existing->delete();
        }

        foreach ($files as $i => $fileData) {
            $file = is_array($fileData) ? ($fileData['file'] ?? null) : $fileData;
            if (!$file) continue;

            $docType = $fields[$i]['document_type'] ?? 'national_id';

            $filePath = $file->store('identity_documents', 'public');
            $doc = IdentityDocument::create([
                'user_id'         => $user->id,
                'type'            => $docType,
                'document_number' => 'pending',
                'path'            => $filePath,
                'is_verified'     => false,
                'status'          => 'pending',
            ]);
            $uploaded[] = $doc;
        }

        return response()->json([
            'message' => 'Documents uploaded successfully.',
            'data'    => $uploaded,
        ], Response::HTTP_CREATED);
    }
}
