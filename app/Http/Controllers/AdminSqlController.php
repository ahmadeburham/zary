<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdminSqlController extends Controller
{
    /** POST /api/admin/query — run a read-only SELECT query */
    public function query(Request $request)
    {
        if (!$request->user()->isAdmin()) abort(403);

        $sql = trim($request->input('sql', ''));

        if (empty($sql)) {
            return response()->json(['message' => 'SQL query is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Reject any non-SELECT statements (strip leading comments/whitespace)
        $normalized = preg_replace('/\/\*.*?\*\//s', '', $sql);   // remove block comments
        $normalized = preg_replace('/--[^\n]*/', '', $normalized); // remove line comments
        $normalized = trim($normalized);

        if (!preg_match('/^SELECT\s/i', $normalized)) {
            return response()->json([
                'message' => 'Only SELECT statements are permitted.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Additional safety: reject statements with semicolons followed by more content (stacked queries)
        if (substr_count($sql, ';') > 1 || (str_contains($sql, ';') && trim(substr($sql, strrpos($sql, ';') + 1)) !== '')) {
            return response()->json(['message' => 'Stacked queries are not permitted.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $rows = DB::select(DB::raw($sql));
            $data = array_map(fn($row) => (array) $row, $rows);

            return response()->json([
                'data'  => $data,
                'count' => count($data),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Query error: ' . $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
