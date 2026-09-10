<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use LogicException;

final class ReceiveTransferRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'received' => ['required', 'array', 'min:1', 'max:500'],
            'received.*.line_id' => ['required', 'ulid', 'distinct'],
            'received.*.quantity_base' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, int> */
    public function quantitiesByLine(): array
    {
        $rawLines = $this->validated('received');
        if (! is_array($rawLines)) {
            throw new LogicException('Validated transfer receipts are unavailable.');
        }
        $received = [];
        foreach ($rawLines as $rawLine) {
            if (! is_array($rawLine)) {
                throw new LogicException('A validated transfer receipt is invalid.');
            }
            $received[(string) $rawLine['line_id']] = (int) $rawLine['quantity_base'];
        }

        return $received;
    }
}
