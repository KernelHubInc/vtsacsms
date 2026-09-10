<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_type_check CHECK (site_type IN ('public_parking', 'retail', 'workplace', 'fleet', 'highway', 'hospitality'))");
        DB::statement("ALTER TABLE charging_connector_statuses ADD CONSTRAINT connector_status_value_check CHECK (status IN ('available', 'occupied', 'reserved', 'unavailable', 'faulted', 'offline', 'unknown'))");
        DB::statement("ALTER TABLE cms_pages ADD CONSTRAINT cms_page_status_check CHECK (status IN ('draft', 'published'))");
        DB::statement("ALTER TABLE cms_articles ADD CONSTRAINT cms_article_status_check CHECK (status IN ('draft', 'published'))");
        DB::statement("ALTER TABLE cms_articles ADD CONSTRAINT cms_article_kind_check CHECK (kind IN ('article', 'news'))");
        DB::statement('ALTER TABLE cms_redirects ADD CONSTRAINT cms_redirect_status_check CHECK (status_code IN (301, 302, 307, 308))');
        DB::statement('ALTER TABLE site_operating_hours ADD CONSTRAINT site_hours_day_check CHECK (day_of_week BETWEEN 0 AND 6)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE sites DROP CONSTRAINT IF EXISTS sites_type_check');
        DB::statement('ALTER TABLE charging_connector_statuses DROP CONSTRAINT IF EXISTS connector_status_value_check');
        DB::statement('ALTER TABLE cms_pages DROP CONSTRAINT IF EXISTS cms_page_status_check');
        DB::statement('ALTER TABLE cms_articles DROP CONSTRAINT IF EXISTS cms_article_status_check');
        DB::statement('ALTER TABLE cms_articles DROP CONSTRAINT IF EXISTS cms_article_kind_check');
        DB::statement('ALTER TABLE cms_redirects DROP CONSTRAINT IF EXISTS cms_redirect_status_check');
        DB::statement('ALTER TABLE site_operating_hours DROP CONSTRAINT IF EXISTS site_hours_day_check');
    }
};
