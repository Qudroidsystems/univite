<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Appearance\AppearancePreferences;
use App\Http\Controllers\Appearance\MyAppearanceController;
use App\Support\Appearance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in person's font, text size and look; the same settings as "Display & reading" on the portal. */
class AppearanceController extends ApiController
{
    public function __construct(private Appearance $appearance)
    {
    }

    /** GET /api/v1/me/appearance: what applies now, what the person saved (null = school default), and the choices. */
    public function show(Request $request): JsonResponse
    {
        return $this->ok($this->payload($request));
    }

    /** PUT /api/v1/me/appearance {font?, size?, theme?, menu?, density?, line_spacing?, reduce_motion?}; null or missing = school default. */
    public function update(Request $request): JsonResponse
    {
        $this->appearance->save($request->user(), AppearancePreferences::fromArray($request->validate(MyAppearanceController::rules())));

        return $this->ok($this->payload($request));
    }

    private function payload(Request $request): array
    {
        return [
            'current' => $this->appearance->forApp($request->user()), 'saved' => $this->appearance->saved($request->user())->toArray(),
            'school' => $this->appearance->forApp(null), 'options' => $this->appearance->options(),
        ];
    }
}
