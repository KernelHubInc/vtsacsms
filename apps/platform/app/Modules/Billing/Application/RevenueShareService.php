<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Models\Invoice;
use App\Modules\Billing\Domain\Models\RevenueShareRule;
use InvalidArgumentException;

final readonly class RevenueShareService
{
    public function __construct(private DoubleEntryLedger $ledger) {}

    public function allocate(Invoice $invoice, RevenueShareRule $rule): void
    {
        $basisPoints = (int) $rule->platform_basis_points + (int) $rule->operator_basis_points + (int) $rule->site_host_basis_points;
        if ($basisPoints !== 10000) {
            throw new InvalidArgumentException('Revenue share basis points must total 10,000.');
        }
        $total = (int) $invoice->total_minor;
        $platform = intdiv($total * (int) $rule->platform_basis_points, 10000);
        $operator = intdiv($total * (int) $rule->operator_basis_points, 10000);
        $siteHost = $total - $platform - $operator;
        $gross = $this->ledger->account('4000-CHARGING-GROSS', 'Gross charging revenue', 'revenue', (string) $invoice->currency);
        $postings = [new LedgerPosting($gross, debitMinor: $total)];
        if ($platform > 0) {
            $account = $this->ledger->account('4100-PLATFORM-FEE', 'Platform fee revenue', 'revenue', (string) $invoice->currency, 'platform', (string) $rule->tenant_id);
            $postings[] = new LedgerPosting($account, creditMinor: $platform);
        }
        if ($operator > 0) {
            $account = $this->ledger->account('2100-OPERATOR-'.$rule->operator_organization_id, 'Operator revenue payable', 'liability', (string) $invoice->currency, 'organization', (string) $rule->operator_organization_id);
            $postings[] = new LedgerPosting($account, creditMinor: $operator);
        }
        if ($siteHost > 0 && $rule->site_host_organization_id !== null) {
            $account = $this->ledger->account('2200-SITE-HOST-'.$rule->site_host_organization_id, 'Site-host revenue payable', 'liability', (string) $invoice->currency, 'organization', (string) $rule->site_host_organization_id);
            $postings[] = new LedgerPosting($account, creditMinor: $siteHost);
        } elseif ($siteHost > 0) {
            throw new InvalidArgumentException('A site-host allocation requires a site-host organization.');
        }
        $this->ledger->post('invoice', (string) $invoice->getKey(), 'revenue_share_allocated', (string) $invoice->currency,
            'revenue-share-'.(string) $invoice->getKey().'-'.(string) $rule->getKey(), $postings);
    }
}
