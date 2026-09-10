<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\InspectGoodsReceiptRequest;
use App\Http\Requests\Api\ReceiveGoodsRequest;
use App\Http\Requests\Api\SupplierReturnRequest;
use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Application\GoodsReceiptWorkflow;
use App\Modules\Inventory\Application\SupplierReturnWorkflow;
use App\Modules\Inventory\Domain\Models\GoodsReceipt;
use App\Modules\Inventory\Domain\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class GoodsReceiptController extends Controller
{
    public function index(Request $request, AccessibleWarehousesQuery $accessible): JsonResponse
    {
        Gate::authorize('viewAny', GoodsReceipt::class);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $receipts = GoodsReceipt::query()
            ->whereIn('warehouse_id', $accessible->for($user)->select('id'))
            ->with('lines')
            ->latest()
            ->cursorPaginate(50);

        return response()->json($receipts);
    }

    public function receive(ReceiveGoodsRequest $request, GoodsReceiptWorkflow $workflow): JsonResponse
    {
        $warehouse = Warehouse::query()->whereKey($request->validated('warehouse_id'))->firstOrFail();
        Gate::authorize('update', $warehouse);

        return response()->json(['data' => $this->data($workflow->receive($request->payload()))], 201);
    }

    public function inspect(
        InspectGoodsReceiptRequest $request,
        string $goodsReceipt,
        GoodsReceiptWorkflow $workflow,
    ): JsonResponse {
        $receipt = GoodsReceipt::query()->whereKey($goodsReceipt)->firstOrFail();
        Gate::authorize('update', $receipt);

        return response()->json(['data' => $this->data($workflow->inspect(
            $receipt,
            $request->validated('decisions'),
        ))]);
    }

    public function post(string $goodsReceipt, GoodsReceiptWorkflow $workflow): JsonResponse
    {
        $receipt = GoodsReceipt::query()->whereKey($goodsReceipt)->firstOrFail();
        Gate::authorize('update', $receipt);

        return response()->json(['data' => $this->data($workflow->post($receipt))]);
    }

    public function returnRejected(
        SupplierReturnRequest $request,
        string $goodsReceipt,
        SupplierReturnWorkflow $workflow,
    ): JsonResponse {
        $receipt = GoodsReceipt::query()->whereKey($goodsReceipt)->firstOrFail();
        Gate::authorize('update', $receipt);
        $return = $workflow->returnRejected(
            $receipt,
            (string) $request->validated('return_number'),
            (string) $request->validated('reason'),
        );

        return response()->json(['data' => [
            'id' => (string) $return->getKey(),
            'return_number' => (string) $return->return_number,
            'status' => (string) $return->status,
        ]], 201);
    }

    /** @return array<string, mixed> */
    private function data(GoodsReceipt $receipt): array
    {
        return [
            'id' => (string) $receipt->getKey(),
            'purchase_order_id' => (string) $receipt->purchase_order_id,
            'warehouse_id' => (string) $receipt->warehouse_id,
            'receipt_number' => (string) $receipt->receipt_number,
            'status' => (string) $receipt->status,
            'received_at' => $receipt->received_at->utc()->toISOString(),
            'lines' => $receipt->relationLoaded('lines')
                ? $receipt->lines->map(static fn ($line): array => [
                    'id' => (string) $line->getKey(),
                    'purchase_order_line_id' => (string) $line->purchase_order_line_id,
                    'item_id' => (string) $line->item_id,
                    'received_quantity_base' => (int) $line->received_quantity_base,
                    'accepted_quantity_base' => (int) $line->accepted_quantity_base,
                    'rejected_quantity_base' => (int) $line->rejected_quantity_base,
                    'inspection_status' => (string) $line->inspection_status,
                ])
                : [],
        ];
    }
}
