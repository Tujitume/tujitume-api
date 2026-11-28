<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use App\Models\Services\ServiceCategory;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Twenty live services from one KYC-verified service provider, so Explore services, a service's details and booking
 * can be tried from any dashboard (program organisations included).
 *
 * Each service gets its milestones first and is then made active, as the app's own Activate does (a service needs at
 * least one milestone). Running it again updates the same twenty services (matched by provider and name); nothing is
 * duplicated and nothing else is removed.
 *
 *   php artisan db:seed --class=VerifiedProviderServicesSeeder
 *   SEED_PROVIDER_ID=10 php artisan db:seed --class=VerifiedProviderServicesSeeder   (a chosen provider)
 */
class VerifiedProviderServicesSeeder extends Seeder
{
    /** The categories the add-service form offers (saved by name), as ServiceCategorySeeder lists them. */
    private const CATEGORIES = [
        ['name' => 'Business Planning', 'value' => 'business_planning'],
        ['name' => 'IT', 'value' => 'it'],
        ['name' => 'Legal', 'value' => 'legal'],
        ['name' => 'Project Management', 'value' => 'project_management'],
        ['name' => 'Branding and Design', 'value' => 'branding_and_design'],
        ['name' => 'Finance, Accounting & Tax', 'value' => 'finance_accounting_tax'],
        ['name' => 'Marketing', 'value' => 'marketing'],
        ['name' => 'Public Relations', 'value' => 'public_relations'],
        ['name' => 'Business Coach', 'value' => 'business_coaching'],
        ['name' => 'Other', 'value' => 'other'],
    ];

    /** Where the services are delivered from: [location, lat, lng]. */
    private const PLACES = [
        ['Westlands, Nairobi, Kenya', '-1.2676', '36.8108'],
        ['Kilimani, Nairobi, Kenya', '-1.2921', '36.7836'],
        ['Upper Hill, Nairobi, Kenya', '-1.2986', '36.8152'],
        ['Nyali, Mombasa, Kenya', '-4.0435', '39.7066'],
        ['Milimani, Kisumu, Kenya', '-0.1022', '34.7617'],
        ['Nakuru Town, Nakuru, Kenya', '-0.3031', '36.0800'],
        ['Eldoret Town, Uasin Gishu, Kenya', '0.5143', '35.2698'],
        ['Thika Town, Kiambu, Kenya', '-1.0333', '37.0693'],
    ];

