<?php

namespace App\Modules\Address\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Address\Http\Requests\StoreAddressRequest;
use App\Modules\Address\Http\Requests\UpdateAddressRequest;
use App\Modules\Address\Http\Resources\AddressResource;
use App\Modules\Address\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $addresses = Address::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return AddressResource::collection($addresses)
            ->additional([
                'meta' => [
                    'count' => $addresses->count(),
                ],
            ]);
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $data = $request->validated();
        $makeDefault = (bool) ($data['is_default'] ?? false);

        $address = DB::transaction(function () use ($userId, $data, $makeDefault) {
            $count = Address::query()->where('user_id', $userId)->lockForUpdate()->count();
            $isDefault = $makeDefault || $count === 0;

            if ($isDefault) {
                Address::query()
                    ->where('user_id', $userId)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            return Address::query()->create([
                'user_id' => $userId,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'line1' => $data['line1'],
                'line2' => $data['line2'] ?? null,
                'city' => $data['city'],
                'state' => $data['state'],
                'pincode' => $data['pincode'],
                'type' => $data['type'],
                'is_default' => $isDefault,
            ]);
        });

        return (new AddressResource($address))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAddressRequest $request, int $id): AddressResource|JsonResponse
    {
        $address = $this->ownedAddress($request, $id);
        $data = $request->validated();

        // Unsetting default via PATCH is rejected — use POST …/default on another address.
        if (array_key_exists('is_default', $data) && $data['is_default'] === false && $address->is_default) {
            return response()->json([
                'message' => 'Cannot unset the default address. Set another address as default instead.',
                'errors' => [
                    'is_default' => ['Cannot unset the default address. Set another address as default instead.'],
                ],
            ], 422);
        }

        DB::transaction(function () use ($request, $address, $data) {
            $address = Address::query()
                ->where('user_id', $request->user()->id)
                ->whereKey($address->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (array_key_exists('is_default', $data) && $data['is_default']) {
                Address::query()
                    ->where('user_id', $request->user()->id)
                    ->where('is_default', true)
                    ->whereKeyNot($address->id)
                    ->update(['is_default' => false]);
                $data['is_default'] = true;
            }

            $address->fill($data);
            $address->save();
        });

        return new AddressResource($address->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $address = $this->ownedAddress($request, $id);

        DB::transaction(function () use ($request, $address) {
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                $next = Address::query()
                    ->where('user_id', $request->user()->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if ($next) {
                    $next->is_default = true;
                    $next->save();
                }
            }
        });

        return response()->json([
            'data' => [
                'id' => $id,
                'removed' => true,
            ],
        ]);
    }

    public function setDefault(Request $request, int $id): AddressResource
    {
        $address = DB::transaction(function () use ($request, $id) {
            $address = Address::query()
                ->where('user_id', $request->user()->id)
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            Address::query()
                ->where('user_id', $request->user()->id)
                ->where('is_default', true)
                ->whereKeyNot($address->id)
                ->update(['is_default' => false]);

            $address->is_default = true;
            $address->save();

            return $address->fresh();
        });

        return new AddressResource($address);
    }

    private function ownedAddress(Request $request, int $id): Address
    {
        return Address::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($id)
            ->firstOrFail();
    }
}
