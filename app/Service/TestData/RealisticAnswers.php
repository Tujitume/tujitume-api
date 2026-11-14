<?php

namespace App\Service\TestData;

use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Service\AiReview\ScoreGuard;
use Illuminate\Support\Str;

/**
 * Answers for the test-data generator that follow the round's OWN questions (the custom process the owner set up), not one block of filler for
 * everything: a name question gets a name, an email question an email, a phone question a Kenyan number, a choice question one of its options, a
 * number question a number, a file question a small readable PDF. Most answers are good, some are weak or wrong on purpose (a webmail where a
 * company email is asked, a number with too many digits, a one-line answer), so the AI and the scoring have real differences to work with.
 */
class RealisticAnswers
{
    private const FIRST = ['Amina', 'Brian', 'Cynthia', 'David', 'Esther', 'Felix', 'Grace', 'Hassan', 'Irene', 'James', 'Kevin', 'Linet', 'Mercy', 'Noah', 'Wanjiru', 'Otieno', 'Fatuma', 'Peter'];
    private const LAST = ['Otieno', 'Mwangi', 'Njoroge', 'Kamau', 'Achieng', 'Mohamed', 'Wafula', 'Chebet', 'Kiplagat', 'Odhiambo', 'Naliaka', 'Mutiso', 'Abdi', 'Wekesa'];

    private const TOPICS = [
        'team' => [
            'Our founding team has {y} years of combined experience in {s}. {n} leads operations and previously managed a team of 15 at a regional distributor; our technical lead holds a degree in the field and has shipped three products. We work on this full time and are available for the whole program.',
            'Two co-founders with backgrounds in {s} and finance. We have worked together for {y} years and meet weekly with our advisors.',
        ],
        'product' => [
            'We sell {p} to small retailers and households in Nairobi, Kisumu and Mombasa. The product is live, we have 120 repeat customers, and we ship within 48 hours. The next version adds mobile payments and delivery tracking.',
            'Our product is in pilot with 15 customers. It works, but we still do manual work behind the scenes and need support to automate it.',
        ],
        'problem' => [
            'Small {s} businesses in our area lose about 30% of their income to waste and late payments. We started after losing stock ourselves, and we have spoken to more than 60 owners to confirm the problem before building anything.',
            'Many people cannot get reliable {s} services nearby. We want to fix that.',
        ],
        'market' => [
            'There are roughly 40,000 small businesses in our target counties, and the average customer spends KES 3,500 a month on this. We aim at 2,000 customers within two years, which is under 5% of the market.',
            'The market is large and growing.',
        ],
        'competition' => [
            'Our main competitors are two larger firms and informal sellers. We are cheaper by 15%, deliver faster and offer credit terms they do not. Our local relationships are hard to copy.',
            'We have few competitors.',
        ],
        'traction' => [
            'We reached KES 1.2 million in sales over the last 12 months, grew 18% month on month in the last quarter, and have 120 active customers with 70% coming back each month. We spend about KES 400 to win a customer.',
            'We have some customers and growing interest.',
        ],
        'finance' => [
            'Revenue for the last 12 months was KES 1.2 million with a 28% margin. Costs are mainly stock and delivery. We broke even in month 7 and keep three months of costs in reserve. We request KES 1 million for stock and one vehicle.',
            'We make some profit and want a grant to grow.',
        ],
        'legal' => [
            'We are registered as a private limited company with the Registrar of Companies. The two founders hold 50% each, and there is a signed shareholders agreement. We pay taxes and hold a county business permit.',
            'We are registered. Equity is shared between the founders.',
        ],
        'default' => [
            'We are a {s} business based in {l}. We started {y} years ago, serve local customers every day, and plan to use the program to grow our team and improve how we work.',
            'We run a small {s} business and want to grow it.',
        ],
    ];

    public function __construct(private int $index = 0)
    {
    }