    /**
     * [name, category, price, sectors, impact areas, details, milestones [title, share of price %, days]].
     * The milestone shares of each service add up to 100.
     */
    private const SERVICES = [
        ['Investor-Ready Business Plan', 'Business Planning', 45000, ['Agriculture', 'Retail', 'Manufacturing'], ['Youth-led', 'Local-sourcing'],
            'A full business plan with market analysis, a five-year financial model and a funding ask, written to the format grant funders and impact investors expect.',
            [['Discovery session and data collection', 20, 5], ['Market and competitor analysis', 30, 7], ['Financial model and final plan', 50, 10]]],
        ['Grant Proposal Writing', 'Business Planning', 35000, ['Agriculture', 'Education', 'Health'], ['Gender-led', 'Youth-led'],
            'We write your grant application end to end: the problem statement, theory of change, budget narrative and M&E framework, matched to the funder\'s criteria.',
            [['Funder criteria review and outline', 25, 3], ['First full draft', 45, 7], ['Revisions and submission pack', 30, 4]]],
        ['Bookkeeping & Monthly Accounts', 'Finance, Accounting & Tax', 12000, ['Retail', 'Hospitality', 'Services'], ['Local-sourcing'],
            'Monthly bookkeeping on QuickBooks or Zoho Books, bank reconciliations and a one-page management report you can share with funders.',
            [['Set up chart of accounts', 30, 3], ['First month books and reconciliation', 70, 10]]],
        ['KRA Tax Compliance & Filing', 'Finance, Accounting & Tax', 9500, ['Retail', 'Manufacturing', 'Services'], ['Diversity'],
            'iTax registration checks, monthly VAT and PAYE filing, annual returns and a tax compliance certificate for tenders and grants.',
            [['Compliance health check', 40, 2], ['Filing and compliance certificate', 60, 5]]],
        ['Audit-Ready Financial Statements', 'Finance, Accounting & Tax', 60000, ['Agriculture', 'Manufacturing', 'Education'], ['Local-sourcing', 'Gender-led'],
            'Year-end financial statements prepared to IFRS for SMEs, with the schedules auditors and grant programmes ask for at due diligence.',
            [['Trial balance and adjustments', 35, 7], ['Draft statements', 40, 7], ['Final statements and notes', 25, 4]]],
        ['Company Registration & Compliance', 'Legal', 18000, ['Retail', 'Technology', 'Services'], ['Youth-led'],
            'Business name search, company registration on eCitizen, CR12, KRA PIN and the first-year compliance calendar.',
            [['Name search and documents', 40, 3], ['Registration and certificates', 60, 7]]],
        ['Contracts & Supplier Agreements', 'Legal', 25000, ['Manufacturing', 'Agriculture', 'Retail'], ['Local-sourcing'],
            'Plain-language supply, distribution and service agreements drafted or reviewed by an advocate, so you can sign with suppliers and buyers with confidence.',
            [['Review of current agreements', 30, 4], ['Drafted agreements', 70, 8]]],
        ['Trademark Registration (KIPI)', 'Legal', 22000, ['Technology', 'Retail', 'Hospitality'], ['Diversity'],
            'Trademark search and filing with the Kenya Industrial Property Institute, follow-up on objections and the registration certificate.',
            [['Search and filing', 50, 5], ['Follow-up to registration', 50, 30]]],
        ['Website Design & Build', 'IT', 55000, ['Retail', 'Hospitality', 'Education'], ['Youth-led', 'Gender-led'],
            'A fast, mobile-first website with up to eight pages, contact and enquiry forms, Google Business setup and one month of support.',
            [['Design mock-ups', 30, 7], ['Build and content', 50, 14], ['Launch and handover', 20, 3]]],
        ['M-Pesa & Card Payments Integration', 'IT', 40000, ['Retail', 'Technology', 'Hospitality'], ['Local-sourcing'],
            'M-Pesa Daraja (STK push, C2B, B2C) and card payments built into your website or app, with test runs and go-live with Safaricom.',
            [['Daraja setup and sandbox', 35, 5], ['Integration and testing', 45, 10], ['Go-live', 20, 3]]],
        ['Inventory & POS System Setup', 'IT', 30000, ['Retail', 'Hospitality', 'Agriculture'], ['Local-sourcing'],
            'Point-of-sale and stock tracking set up on your devices, products and prices loaded, staff trained and daily sales reports switched on.',
            [['Requirements and device check', 25, 3], ['Setup and data load', 50, 7], ['Staff training', 25, 2]]],
        ['Brand Identity Package', 'Branding and Design', 28000, ['Retail', 'Hospitality', 'Agriculture'], ['Gender-led', 'Youth-led'],
            'Logo, colour palette, typography and a short brand guide, plus business cards and social media templates ready to use.',
            [['Brand workshop and concepts', 40, 5], ['Final identity and brand guide', 60, 7]]],
        ['Product Packaging Design', 'Branding and Design', 32000, ['Manufacturing', 'Agriculture', 'Retail'], ['Local-sourcing'],
            'Packaging and labels that meet KEBS labelling rules, designed for supermarket shelves and print-ready for local printers.',
            [['Concepts and label content', 40, 6], ['Print-ready artwork', 60, 6]]],
        ['Digital Marketing Campaign', 'Marketing', 38000, ['Retail', 'Hospitality', 'Education'], ['Youth-led'],
            'A four-week campaign on Facebook, Instagram and TikTok: content calendar, ad setup, budget management and a results report.',
            [['Strategy and content calendar', 30, 4], ['Campaign run', 50, 28], ['Results report', 20, 3]]],
        ['Market Research & Customer Survey', 'Marketing', 42000, ['Agriculture', 'Retail', 'Health'], ['Gender-led', 'Diversity'],
            'Field and phone surveys with your target customers, focus groups and a report on demand, pricing and the channels that reach them.',
            [['Survey design', 25, 4], ['Data collection', 45, 14], ['Findings report', 30, 5]]],
        ['Media & Press Launch', 'Public Relations', 30000, ['Technology', 'Hospitality', 'Education'], ['Youth-led', 'Gender-led'],
            'Press release, media list and outreach to Kenyan business and tech media, with interview preparation for your launch.',
            [['Story and press release', 40, 4], ['Media outreach and coverage report', 60, 10]]],
        ['Project Management for Funded Projects', 'Project Management', 50000, ['Agriculture', 'Health', 'Education'], ['Local-sourcing', 'Diversity'],
            'A project manager to run your grant-funded project: work plan, milestone tracking, supplier follow-up and monthly progress reports for the funder.',
            [['Work plan and set-up', 20, 5], ['Month one delivery and report', 40, 30], ['Month two delivery and report', 40, 30]]],
        ['Monitoring & Evaluation Framework', 'Project Management', 35000, ['Agriculture', 'Health', 'Education'], ['Gender-led', 'Diversity'],
            'KPIs, baseline data, data collection tools and a reporting template so you can show funders the impact of their money.',
            [['Indicators and baseline', 45, 7], ['Tools and reporting template', 55, 7]]],
        ['One-on-One Business Coaching', 'Business Coach', 15000, ['Retail', 'Services', 'Agriculture'], ['Youth-led', 'Gender-led'],
            'Six coaching sessions with an experienced SME coach on cash flow, pricing, sales and growing your team, with an action plan after each session.',
            [['Sessions one to three', 50, 21], ['Sessions four to six', 50, 21]]],
        ['Export Readiness & Certification', 'Other', 48000, ['Agriculture', 'Manufacturing'], ['Local-sourcing', 'Gender-led'],
            'Guidance through KEBS, KEPHIS and export documentation, buyer requirements and a readiness plan for regional and EU markets.',
            [['Readiness assessment', 30, 5], ['Certification support', 50, 21], ['Export plan', 20, 5]]],
    ];

