<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AcceptInvitationRequest;
use App\Http\Requests\Api\InviteUserRequest;
use App\Http\Resources\Api\V1\InvitationResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Modules\Identity\Application\InvitationService;
use App\Modules\Identity\Domain\Models\UserInvitation;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvitationController extends Controller
{
    public function store(InviteUserRequest $request, InvitationService $invitations): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $organizationId = $request->validated('organization_id');
        $organization = is_string($organizationId)
            ? Organization::query()->whereKey($organizationId)->firstOrFail()
            : null;
        $role = Role::query()->whereKey($request->validated('role_id'))->firstOrFail();
        $scope = new ResourceScope(
            ScopeType::from((string) $request->validated('scope_type')),
            $request->validated('scope_id'),
        );

        return (new InvitationResource($invitations->invite(
            $actor,
            (string) $request->validated('email'),
            $organization,
            $role,
            $scope,
        )->invitation))->response()->setStatusCode(201);
    }

    public function accept(
        AcceptInvitationRequest $request,
        InvitationService $invitations,
    ): JsonResponse {
        $accepted = $invitations->accept(
            (string) $request->validated('token'),
            $request->validated('name'),
            $request->validated('password'),
            (string) $request->attributes->get('correlation_id'),
        );

        return response()->json(['data' => [
            'user' => new UserResource($accepted['user']),
            'tenant_id' => $accepted['tenant_id'],
        ]]);
    }

    public function destroy(
        Request $request,
        string $invitation,
        InvitationService $invitations,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $model = UserInvitation::query()->whereKey($invitation)->firstOrFail();
        $invitations->revoke($actor, $model, 'administrator_revocation');

        return response()->json([], 204);
    }
}
