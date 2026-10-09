<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Aub;

use App\Modules\Payments\Application\Qr\WalletException;
use DOMDocument;
use DOMElement;

final class AubQrProtocol
{
    /** SHA256 per section 4; not HMAC and not a hash of serialized XML.
     * @param  array<string,string>  $fields
     */
    public function sign(array $fields, #[\SensitiveParameter] string $key): string
    {
        unset($fields['sign']);
        $fields = array_filter($fields, fn (string $value): bool => $value !== '');
        ksort($fields, SORT_STRING);
        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = $name.'='.$value;
        }

        return strtoupper(hash('sha256', implode('&', $parts).'&key='.$key));
    }

    /** @param array<string,string> $fields */
    public function encode(array $fields, #[\SensitiveParameter] string $key): string
    {
        $fields['sign_type'] = 'SHA256';
        $fields['sign'] = $this->sign($fields, $key);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->appendChild($doc->createElement('xml'));
        foreach ($fields as $name => $value) {
            $node = $doc->createElement($name);
            $node->appendChild($doc->createTextNode($value));
            $root->appendChild($node);
        }

        return (string) $doc->saveXML();
    }

    /** @return array<string,string> */
    public function verify(string $xml, #[\SensitiveParameter] string $key): array
    {
        if ($key === '' || strlen($xml) > 65536 || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            $this->invalid();
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            if (! $doc->loadXML($xml, LIBXML_NONET) || $doc->documentElement?->tagName !== 'xml' || $doc->doctype !== null) {
                $this->invalid();
            }
            $fields = [];
            foreach ($doc->documentElement->childNodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                if ($node->attributes->length > 0 || isset($fields[$node->tagName])) {
                    $this->invalid();
                }
                foreach ($node->childNodes as $child) {
                    if ($child instanceof DOMElement) {
                        $this->invalid();
                    }
                }
                $fields[$node->tagName] = $node->textContent;
            }
            if (($fields['sign_type'] ?? '') !== 'SHA256' || ! hash_equals($this->sign($fields, $key), $fields['sign'] ?? '')) {
                $this->invalid();
            }

            return $fields;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function invalid(): never
    {
        throw new WalletException('INVALID_AUB_MESSAGE', 'Payment verification failed.', 400);
    }
}
