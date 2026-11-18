<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Requests\UpdateEmployeeStatusRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    // 1. List employees
    public function index(): JsonResponse
{
    $users = User::where('role', 'employee')
        ->orderBy('name')
        ->get([
            'id',
            'name',
            'email',
            'role',
            'is_active',
            'created_at'
        ]);

    return response()->json([
        'data' => $users,
    ], 200);
}

    // 2. Create employee
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'employee',
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Employee created successfully',
            'data' => $user,
        ], 201);
    }

    // 3. Update employee
    public function update(
        UpdateEmployeeRequest $request,
        User $user
    ): JsonResponse {
        $this->ensureEmployee($user);

        $user->update($request->validated());

        return response()->json([
            'message' => 'Employee updated successfully',
            'data' => $user,
        ], 200);
    }

    // 4. Activate / Deactivate employee
    public function updateStatus(
        UpdateEmployeeStatusRequest $request,
        User $user
    ): JsonResponse {
        $this->ensureEmployee($user);

        $data = $request->validated();

        DB::transaction(function () use ($user, $data) {
            $user->is_active = $data['is_active'];
            $user->save();

            if (!$user->is_active) {
                DB::table('sessions')
                    ->where('user_id', $user->id)
                    ->delete();
            }
        });

        return response()->json([
            'message' => 'Employee status updated successfully',
            'data' => $user->refresh(),
        ], 200);
    }

    // Prevent modifying admin accounts
    private function ensureEmployee(User $user): void
    {
        abort_unless(
            $user->role === 'employee',
            403,
            'Only employee accounts can be managed.'
        );
    }
}
