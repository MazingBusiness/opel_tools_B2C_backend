<?php

namespace App\Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Services\ZohoPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ZohoPaymentController extends Controller
{
    public function __construct(private ZohoPaymentService $zoho)
    {
    }

    /** Start OAuth (sandbox one-time). Open this URL in a browser while logged into Zoho. */
    public function oauthRedirect(): JsonResponse
    {
        $state = Str::random(40);
        Cache::put('zoho_oauth_state:'.$state, true, now()->addMinutes(15));

        return response()->json([
            'data' => [
                'auth_url' => $this->zoho->authUrl($state),
                'state' => $state,
            ],
        ]);
    }

    public function oauthCallback(Request $request): JsonResponse
    {
        if (! $request->filled('code')) {
            return response()->json(['message' => 'Authorization code missing.'], 422);
        }

        $state = (string) $request->query('state', '');
        if ($state === '' || ! Cache::pull('zoho_oauth_state:'.$state)) {
            return response()->json(['message' => 'Invalid or expired OAuth state.'], 422);
        }

        try {
            $this->zoho->exchangeCode((string) $request->query('code'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'message' => 'Zoho Payments token stored. You can close this tab.',
        ]);
    }

    /**
     * Webhook stub — log only until signature verification is registered.
     * Never mutates order status from an unverified body.
     */
    public function webhook(Request $request): JsonResponse
    {
        Log::info('[ZohoPayment] webhook received (no-op until signature verify)', [
            'payload' => $request->all(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Browser return from Zoho — log query only. Does NOT mark paid (forgeable).
     * FE must poll authenticated payment-status which confirms via Zoho API.
     */
    public function returnSync(Request $request): JsonResponse
    {
        Log::info('[ZohoPayment] return-sync noted (no status mutation)', [
            'query' => $request->query(),
        ]);

        return response()->json([
            'data' => [
                'noted' => true,
                'message' => 'Return noted. Confirm payment via authenticated payment-status poll.',
            ],
        ]);
    }
}
