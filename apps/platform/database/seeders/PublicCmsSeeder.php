<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\CMS\Domain\Models\AppStoreLink;
use App\Modules\CMS\Domain\Models\CmsPage;
use App\Modules\CMS\Domain\Models\CmsSection;
use App\Modules\CMS\Domain\Models\Faq;
use App\Modules\CMS\Domain\Models\SeoMetadata;
use Illuminate\Database\Seeder;

final class PublicCmsSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            ['home', 'Home', 'home', 'Charging that fits the places people already go.', 'Find dependable charging, understand every stop, and keep moving with a network designed around real journeys.', 'Explore the charging map', '/charging-map'],
            ['ev-charging-network', 'EV charging network', 'network', 'One connected network, built place by place.', 'Explore how sites, chargers, operators, and service teams work together to make public charging easier to trust.', 'Find a station', '/charging-map'],
            ['charging-map', 'Public charging map', 'map', 'See the next useful charging stop.', 'Search the public network by location, connector, power, operator, and current reported status.', null, null],
            ['for-ev-drivers', 'For EV drivers', 'default', 'Less uncertainty between here and there.', 'Use clear station details, connector compatibility, and directions to plan charging with confidence.', 'Open the map', '/charging-map'],
            ['for-operators-and-site-hosts', 'For operators and site hosts', 'default', 'Turn charging infrastructure into a service people return to.', 'Operate sites with clear ownership, durable asset records, and customer-facing information that stays useful.', 'Become a partner', '/partner-program'],
            ['installation-and-maintenance', 'Charger installation and maintenance', 'default', 'From site readiness to reliable field service.', 'Coordinate equipment records, commissioning information, preventive work, and service history without losing the operational thread.', 'Discuss a project', '/contact'],
            ['mobile-app', 'Mobile app', 'app', 'Your charging companion, without the dashboard clutter.', 'Discover stations, understand connector options, and keep essential charging information close at hand.', null, null],
            ['news-and-resources', 'News and resources', 'articles', 'Practical notes for a changing charging landscape.', 'Read platform updates, operating guidance, and plain-language resources for drivers and partners.', null, null],
            ['partner-program', 'Partner program', 'default', 'Put a well-run charging site on the map.', 'Work with Power Solutions to connect operators, site hosts, installers, and service teams around a shared operating foundation.', 'Start a business inquiry', '/contact'],
            ['frequently-asked-questions', 'Frequently asked questions', 'faq', 'Answers before you need to ask.', 'Browse common questions about finding stations, compatibility, operating sites, support, and the mobile experience.', null, null],
            ['contact', 'Contact and business inquiry', 'contact', 'Tell us what you are trying to make possible.', 'Share enough context for the right team to understand your question. Do not include payment credentials or other sensitive information.', null, null],
            ['support', 'Support', 'support', 'Start with the station, session, or account detail you can safely share.', 'Use the support channel shown in your app or on-site materials. Never send a password, access token, card number, or CVV.', null, null],
            ['privacy-policy', 'Privacy policy', 'legal', 'Privacy policy', 'This publication area is prepared for approved privacy language and data-handling notices.', null, null],
            ['terms-of-service', 'Terms of service', 'legal', 'Terms of service', 'This publication area is prepared for approved platform terms and conditions.', null, null],
            ['charging-terms', 'Charging terms', 'legal', 'Charging terms', 'This publication area is prepared for approved terms that apply to charging use and sessions.', null, null],
            ['refund-policy', 'Refund policy', 'legal', 'Refund policy placeholder', 'This page is a clearly marked placeholder. Refund rules, eligibility, timing, evidence requirements, and market-specific consumer rights require legal and payment-operations review before publication as binding terms.', null, null, true],
        ];

        foreach ($pages as $definition) {
            [$slug, $title, $template, $heading, $summary, $actionLabel, $actionUrl] = $definition;
            $page = CmsPage::query()->updateOrCreate(['slug' => $slug], [
                'title' => $title,
                'eyebrow' => $this->eyebrow($slug),
                'summary' => $summary,
                'hero_heading' => $heading,
                'hero_copy' => $summary,
                'template' => $template,
                'status' => 'published',
                'primary_action_label' => $actionLabel,
                'primary_action_url' => $actionUrl,
                'legal_review_required' => $definition[7] ?? false,
                'published_at' => now('UTC'),
            ]);
            SeoMetadata::query()->updateOrCreate(['target_type' => 'page', 'target_id' => $page->getKey()], [
                'meta_title' => $title.' | Power Solutions',
                'meta_description' => mb_substr($summary, 0, 180),
                'social_image_url' => '/branding/power-solutions-social-card.svg',
                'noindex' => ($definition[7] ?? false) === true,
            ]);
        }

        $home = CmsPage::query()->where('slug', 'home')->sole();
        CmsSection::query()->where('cms_page_id', $home->getKey())->delete();
        foreach ([
            ['signal', 'Know what the station is telling you.', 'Availability, connector, power, operating hours, and freshness are presented as distinct signals—not collapsed into one vague pin.', ['Status that names uncertainty', 'Accessible list and map together', 'Filters that reflect real charging choices']],
            ['network', 'Useful infrastructure has a human context.', 'A charging point belongs to a place. The public experience keeps directions, amenities, parking context, and operating hours close to the equipment details.', ['Place before pin', 'Clear operator identity', 'Practical arrival information']],
            ['partner', 'Build the network with operational memory.', 'Site hosts and operators get a durable foundation for assets, service relationships, and public information without turning every task into another disconnected spreadsheet.', ['Designed for multiple operators', 'Maintenance-ready asset history', 'Publishing controls with review states']],
        ] as $order => [$eyebrow, $heading, $copy, $items]) {
            CmsSection::query()->create(['cms_page_id' => $home->getKey(), 'kind' => 'feature', 'eyebrow' => $eyebrow, 'heading' => $heading, 'copy' => $copy, 'items' => $items, 'sort_order' => $order, 'is_enabled' => true]);
        }

        foreach ([
            ['drivers', 'How do I know whether station information is current?', 'Each map result includes a freshness signal. A stale or unknown status is shown honestly instead of being presented as live availability.'],
            ['drivers', 'Can I search without using the interactive map?', 'Yes. The synchronized station list is a complete keyboard- and screen-reader-friendly fallback.'],
            ['compatibility', 'How should I choose a connector?', 'Confirm the connector standard supported by your vehicle and check the station power details. Your vehicle controls the maximum power it can accept.'],
            ['operators', 'Can site-host users see every operator site?', 'No. Access remains tenant- and site-scoped in the operator platform, independent of what appears publicly.'],
            ['support', 'What should I include in a support request?', 'Share the station or connector identifier, approximate UTC or local time, and a safe description. Never include passwords, tokens, full card numbers, or CVV.'],
        ] as $order => [$category, $question, $answer]) {
            Faq::query()->updateOrCreate(['question' => $question], ['category' => $category, 'answer' => $answer, 'sort_order' => $order, 'is_published' => true]);
        }

        AppStoreLink::query()->updateOrCreate(['store' => 'apple'], ['label' => 'Download on the App Store', 'url' => null, 'is_published' => false]);
        AppStoreLink::query()->updateOrCreate(['store' => 'google'], ['label' => 'Get it on Google Play', 'url' => null, 'is_published' => false]);
    }

    private function eyebrow(string $slug): string
    {
        return match ($slug) {
            'home' => 'A clearer charging network',
            'charging-map' => 'Live network view',
            'refund-policy' => 'Legal review required',
            default => 'Power Solutions',
        };
    }
}
