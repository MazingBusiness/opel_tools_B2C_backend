<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\RegisterB2bUserRequest;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class B2bUserRegistrationController extends Controller
{
    public function store(RegisterB2bUserRequest $request, AuthService $auth): JsonResponse
    {
        /** @var array{name?: string|null, email?: string|null, phone?: string|null} $data */
        $data = $request->safe()->only(['name', 'email', 'phone']);

        $result = $auth->registerFromB2b($data);
        $user = $result['user'];

        return response()->json([
            'ok' => true,
            'created' => $result['created'],
            'user' => $this->userPayload($user),
        ], $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * @return array{id: int, name: string|null, email: string|null, phone: string|null, disabled: bool}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'disabled' => $user->isDisabled(),
        ];
    }
}
