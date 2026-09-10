<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('site_type', 40)->default('public_parking')->after('timezone');
            $table->index(['site_type', 'lifecycle_status', 'is_public'], 'site_type_public_idx');
        });

        Schema::create('charging_connector_statuses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('connector_id');
            $table->string('status', 24);
            $table->timestampTz('observed_at');
            $table->unsignedInteger('stale_after_seconds')->default(300);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'connector_status_tenant_id_unique');
            $table->unique(['tenant_id', 'connector_id'], 'connector_status_tenant_connector_unique');
            $table->index(['tenant_id', 'status', 'observed_at'], 'connector_status_tenant_state_time_idx');
            $table->foreign('tenant_id', 'connector_status_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'connector_id'], 'connector_status_connector_fk')
                ->references(['tenant_id', 'id'])->on('connectors')->cascadeOnDelete();
        });

        Schema::create('cms_pages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('slug', 180)->unique();
            $table->string('title', 180);
            $table->string('eyebrow', 120)->nullable();
            $table->string('summary', 500)->nullable();
            $table->text('body')->nullable();
            $table->string('template', 40)->default('default');
            $table->string('status', 20)->default('draft');
            $table->string('hero_heading', 240)->nullable();
            $table->text('hero_copy')->nullable();
            $table->string('primary_action_label', 80)->nullable();
            $table->string('primary_action_url', 500)->nullable();
            $table->string('secondary_action_label', 80)->nullable();
            $table->string('secondary_action_url', 500)->nullable();
            $table->string('featured_image_disk', 40)->nullable();
            $table->string('featured_image_path', 500)->nullable();
            $table->string('featured_image_alt', 240)->nullable();
            $table->boolean('legal_review_required')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'published_at', 'slug'], 'cms_page_publish_idx');
        });

        Schema::create('cms_sections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cms_page_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('eyebrow', 120)->nullable();
            $table->string('heading', 240);
            $table->text('copy')->nullable();
            $table->json('items')->nullable();
            $table->string('action_label', 80)->nullable();
            $table->string('action_url', 500)->nullable();
            $table->string('image_disk', 40)->nullable();
            $table->string('image_path', 500)->nullable();
            $table->string('image_alt', 240)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
            $table->index(['cms_page_id', 'is_enabled', 'sort_order'], 'cms_section_page_order_idx');
        });

        Schema::create('cms_partner_logos', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 160);
            $table->string('website_url', 500)->nullable();
            $table->string('image_disk', 40)->default('s3');
            $table->string('image_path', 500);
            $table->string('image_alt', 240);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestampsTz();
            $table->index(['is_published', 'sort_order'], 'cms_partner_publish_order_idx');
        });

        Schema::create('cms_faqs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('category', 80)->default('general');
            $table->string('question', 300);
            $table->text('answer');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestampsTz();
            $table->index(['is_published', 'category', 'sort_order'], 'cms_faq_publish_category_idx');
        });

        Schema::create('cms_articles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 20)->default('article');
            $table->string('slug', 180)->unique();
            $table->string('title', 240);
            $table->string('excerpt', 500)->nullable();
            $table->text('body');
            $table->string('author_name', 160)->nullable();
            $table->string('featured_image_disk', 40)->nullable();
            $table->string('featured_image_path', 500)->nullable();
            $table->string('featured_image_alt', 240)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'kind', 'published_at'], 'cms_article_publish_kind_idx');
        });

        Schema::create('cms_testimonials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->text('quote');
            $table->string('person_name', 160);
            $table->string('person_role', 160)->nullable();
            $table->string('organization_name', 160)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestampsTz();
        });

        Schema::create('cms_app_store_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('store', 30)->unique();
            $table->string('label', 100);
            $table->string('url', 500)->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestampsTz();
        });

        Schema::create('cms_contact_details', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key', 80)->unique();
            $table->string('label', 120);
            $table->string('value', 500);
            $table->boolean('is_public')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('cms_seo_metadata', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('target_type', 32);
            $table->ulid('target_id');
            $table->string('meta_title', 70);
            $table->string('meta_description', 180);
            $table->string('canonical_url', 500)->nullable();
            $table->string('social_image_url', 500)->nullable();
            $table->boolean('noindex')->default(false);
            $table->json('structured_data')->nullable();
            $table->timestampsTz();
            $table->unique(['target_type', 'target_id'], 'cms_seo_target_unique');
        });

        Schema::create('cms_redirects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('source_path', 500)->unique();
            $table->string('destination_url', 500);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('hit_count')->default(0);
            $table->timestampTz('last_hit_at')->nullable();
            $table->timestampsTz();
            $table->index(['is_enabled', 'source_path'], 'cms_redirect_enabled_path_idx');
        });
    }

    public function down(): void
    {
        foreach (['cms_redirects', 'cms_seo_metadata', 'cms_contact_details', 'cms_app_store_links', 'cms_testimonials', 'cms_articles', 'cms_faqs', 'cms_partner_logos', 'cms_sections', 'cms_pages', 'charging_connector_statuses'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropIndex('site_type_public_idx');
            $table->dropColumn('site_type');
        });
    }
};
