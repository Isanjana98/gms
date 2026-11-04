<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** SRS 3 - Staff accounts and role assignment. */
class UserController extends Controller
{
    /** GET /api/users */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::USERS_MANAGE), 403);

        $users = User::query()
            ->visibleTo($request->user())
            ->when($request->filled('search'), fn ($q) => $q->where(function ($w) use ($request) {
                $term = '%'.$request->string('search').'%';
                $w->where('name', 'like', $term)->orWhere('email', 'like', $term);
            }))
            ->when($request->filled('role'), fn ($q) => $q->role($request->string('role')->toString()))
            ->with(['roles:id,name', 'branch:id,name,code'])
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return response()->json($users->through(fn (User $user) => $this->payload($user)));
    }

    /** POST /api/users */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::USERS_MANAGE), 403);

        $data = $request->validate($this->rules());

        $this->assertCanAssignRole($request, $data['role']);

        $user = User::create([
            'branch_id' => $data['branch_id'] ?? null,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $user->syncRoles([$data['role']]);

        return response()->json([
            'message' => "{$user->name} can now sign in as {$data['role']}.",
            'data' => $this->payload($user->load('roles:id,name')),
        ], 201);
    }

    /** GET /api/users/{user} */
    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::USERS_MANAGE), 403);

        return response()->json(['data' => $this->payload($user->load(['roles:id,name', 'branch:id,name,code']))]);
    }

    /** PUT /api/users/{user} */
    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::USERS_MANAGE), 403);

        $data = $request->validate($this->rules($user->id, updating: true));

        if (isset($data['role'])) {
            $this->assertCanAssignRole($request, $data['role']);

            // Removing the last super administrator would lock everyone out of
            // user management permanently.
            $this->assertNotLastSuperAdmin($user, $data['role']);

            $user->syncRoles([$data['role']]);
        }

        // Deactivating must take effect now, not whenever the token expires.
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $user->tokens()->delete();
        }

        $user->update(collect($data)->except('role')->all());

        return response()->json([
            'message' => 'User updated.',
            'data' => $this->payload($user->fresh()->load('roles:id,name')),
        ]);
    }

    /** DELETE /api/users/{user} */
    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::USERS_MANAGE), 403);

        abort_if(
            $user->id === $request->user()->id,
            422,
            'You cannot delete your own account.',
        );

        $this->assertNotLastSuperAdmin($user, null);

        $user->tokens()->delete();
        $user->delete();

        return $this->ok("{$user->name} has been removed.");
    }

    /** GET /api/roles - roles and the permissions behind them. */
    public function roles(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::USERS_MANAGE), 403);

        return response()->json([
            'roles' => Role::with('permissions:id,name')->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => $role->users()->count(),
            ]),
            'permissions' => Permission::orderBy('name')->pluck('name'),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'branch_id' => $user->branch_id,
            'branch' => $user->relationLoaded('branch') && $user->branch
                ? ['id' => $user->branch->id, 'name' => $user->branch->name]
                : null,
            'is_active' => $user->is_active,
            'roles' => $user->getRoleNames(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * Only a super administrator may create another one - otherwise a gym
     * administrator could promote themselves past their own ceiling.
     */
    private function assertCanAssignRole(Request $request, string $role): void
    {
        abort_if(
            $role === Permissions::ROLE_SUPER_ADMIN && ! $request->user()->hasRole(Permissions::ROLE_SUPER_ADMIN),
            403,
            'Only a Super Administrator can grant that role.',
        );
    }

    private function assertNotLastSuperAdmin(User $user, ?string $newRole): void
    {
        if (! $user->hasRole(Permissions::ROLE_SUPER_ADMIN)) {
            return;
        }

        if ($newRole === Permissions::ROLE_SUPER_ADMIN) {
            return;
        }

        $remaining = User::role(Permissions::ROLE_SUPER_ADMIN)->where('id', '!=', $user->id)->count();

        abort_if(
            $remaining === 0,
            422,
            'This is the only Super Administrator. Grant the role to someone else first.',
        );
    }

    /** @return array<string, mixed> */
    private function rules(?int $ignoreId = null, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => [$required, 'string', 'max:255'],
            'email' => [$required, 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignoreId)->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => [$updating ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => [$required, 'string', Rule::exists('roles', 'name')],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
