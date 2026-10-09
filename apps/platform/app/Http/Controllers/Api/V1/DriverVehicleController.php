<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DriverVehicleRequest;
use App\Models\User;
use App\Modules\Assets\Application\DriverVehicles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DriverVehicleController extends Controller
{
    public function __construct(private readonly DriverVehicles $vehicles) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->vehicles->list($this->subject($request))])->header('Cache-Control', 'no-store');
    }

    public function store(DriverVehicleRequest $request, string $vehicle): JsonResponse
    {
        return response()->json(['data' => $this->vehicles->save($this->subject($request), $vehicle, $request->validated())])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $vehicle): JsonResponse
    {
        $this->vehicles->remove($this->subject($request), $vehicle);

        return response()->json(['data' => ['removed' => true]]);
    }

    private function subject(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return (string) $user->public_id;
    }
}