    /** @return array{response:?string, file_path:?string} */
    public function forQuestion(RoundCustomQuestion $q, string $businessName, string $personName, string $sector = 'retail', string $location = 'Nairobi'): array
    {
        $type = (string) $q->question_type;
        $text = (string) $q->question_text;
        $kind = ScoreGuard::kind($text);
        $slug = Str::slug($businessName) ?: 'business';
        $good = mt_rand(1, 100) <= 85; // most answers are fine

        // What the question is about, from its words, beats its type: an "Email" question can be a short-answer type
        if ($type === 'email' || $kind === 'email') return $this->plain($this->email($text, $slug, $personName, $good));
        if ($type === 'phone' || $kind === 'phone') return $this->plain($this->phone($good));
        if ($kind === 'name') return $this->plain($this->name($good, $personName));

        switch ($type) {
            case 'file_upload':
                return ['response' => null, 'file_path' => $this->pdf($q, $businessName, $personName, $sector, $good)];
            case 'yes_no':
                return $this->plain(mt_rand(1, 100) <= 75 ? 'yes' : 'no');
            case 'true_false':
                return $this->plain(mt_rand(1, 100) <= 75 ? 'true' : 'false');
            case 'multiple_choice':
            case 'dropdown':
                return $this->plain($this->option($q) ?? 'Yes');
            case 'checkbox':
                $options = $this->options($q);
                shuffle($options);

                return $this->plain(json_encode(array_slice($options, 0, max(1, min(2, count($options))))));
            case 'ranking':
                $options = $this->options($q);
                shuffle($options);

                return $this->plain(json_encode($options));
            case 'rating':
                return $this->plain((string) mt_rand(2, 5));
            case 'linear_scale':
                return $this->plain((string) mt_rand(3, 10));
            case 'percentage':
                return $this->plain((string) mt_rand(5, 90));
            case 'currency':
            case 'number':
                return $this->plain((string) (preg_match('/year|age|month|employee|staff|team|people|customer/i', $text) ? mt_rand(1, 40) : mt_rand(50, 500) * 1000));
            case 'date':
                return $this->plain(now()->subDays(mt_rand(30, 1500))->format('Y-m-d'));
            case 'url':
                return $this->plain("https://www.{$slug}.co.ke");
            case 'location':
                return $this->plain(json_encode(['region' => 'East Africa', 'country' => 'Kenya', 'specific_location' => $location]));
            case 'signature':
                return $this->plain($personName);
        }

        // Written answers: about what the question asks, sometimes thin on purpose
        return $this->plain($this->paragraph($text, $sector, $location, $personName, $good, $type === 'short_answer'));
    }

    /** A small PDF with real text (what a CV, plan or certificate would say), written to the public files folder like the generator's other uploads. */
    public function makePdfFile(int $programId, int $roundId, string $label, array $lines): string
    {
        $dir = "files/programApps/{$programId}/rounds/{$roundId}";
        if (! file_exists(public_path($dir))) mkdir(public_path($dir), 0777, true);
        $path = $dir.'/'.Str::slug($label).'_'.Str::random(10).'.pdf';
        file_put_contents(public_path($path), self::textPdf($lines));

        return $path;
    }

    public static function textPdf(array $lines): string
    {
        $wrapped = [];
        foreach ($lines as $line) {
            foreach (explode("\n", wordwrap((string) $line, 90, "\n", true)) as $w) $wrapped[] = $w;
        }
        $stream = "BT\n/F1 11 Tf\n72 740 Td\n15 TL\n";
        foreach ($wrapped as $w) $stream .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $w).") Tj\nT*\n";
        $stream .= 'ET';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            4 => '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[$i] = strlen($pdf);
            $pdf .= "{$i} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) $pdf .= sprintf("%010d 00000 n \n", $o);

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    // ── pieces ────────────────────────────────────────────────────────────────

    private function plain(string $value): array
    {
        return ['response' => $value, 'file_path' => null];
    }

    private function name(bool $good, string $personName): string
    {
        if (! $good) return ['Owen', 'N/A', 'test test', 'Name'][mt_rand(0, 3)];

        return self::FIRST[mt_rand(0, count(self::FIRST) - 1)].' '.self::LAST[mt_rand(0, count(self::LAST) - 1)];
    }

