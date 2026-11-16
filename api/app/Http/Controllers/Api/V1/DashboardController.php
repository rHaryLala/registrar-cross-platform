<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Dashboard\StaffDashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tableau de bord du personnel (lecture seule). Le droit est vérifié dans le domaine. */
class DashboardController extends Controller
{
    public function __construct(private readonly StaffDashboard $dashboard) {}

    public function show(Request $request): JsonResponse
    {
        $d = $request->validate(['year' => ['nullable', 'string', 'max:20', 'regex:/^\d{4} - \d{4}$/']]);

        return response()->json(['data' => $this->dashboard->overview($request->user(), $d['year'] ?? null)])->header('Cache-Control', 'private, no-store');
    }
}
