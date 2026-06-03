<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\DataIntegrityService;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class AdminUserController extends Controller
{
    public function __construct(
        protected DataIntegrityService $dataIntegrity,
    ) {}

    private function guard(Request $request): void
    {
        if (!$request->user() || !$request->user()->isAdmin()) abort(403);
    }

    /** GET /api/admin/users */
    public function index(Request $request)
    {
        $this->guard($request);
        $perPage = min((int) $request->input('per_page', 50), 100);

        $usersQuery = User::with(['roles', 'profile'])
            ->orderByDesc('created_at');

        if ($request->filled('q')) {
            $term = '%' . $request->input('q') . '%';
            $usersQuery->where(function ($q) use ($term) {
                $q->where('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhereHas('profile', function ($p) use ($term) {
                        $p->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term);
                    });
            });
        }

        if ($request->filled('role')) {
            $role = $request->input('role') === 'renter' ? 'rental' : $request->input('role');
            $usersQuery->whereHas('roles', fn ($r) => $r->where('role', $role));
        }

        $users = $usersQuery->paginate($perPage);
        
        $transformedUsers = $users->getCollection()->map(fn($u) => array_merge($u->toArray(), [
            'liveness_passed'   => (bool) $u->liveness_passed,
            'face_match_passed' => (bool) $u->face_match_passed,
            'is_verified'       => (bool) $u->is_verified,
            'ocr_data'          => $u->profile ? [
                'id_number'      => $u->profile->id_number,
                'birth_date'     => $u->profile->birth_date,
                'address'        => $u->profile->address,
                'id_expiry_date' => $u->profile->id_expiry_date,
                'id_issue_date'  => $u->profile->id_issue_date,
                'profession'     => $u->profile->profession,
                'religion'       => $u->profile->religion,
                'marital_status' => $u->profile->marital_status,
            ] : null,
        ]));
        
        return response()->json([
            'data' => $transformedUsers,
            'pagination' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ]
        ]);
    }

    /** POST /api/admin/users */
    public function store(Request $request)
    {
        $this->guard($request);

        $validator = Validator::make($request->all(), [
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|string|min:6',
            'gender'     => 'required|in:male,female',
            'role'       => 'required|in:admin,owner,rental,sponsor',
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'phone'      => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = User::create([
            'email'    => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'gender'   => $request->input('gender'),
            'phone'    => $request->input('phone'),
        ]);

        $role = Role::where('role', $request->input('role'))->first();
        if ($role) $user->roles()->attach($role->id);

        UserProfile::create([
            'user_id'    => $user->id,
            'first_name' => $request->input('first_name'),
            'last_name'  => $request->input('last_name'),
            'age'        => 0,
            'country'    => '',
            'city'       => '',
        ]);

        return response()->json([
            'message' => 'User created.',
            'data'    => $user->load(['roles', 'profile']),
        ], Response::HTTP_CREATED);
    }

    /** PUT /api/admin/users/{id} */
    public function update(Request $request, $id)
    {
        $this->guard($request);

        $user = User::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'email'      => 'email|unique:users,email,' . $user->id,
            'password'   => 'nullable|string|min:6',
            'gender'     => 'in:male,female',
            'role'       => 'in:admin,owner,rental,sponsor',
            'first_name' => 'string|max:100',
            'last_name'  => 'string|max:100',
            'phone'      => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userFields = array_filter([
            'email'  => $request->input('email'),
            'gender' => $request->input('gender'),
            'phone'  => $request->input('phone'),
        ], fn($v) => $v !== null);

        if ($request->filled('password')) {
            $userFields['password'] = Hash::make($request->input('password'));
        }

        if (!empty($userFields)) $user->update($userFields);

        if ($request->filled('role')) {
            $role = Role::where('role', $request->input('role'))->first();
            if ($role) {
                $user->roles()->sync([$role->id]);
            }
        }

        $profileFields = array_filter([
            'first_name' => $request->input('first_name'),
            'last_name'  => $request->input('last_name'),
        ], fn($v) => $v !== null);

        if (!empty($profileFields)) {
            if ($user->profile) {
                $user->profile->update($profileFields);
            } else {
                \App\Models\UserProfile::create(array_merge(['user_id' => $user->id], $profileFields));
            }
        }

        Cache::forget("auth_user_profile_{$user->id}");

        return response()->json([
            'message' => 'User updated.',
            'data'    => $user->load(['roles', 'profile']),
        ]);
    }

    /** POST /api/admin/users/{id}/promote */
    public function promote(Request $request, $id)
    {
        $this->guard($request);
        $user      = User::findOrFail($id);
        $adminRole = Role::where('role', 'admin')->first();
        if (!$adminRole) {
            return response()->json(['message' => 'Admin role not found.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        if (!$user->roles()->where('role_id', $adminRole->id)->exists()) {
            $user->roles()->attach($adminRole->id);
        }
        Cache::forget("auth_user_profile_{$user->id}");
        return response()->json(['message' => 'User promoted to admin.', 'data' => $user->load('roles')]);
    }

    /** POST /api/admin/users/{id}/demote */
    public function demote(Request $request, $id)
    {
        $this->guard($request);
        $user      = User::findOrFail($id);
        $adminRole = Role::where('role', 'admin')->first();
        if ($adminRole) $user->roles()->detach($adminRole->id);
        Cache::forget("auth_user_profile_{$user->id}");
        return response()->json(['message' => 'User demoted from admin.', 'data' => $user->load('roles')]);
    }

    /** DELETE /api/admin/users/{id} */
    public function destroy(Request $request, $id)
    {
        $this->guard($request);
        $user = User::findOrFail($id);
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot delete your own account.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->dataIntegrity->purgeUserRelatedData($user);
        $user->delete(); // soft-delete: sets deleted_at, FK rows remain intact
        Cache::forget("auth_user_profile_{$user->id}");
        return response()->json(['message' => 'User deleted.']);
    }
}
