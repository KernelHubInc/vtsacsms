<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\FinanceReviews\Pages;

use App\Filament\Operator\Resources\FinanceReviews\FinanceReviewResource;
use Filament\Resources\Pages\ListRecords;

final class ListFinanceReviews extends ListRecords
{
    protected static string $resource = FinanceReviewResource::class;
}
