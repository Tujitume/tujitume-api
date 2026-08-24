<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Service\Account\RegisterService;
use Illuminate\Http\Request;

class RegisterController extends Controller
{
    public function __construct(
        private RegisterService $registerService
    ) {}

    public function register(Request $request)
    {
        $request->validate([
            'user_type_id' => ['required', 'integer', 'in:1,2,3,4'],
        ]);

        return match ((int) $request->user_type_id) {
            1 => $this->registerService->registerBusinessOwner($request),
            2 => $this->registerService->registerInvestor($request),
            3 => $this->registerService->registerServiceProvider($request),
            4 => $this->registerService->registerOrganization($request),
            default => response()->json(['error' => 'Invalid user type'], 422),
        };
    }

    public function registerRoleUser(Request $request)
    {
        $request->validate([
            'user_type_id' => ['required', 'integer', 'in:4'],
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'email' => ['required', 'email'],
            'first_name' => ['required', 'string', 'max:255'],
        ]);

        return $this->registerService->registerOrgRoleUser($request);
    }
}
