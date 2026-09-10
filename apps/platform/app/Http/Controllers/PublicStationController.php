<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\CMS\Application\PublicContentRepository;
use App\Modules\Integrations\Application\MapConfigurationResolver;
use App\Modules\Integrations\Domain\MapSurface;
use App\Modules\Locations\Application\PublicStationSearch;
use Illuminate\Contracts\View\View;

final class PublicStationController extends Controller
{
    public function __invoke(
        string $slug,
        PublicStationSearch $search,
        PublicContentRepository $content,
        MapConfigurationResolver $maps,
    ): View {
        $stations = collect($search->search(['limit' => 250]))
            ->filter(fn (array $station): bool => $station['public_slug'] === $slug)
            ->values();

        abort_if($stations->isEmpty(), 404);

        return view('public.station', [
            'page' => $content->page('charging-map'),
            'station' => $stations->first(),
            'siteStations' => $stations,
            'mapConfig' => $maps->forSurface(MapSurface::Public)->toWebArray(),
            'directionsUrl' => str_replace(
                ['{latitude}', '{longitude}', '{name}'],
                [
                    rawurlencode((string) $stations->first()['latitude']),
                    rawurlencode((string) $stations->first()['longitude']),
                    rawurlencode((string) $stations->first()['site_name']),
                ],
                (string) config('maps.directions.web_url_template'),
            ),
        ]);
    }
}
