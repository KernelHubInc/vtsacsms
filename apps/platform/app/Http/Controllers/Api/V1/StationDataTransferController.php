<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StationCsvImportRequest;
use App\Models\User;
use App\Modules\Assets\Application\StationCsvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class StationDataTransferController extends Controller
{
    public function template(StationCsvService $csv): Response
    {
        return response($csv->template(), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="station-import-template.csv"']);
    }

    public function import(StationCsvImportRequest $request, StationCsvService $csv): JsonResponse
    {
        return response()->json($csv->import($this->user($request), $request->file('file')));
    }

    public function export(Request $request, StationCsvService $csv): Response
    {
        return response($csv->export($this->user($request)), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="stations.csv"']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
