<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Api\DeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The app registers its push token after sign-in and whenever Firebase gives it a new one. */
class DevicesController extends ApiController
{
    public function __construct(private DeviceRegistry $devices)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['token' => ['required', 'string', 'max:4096'], 'platform' => ['nullable', 'in:android,ios,web'], 'device_name' => ['nullable', 'string', 'max:120'], 'app_version' => ['nullable', 'string', 'max:40']]);
        $access = $request->user()->currentAccessToken();
        $device = $this->devices->register($request->user(), $d['token'], $d['platform'] ?? 'android', $d['device_name'] ?? null, $d['app_version'] ?? null, isset($access->id) ? (int) $access->id : null);

        return $this->ok(['id' => $device->id, 'platform' => $device->platform], [], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $d = $request->validate(['token' => ['required', 'string', 'max:4096']]);

        return $this->ok(['removed' => $this->devices->forget($request->user(), $d['token'])]);
    }
}
