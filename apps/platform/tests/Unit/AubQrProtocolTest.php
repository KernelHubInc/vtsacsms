<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Payments\Application\Qr\WalletException;
use App\Modules\Payments\Infrastructure\Aub\AubQrProtocol;
use PHPUnit\Framework\TestCase;

final class AubQrProtocolTest extends TestCase
{
    public function test_canonicalization_keeps_zero_unicode_and_extra_fields_without_url_encoding(): void
    {
        $protocol = new AubQrProtocol;
        $key = bin2hex(random_bytes(16));
        $fields = ['z' => '0', 'empty' => '', 'a' => 'café & charging', 'sign_type' => 'SHA256'];
        $expected = strtoupper(hash('sha256', 'a=café & charging&sign_type=SHA256&z=0&key='.$key));
        $this->assertSame($expected, $protocol->sign($fields, $key));
        $xml = $protocol->encode($fields + ['new_provider_field' => 'value'], $key);
        $this->assertSame('value', $protocol->verify($xml, $key)['new_provider_field']);
        $this->expectException(WalletException::class);
        $protocol->verify(str_replace('value', 'tampered', $xml), $key);
    }

    public function test_rejects_entities_duplicate_fields_nested_xml_and_missing_signatures(): void
    {
        $protocol = new AubQrProtocol;
        foreach (['<!DOCTYPE xml [<!ENTITY x SYSTEM "file:///etc/passwd">]><xml><a>&x;</a></xml>', '<xml><a>1</a><a>2</a></xml>', '<xml><a><b>1</b></a></xml>', '<xml><status>0</status></xml>'] as $xml) {
            try {
                $protocol->verify($xml, 'test-only-key');
                $this->fail('Unsafe XML accepted');
            } catch (WalletException $error) {
                $this->assertSame('INVALID_AUB_MESSAGE', $error->errorCode);
            }
        }
    }
}
