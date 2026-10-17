<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChargingAccountRequest;
use App\Models\User;
use App\Modules\Assets\Application\DriverVehicles;
use App\Modules\Billing\Application\PrepaidWallet;
use App\Modules\Identity\Application\ChargingAccountInvitations;
use App\Modules\Organizations\Application\ChargingAccounts;
use App\Modules\Organizations\Domain\ChargingAccountRole;
use App\Modules\Organizations\Domain\PermissionKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ChargingAccountController extends Controller
{
    public function __construct(private readonly ChargingAccounts $accounts, private readonly ChargingAccountInvitations $invitations) {}

    public function index(Request $request): JsonResponse
    {
        return $this->respond($this->accounts->list($this->user($request)));
    }

    public function store(ChargingAccountRequest $request): JsonResponse
    {
        $user = $this->user($request);

        return $this->respond($this->accounts->present($user, $this->accounts->create($user, $request->validated('name'), $request->validated('type'))));
    }

    public function members(Request $request, string $account): JsonResponse
    {
        return $this->respond($this->accounts->members($this->user($request), $account));
    }

    public function member(ChargingAccountRequest $request, string $account, string $subject): JsonResponse
    {
        $role = $request->validated('role');
        $this->accounts->member($this->user($request), $account, $subject, $role === null ? null : ChargingAccountRole::from($role));

        return $this->respond(['saved' => true]);
    }

    public function invitations(Request $request, ?string $account = null): JsonResponse
    {
        return $this->respond($this->invitations->pending($this->user($request), $account));
    }

    public function invite(ChargingAccountRequest $request, string $account): JsonResponse
    {
        return $this->respond($this->invitations->invite($this->user($request), $account, $request->validated('email'), ChargingAccountRole::from($request->validated('role'))));
    }

    public function accept(Request $request, string $invitation): JsonResponse
    {
        $this->invitations->accept($this->user($request), $invitation);

        return $this->respond(['accepted' => true]);
    }

    public function revoke(Request $request, string $account, string $invitation): JsonResponse
    {
        $this->invitations->revoke($this->user($request), $account, $invitation);

        return $this->respond(['revoked' => true]);
    }

    public function assignments(Request $request, string $account, string $vehicle): JsonResponse
    {
        return $this->respond(app(DriverVehicles::class)->assignments($this->user($request)->public_id, $account, $vehicle));
    }

    public function assign(ChargingAccountRequest $request, string $account, string $vehicle): JsonResponse
    {
        app(DriverVehicles::class)->assign($this->user($request)->public_id, $account, $vehicle, $request->validated('subject_id'), (bool) $request->validated('assigned'));

        return $this->respond(['saved' => true]);
    }

    public function limits(Request $request, string $account): JsonResponse
    {
        $this->accounts->require($this->user($request), $account, PermissionKey::ChargingLimitsManage);

        return $this->respond(app(PrepaidWallet::class)->limits($account));
    }

    public function limit(ChargingAccountRequest $request, string $account): JsonResponse
    {
        DB::transaction(function () use ($request, $account): void {
            $user = $this->user($request);
            $record = $this->accounts->require($user, $account, PermissionKey::ChargingLimitsManage, true);
            abort_if($record->charging_account_type === 'personal', 422);
            $type = $request->validated('target_type');
            $target = $request->validated('target_id');
            if ($type === 'driver') {
                $this->accounts->require(User::query()->where('public_id', $target)->firstOrFail(), $account, PermissionKey::ChargingAccountCharge);
            } else {
                app(DriverVehicles::class)->chargeVehicle($user->public_id, $account, $target);
            }
            app(PrepaidWallet::class)->setLimit($user->public_id, $account, $type, $target, $request->validated('amount_minor'));
        });

        return $this->respond(['saved' => true]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @param array<mixed> $data */
    private function respond(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