    public function run(): void
    {
        $provider = $this->provider();

        // The category names the services use must exist for the add-service form and filters (missing ones only)
        foreach (self::CATEGORIES as $category) {
            if (! ServiceCategory::where('value', $category['value'])->exists()) {
                ServiceCategory::insert($category);
            }
        }

        DB::transaction(function () use ($provider) {
            foreach (self::SERVICES as $i => [$name, $category, $price, $sectors, $impact, $details, $milestones]) {
                [$location, $lat, $lng] = self::PLACES[$i % count(self::PLACES)];

                $service = Services::updateOrCreate(
                    ['user_id' => $provider->id, 'name' => $name],
                    [
                        'image'                 => 'https://picsum.photos/seed/tujitume-service-'.($i + 1).'/600/400',
                        'price'                 => $price,
                        'category'              => $category,
                        'business_sector_focus' => $sectors,
                        'social_impact_areas'   => $impact,
                        'details'               => $details,
                        'location'              => $location,
                        'lat'                   => $lat,
                        'lng'                   => $lng,
                        // Ratings as reviews would leave them (1 to 5), with a believable count
                        'rating'                => round(3.8 + (($i * 37) % 12) / 10, 1),
                        'rating_count'          => 4 + (($i * 13) % 40),
                    ]
                );

                // Milestones first (the app will not activate a service without one); amounts add up to the price.
                // Updated in place (by title), so a booking's milestones (mile_id) keep pointing at them on a re-run
                $left = $price;
                foreach ($milestones as $n => [$title, $share, $days]) {
                    $amount = $n === count($milestones) - 1 ? $left : (int) round($price * $share / 100);
                    $left -= $amount;
                    Smilestones::updateOrCreate(
                        ['listing_id' => $service->id, 'title' => $title],
                        ['user_id' => $provider->id, 'amount' => $amount, 'n_o_days' => $days]
                    );
                }
                Smilestones::where('listing_id', $service->id)->whereNotIn('title', array_column($milestones, 0))->delete();

                $service->update(['active' => 1]);
            }
        });

        $this->command?->info('Seeded '.count(self::SERVICES).' active services for '.$provider->first_name.' '.$provider->last_name.' (user '.$provider->id.').');
    }

    /** The chosen provider (SEED_PROVIDER_ID) or the first service provider whose KYC is verified. */
    private function provider(): User
    {
        $verified = User::where('user_type_id', 3)
            ->whereHas('kycVerification', fn ($q) => $q->where('status', 'verified'));

        $chosen = env('SEED_PROVIDER_ID');
        $provider = $chosen ? (clone $verified)->find((int) $chosen) : $verified->orderBy('id')->first();

        if (! $provider) {
            throw new RuntimeException($chosen
                ? "User {$chosen} is not a service provider with verified KYC."
                : 'No service provider with verified KYC was found. Verify one first, or pass SEED_PROVIDER_ID.');
        }

        return $provider;
    }
}