    private function email(string $question, string $slug, string $personName, bool $good): string
    {
        $company = (bool) preg_match('/company|business|official|work|corporate/i', $question);
        $local = Str::slug(explode(' ', trim($personName))[0] ?: 'info') ?: 'info';
        if (! $good) return ['not an email', 'test@test', 'n/a'][mt_rand(0, 2)];
        // A company email is usually on the company's own domain, but now and then a webmail is given
        if ($company) return mt_rand(1, 100) <= 80 ? "{$local}@{$slug}.co.ke" : "{$local}{$this->index}@gmail.com";

        return "{$local}{$this->index}@".['gmail.com', 'yahoo.com', 'outlook.com'][mt_rand(0, 2)];
    }

    private function phone(bool $good): string
    {
        $mobile = (mt_rand(0, 1) ? '7' : '1').mt_rand(10000000, 99999999);
        if (! $good) return '254'.$mobile.mt_rand(100, 999); // too many digits
        $roll = mt_rand(1, 100);

        return $roll <= 60 ? "+254{$mobile}" : ($roll <= 85 ? "254{$mobile}" : "0{$mobile}");
    }

    /** @return string[] */
    private function options(RoundCustomQuestion $q): array
    {
        return collect($q->options ?? [])->map(fn ($o) => is_array($o) ? (string) ($o['value'] ?? $o['label'] ?? '') : (string) $o)
            ->reject(fn ($o) => $o === '' || str_contains($o, ':'))->values()->all();
    }

    private function option(RoundCustomQuestion $q): ?string
    {
        $options = $this->options($q);

        return $options ? $options[mt_rand(0, count($options) - 1)] : null;
    }

    private function topic(string $question): string
    {
        $map = [
            'team' => '/team|founder|experience|background|commitment|availability|qualif/i', 'product' => '/product|service|solution|offer|demo/i',
            'problem' => '/problem|need|pain/i', 'market' => '/market|customer segment|size|opportunity/i', 'competition' => '/compet|advantage|differen/i',
            'traction' => '/traction|sales|growth|acquisition|customers|users/i', 'finance' => '/financ|revenue|profit|cost|funding|budget|margin/i',
            'legal' => '/legal|equity|registr|ownership|incorporat|shareholder/i',
        ];
        foreach ($map as $topic => $pattern) if (preg_match($pattern, $question)) return $topic;

        return 'default';
    }

    private function paragraph(string $question, string $sector, string $location, string $personName, bool $good, bool $short): string
    {
        $pool = self::TOPICS[$this->topic($question)];
        $template = $good ? $pool[0] : ($pool[1] ?? $pool[0]);
        $text = strtr($template, ['{y}' => (string) mt_rand(3, 12), '{s}' => strtolower($sector), '{n}' => explode(' ', trim($personName))[0] ?: 'Our lead', '{p}' => strtolower($sector).' products', '{l}' => $location]);

        return $short ? Str::limit($text, 160, '') : $text;
    }

    private function pdf(RoundCustomQuestion $q, string $businessName, string $personName, string $sector, bool $good): string
    {
        $isCv = (bool) preg_match('/cv|resume|curriculum/i', $q->question_text);
        $lines = $isCv
            ? ($good
                ? ["CURRICULUM VITAE — {$personName}", "Business: {$businessName}", '', 'Profile', "Founder and operations lead with ".mt_rand(4, 12)." years in {$sector}. Built and ran a business from a market stall to ".mt_rand(5, 20)." employees.", '', 'Experience', '2021 - present  Founder, '.$businessName.': sales, hiring and supplier relationships.', '2017 - 2021  Operations manager at a regional distributor: managed 15 staff and a KES 40M budget.', '', 'Education', 'Bachelor of Commerce, University of Nairobi', '', 'Skills', 'Budgeting, customer service, supplier negotiation, team leadership.']
                : ['SAMPLE CV', 'This is a fictional document for demonstration purposes.', 'Name: [your name here]', 'Email: jane@example.com'])
            : ["{$businessName} — supporting document", '', "Prepared by {$personName}.", "Overview of our {$sector} business: customers, how we make money and our plan for the next year.", 'Monthly sales average KES '.mt_rand(80, 400).',000. We serve '.mt_rand(40, 300).' regular customers.'];

        return $this->makePdfFile((int) $q->round->program_id, (int) $q->round_id, $isCv ? 'cv' : 'document', $lines);
    }
}
