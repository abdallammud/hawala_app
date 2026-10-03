<?php

namespace App\Http\Controllers\Setup;

use App\Enums\SmsProvider;
use App\Http\Controllers\Controller;
use App\Services\Sms\GatewayCredentials;
use App\Services\Sms\HormuudDriver;
use App\Services\Sms\SmsDeliveryException;
use App\Services\Sms\TabaarakDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Test connection" on the SMS settings card: logs in to the gateway with
 * what is in the form (blank boxes fall back to the saved values). Nothing
 * is sent and nothing is charged.
 */
class SmsConnectionTestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('setup.settings');

        $validated = $request->validate([
            'provider' => ['required', Rule::in(array_map(fn (SmsProvider $p) => $p->value, SmsProvider::cases()))],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255'],
            'api_url' => ['nullable', 'url:http,https', 'max:255'],
        ]);

        $provider = SmsProvider::from($validated['provider']);

        $credentials = GatewayCredentials::forProvider($provider)->with(
            $validated['username'] ?? null,
            $validated['password'] ?? null,
            $validated['api_url'] ?? null,
        );

        $driver = $provider === SmsProvider::Hormuud
            ? new HormuudDriver($credentials)
            : new TabaarakDriver($credentials);

        try {
            $driver->testConnection();
        } catch (SmsDeliveryException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'message' => __('Connection successful, the credentials are valid.')]);
    }
}
