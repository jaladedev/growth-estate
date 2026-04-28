<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;

class MonnifyService
{
    private static function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withOptions([
            'curl' => [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2],
        ]);
    }

    protected static function authToken(): ?string
    {
        $credentials = base64_encode(
            config('services.monnify.api_key') . ':' .
            config('services.monnify.secret_key')
        );

        $response = self::client()
            ->withHeaders(['Authorization' => "Basic {$credentials}"])
            ->post(config('services.monnify.base_url') . '/api/v1/auth/login');

        return $response['responseBody']['accessToken'] ?? null;
    }

    public static function initialize(
        string $email,
        string $reference,
        int $amountKobo,
        string $callbackUrl,
        string $name
    ) {
        $token = self::authToken();

        return self::client()
            ->withToken($token)
            ->post(config('services.monnify.base_url') . '/api/v1/merchant/transactions/init-transaction', [
                'amount'             => $amountKobo / 100,
                'customerName'       => $name,
                'customerEmail'      => $email,
                'paymentReference'   => $reference,
                'paymentDescription' => 'Wallet Deposit',
                'currencyCode'       => 'NGN',
                'contractCode'       => config('services.monnify.contract_code'),
                'redirectUrl'        => $callbackUrl,
                'paymentMethods'     => ['CARD', 'ACCOUNT_TRANSFER'],
            ]);
    }
}