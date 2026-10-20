<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Aub;

use App\Modules\Payments\Domain\Models\QrTopup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final readonly class AubQrGateway
{
    public function __construct(private AubQrProtocol $protocol) {}

    /** @return array<string,string> */
    public function create(QrTopup $topup): array
    {
        return $this->call(['service' => 'pay.instapay.native.v2', 'mch_id' => (string) $topup->merchant_id,
            'out_trade_no' => (string) $topup->getKey(), 'body' => 'Power Solutions prepaid charging credit',
            'total_fee' => (string) $topup->amount_minor, 'mch_create_ip' => (string) config('wallet.aub.server_ip'),
            'notify_url' => (string) config('wallet.aub.notify_url')]);
    }

    /** @return array<string,string> */
    public function retrieve(QrTopup $topup): array
    {
        // invoice_id is required in the supplied QR Ph query schema. Do not guess after a create timeout.
        if (! $topup->invoice_id) {
            return [];
        }

        return $this->call(['service' => 'pay.instapay.query', 'mch_id' => (string) $topup->merchant_id,
            'out_trade_no' => (string) $topup->getKey(), 'invoice_id' => (string) $topup->invoice_id]);
    }

    /** @param array<string,string> $fields
     * @return array<string,string>
     */
    private function call(array $fields): array
    {
        if (! config('wallet.aub.approved')) {
            throw new \LogicException('AUB contract approval is required.');
        }
        $key = (string) config('wallet.aub.signing_key');
        $body = $this->protocol->encode($fields + ['version' => '2.0', 'charset' => 'UTF-8', 'nonce_str' => Str::random(32)], $key);
        // A timeout is ambiguous. Never automatically retry a request that creates an order.
        $response = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()->withBody($body, 'application/xml')
            ->post('https://gateway.wepayez.com/pay/gateway');
        $response->throw();

        return $this->protocol->verify($response->body(), $key);
    }
}
