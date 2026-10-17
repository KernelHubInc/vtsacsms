<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WalletTopupRequest;
use App\Models\User;
use App\Modules\Billing\Application\PrepaidWallet;
use App\Modules\Organizations\Application\ChargingAccounts;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Payments\Application\Qr\QrAvailability;
use App\Modules\Payments\Application\Qr\QrTopups;
use App\Modules\Payments\Domain\Models\QrTopup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WalletController extends Controller
{
    public function __construct(private readonly PrepaidWallet $wallet, private readonly QrAvailability $availability, private readonly QrTopups $topups) {}

    public function index(Request $request): JsonResponse
    {
        $mode = $this->availability->mode();
        $account = $this->account($request, PermissionKey::ChargingWalletView);
        $book = $mode === 'simulated' ? 'simulated' : 'live';
        $data = $this->wallet->summary($this->subject($request), $book, $account);
        $data += ['mode' => $mode, 'book' => $book, 'topups_enabled' => $mode !== 'disabled',
            'minimum_minor' => (int) config('wallet.minimum_minor'), 'maximum_minor' => (int) config('wallet.maximum_minor')];
        $data['history'] = $this->wallet->history($this->subject($request), $book, $account);
        if ($account !== null) {
            $data['topups_enabled'] = $data['topups_enabled'] && app(ChargingAccounts::class)->can($request->user(), $account, PermissionKey::ChargingWalletFund);
        }

        return $this->respond($data);
    }

    public function create(WalletTopupRequest $request): JsonResponse
    {
        $account = $this->account($request, PermissionKey::ChargingWalletFund);
        $row = $this->topups->create($this->subject($request), (int) $request->validated('amount_minor'), (string) $request->validated('idempotency_key'), $account);

        return $this->respond($this->topups->present($row));
    }

    public function show(Request $request, string $topup): JsonResponse
    {
        $account = $this->account($request, PermissionKey::ChargingWalletView);
        $query = QrTopup::query()->where('charging_account_id', $account);
        if ($account === null) {
            $query->where('subject_id', $this->subject($request));
        }
        $row = $query->findOrFail($topup);

        return $this->respond($this->topups->present($row));
    }

    public function topups(Request $request): JsonResponse
    {
        $book = $this->availability->mode() === 'simulated' ? 'simulated' : 'live';
        $account = $this->account($request, PermissionKey::ChargingWalletView);
        $query = QrTopup::query()->where('charging_account_id', $account);
        if ($account === null) {
            $query->where('subject_id', $this->subject($request));
        }
        $page = $query->where('book', $book)->orderByDesc('id')->cursorPaginate(20);

        return $this->respond(['items' => $page->map(fn (QrTopup $row): array => $this->topups->present($row))->all(), 'next_cursor' => $page->nextCursor()?->encode()]);
    }

    private function subject(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return (string) $user->public_id;
    }

    private function account(Request $request, PermissionKey $permission): ?string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $account = app(ChargingAccounts::class)->selected($user, $request->header('X-Charging-Account-ID'), $permission);

        return $account === null || $account->charging_account_type === 'personal' ? null : (string) $account->getKey();
    }

    /** @param array<string,mixed> $data */
    private function respond(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
