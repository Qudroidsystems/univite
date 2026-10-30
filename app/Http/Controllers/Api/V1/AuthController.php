<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\ApiUser;
use App\Data\Api\LoginRequestData;
use App\Services\Api\DeviceRegistry;
use App\Services\Api\MobileAuth;
use App\Support\Appearance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(private MobileAuth $auth, private DeviceRegistry $devices, private Appearance $appearance)
    {
    }

    /** POST /api/v1/auth/login {login, password, device_name?, push_token?, platform?, app_version?} */
    public function login(Request $request): JsonResponse
    {
        $data = LoginRequestData::fromArray($request->validate([
            'login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:120'], 'push_token' => ['nullable', 'string', 'max:4096'],
            'platform' => ['nullable', 'in:android,ios,web'], 'app_version' => ['nullable', 'string', 'max:40'],
        ]));
        $token = $this->auth->login($data);

        return $this->ok(['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'user' => ApiUser::from($token->accessToken->tokenable)->toArray() + ['appearance' => $this->appearance->forApp($token->accessToken->tokenable)]], [], 201);
    }

    /** POST /api/v1/auth/logout: ends this sign-in only; the phone stops getting pushes for it. */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $this->devices->forgetForAccessToken($request->user(), (int) $token->id);
            $token->delete();
        }

        return $this->ok(['signed_out' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(ApiUser::from($request->user())->toArray() + ['appearance' => $this->appearance->forApp($request->user())]);
    }
}
