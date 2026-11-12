@if (\App\Filament\Shared\Pages\CustomizeSidebar::canAccess())
    <div class="mt-3 border-t border-gray-200 pt-3 dark:border-gray-700">
        <x-filament::button tag="a" color="gray" icon="heroicon-o-adjustments-horizontal"
            :href="\App\Filament\Shared\Pages\CustomizeSidebar::getUrl()" class="w-full">
            Customize sidebar
        </x-filament::button>
    </div>
@endif
