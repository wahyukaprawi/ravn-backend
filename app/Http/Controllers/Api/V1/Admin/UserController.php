<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiError;
use App\Models\User;
use App\Services\UserAdministrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(
        private readonly UserAdministrationService $users,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with('roles');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $needle = mb_strtolower(trim((string) $search));

            $query->where(function ($inner) use ($needle) {
                $inner->whereRaw('LOWER(name) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$needle}%"]);
            });
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $page = $query->orderBy('id')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (User $user) => $this->payload($user))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
        ]);

        [$user, $activationToken] = $this->users->invite($request->user(), $validated);

        return response()->json([
            'data' => $this->payload($user),
            'activation_token' => $activationToken,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = User::query()->with('roles')->find($id);

        if ($user === null) {
            return ApiError::notFound('User not found.')->toResponse($request->header('X-Request-Id'));
        }

        return response()->json(['data' => $this->payload($user)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::query()->with('roles')->find($id);

        if ($user === null) {
            return ApiError::notFound('User not found.')->toResponse($request->header('X-Request-Id'));
        }

        $this->normalizeEmail($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
        ]);

        $user = $this->users->update($request->user(), $user, $validated);

        return response()->json(['data' => $this->payload($user)]);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        $user = User::query()->find($id);

        if ($user === null) {
            return ApiError::notFound('User not found.')->toResponse($request->header('X-Request-Id'));
        }

        $user = $this->users->deactivate($request->user(), $user);

        return response()->json(['data' => $this->payload($user)]);
    }

    public function reactivate(Request $request, int $id): JsonResponse
    {
        $user = User::query()->find($id);

        if ($user === null) {
            return ApiError::notFound('User not found.')->toResponse($request->header('X-Request-Id'));
        }

        $user = $this->users->reactivate($request->user(), $user);

        return response()->json(['data' => $this->payload($user)]);
    }

    public function revokeSessions(Request $request, int $id): JsonResponse
    {
        $user = User::query()->find($id);

        if ($user === null) {
            return ApiError::notFound('User not found.')->toResponse($request->header('X-Request-Id'));
        }

        $revoked = $this->users->revokeSessions($request->user(), $user);

        return response()->json(['status' => 'ok', 'revoked' => $revoked]);
    }

    public function reissueInvitation(Request $request, int $id): JsonResponse
    {
        $user = User::query()->find($id);

        if ($user === null) {
            return ApiError::notFound('User not found.')->toResponse($request->header('X-Request-Id'));
        }

        $token = $this->users->reissueInvitation($request->user(), $user);

        return response()->json(['status' => 'ok', 'activation_token' => $token]);
    }

    public function resetMfa(Request $request, int $id): JsonResponse
    {
        $user = User::query()->find($id);

        if ($user === null) {
            return ApiError::notFound('User not found.')->toResponse($request->header('X-Request-Id'));
        }

        $this->users->resetMfa($request->user(), $user);

        return response()->json(['status' => 'ok']);
    }

    public function assignRoles(Request $request, int $id): JsonResponse
    {
        $user = User::query()->with('roles')->find($id);

        if ($user === null) {
            return ApiError::notFound('User not found.')->toResponse($request->header('X-Request-Id'));
        }

        $validated = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        $user = $this->users->assignRoles($request->user(), $user, $validated['roles']);

        return response()->json(['data' => $this->payload($user)]);
    }

    protected function normalizeEmail(Request $request): void
    {
        $email = $request->input('email');

        if (! is_string($email)) {
            return;
        }

        $request->merge([
            'email' => mb_strtolower(trim($email)),
        ]);
    }

    protected function payload(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'roles' => $user->getRoleNames()->values()->all(),
            'activated_at' => $user->activated_at?->toIso8601String(),
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
