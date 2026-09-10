<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_PARTNER_COPY = 'Work with VTSA CSMS to connect operators, site hosts, installers, and service teams around a shared operating foundation.';

    private const NEW_PARTNER_COPY = 'Work with Power Solutions to connect operators, site hosts, installers, and service teams around a shared operating foundation.';

    public function up(): void
    {
        DB::table('cms_pages')
            ->where('summary', self::OLD_PARTNER_COPY)
            ->update(['summary' => self::NEW_PARTNER_COPY]);

        DB::table('cms_pages')
            ->where('hero_copy', self::OLD_PARTNER_COPY)
            ->update(['hero_copy' => self::NEW_PARTNER_COPY]);

        DB::table('cms_seo_metadata')
            ->where('social_image_url', '/images/vtsa-social-card.svg')
            ->update(['social_image_url' => '/branding/power-solutions-social-card.svg']);

        DB::table('cms_seo_metadata')
            ->where('meta_title', 'like', '% | VTSA CSMS')
            ->orderBy('id')
            ->eachById(function (object $metadata): void {
                DB::table('cms_seo_metadata')
                    ->where('id', $metadata->id)
                    ->update([
                        'meta_title' => str_replace(
                            ' | VTSA CSMS',
                            ' | Power Solutions',
                            (string) $metadata->meta_title,
                        ),
                    ]);
            }, column: 'id');

        DB::table('tenants')
            ->where('name', 'VTSA Milestone 1 Demo (Test data)')
            ->update(['name' => 'Power Solutions Milestone 1 Demo (Test data)']);

        DB::table('organizations')
            ->where('name', 'VTSA Demo Platform')
            ->update(['name' => 'Power Solutions Demo Platform']);
    }

    public function down(): void
    {
        DB::table('cms_pages')
            ->where('summary', self::NEW_PARTNER_COPY)
            ->update(['summary' => self::OLD_PARTNER_COPY]);

        DB::table('cms_pages')
            ->where('hero_copy', self::NEW_PARTNER_COPY)
            ->update(['hero_copy' => self::OLD_PARTNER_COPY]);

        DB::table('cms_seo_metadata')
            ->where('social_image_url', '/branding/power-solutions-social-card.svg')
            ->update(['social_image_url' => '/images/vtsa-social-card.svg']);

        DB::table('cms_seo_metadata')
            ->where('meta_title', 'like', '% | Power Solutions')
            ->orderBy('id')
            ->eachById(function (object $metadata): void {
                DB::table('cms_seo_metadata')
                    ->where('id', $metadata->id)
                    ->update([
                        'meta_title' => str_replace(
                            ' | Power Solutions',
                            ' | VTSA CSMS',
                            (string) $metadata->meta_title,
                        ),
                    ]);
            }, column: 'id');

        DB::table('tenants')
            ->where('name', 'Power Solutions Milestone 1 Demo (Test data)')
            ->update(['name' => 'VTSA Milestone 1 Demo (Test data)']);

        DB::table('organizations')
            ->where('name', 'Power Solutions Demo Platform')
            ->update(['name' => 'VTSA Demo Platform']);
    }
};
