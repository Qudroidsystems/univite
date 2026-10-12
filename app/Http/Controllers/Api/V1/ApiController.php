<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Shared bits for the mobile API: every answer is {"data": ...} with optional "meta". */
abstract class ApiController extends Controller
{
    protected function ok(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json($meta ? ['data' => $data, 'meta' => $meta] : ['data' => $data], $status);
    }

    protected function student(Request $request): Student
    {
        return $request->user()->student ?? abort(403, 'This is only for students.');
    }
}
