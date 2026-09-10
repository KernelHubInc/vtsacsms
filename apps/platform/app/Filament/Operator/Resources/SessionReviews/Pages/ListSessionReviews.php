<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\SessionReviews\Pages;

use App\Filament\Operator\Resources\SessionReviews\SessionReviewResource;
use Filament\Resources\Pages\ListRecords;

final class ListSessionReviews extends ListRecords
{
    protected static string $resource = SessionReviewResource::class;
}
