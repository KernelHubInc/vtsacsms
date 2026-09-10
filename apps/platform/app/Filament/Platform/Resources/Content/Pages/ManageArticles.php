<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content\Pages;

use App\Filament\Platform\Resources\Content\ArticleResource;

final class ManageArticles extends ManageCmsRecords
{
    protected static string $resource = ArticleResource::class;
}
