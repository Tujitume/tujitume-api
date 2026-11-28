<?php

namespace App\Service\Notification;

/**
 * The four applicant emails a program owner may write themselves (ProgramEmailTemplate::CUSTOMISABLE_EVENTS),
 * as the system sends them when the owner writes nothing: subject, heading, body and button, in English and
 * Kiswahili. The program's language (programs.email_locale) picks the version, and it also sets the words
 * around a body the owner wrote (heading, button, footer), so an email written in Kiswahili goes out
 * entirely in Kiswahili.
 *
 * Bodies and subjects use {{tokens}}; render() and renderSubject() fill them in per recipient.
 */
class ProgramEmailDefaults
{
    public const LOCALES = ['en', 'sw'];

    /** The tokens each email can use, in the order the editor offers them. */
    public const VARIABLES = [
        'round.advanced' => ['applicant_name', 'program_title', 'round_name', 'next_steps_url'],
        'round.not_selected' => ['applicant_name', 'program_title', 'round_name'],
        'application.accepted' => ['applicant_name', 'program_title', 'award_amount', 'dashboard_url'],
        'application.rejected' => ['applicant_name', 'program_title'],
    ];

    private const EMAILS = [
        'round.advanced' => [
            'en' => [
                'subject' => "You're through to {{round_name}} of {{program_title}}",
                'heading' => 'You advanced to the next round',
                'body_html' => '<p>Hi {{applicant_name}},</p>'
                    . '<p>Good news: your application to <strong>{{program_title}}</strong> has moved on to <strong>{{round_name}}</strong>.</p>'
                    . '<p>Open your application to see what this round asks for, and complete it before the deadline.</p>',
                'button_label' => 'Open my application',
            ],
            'sw' => [
                'subject' => 'Umefuzu kuingia {{round_name}} ya {{program_title}}',
                'heading' => 'Umefuzu kuingia raundi inayofuata',
                'body_html' => '<p>Habari {{applicant_name}},</p>'
                    . '<p>Habari njema: ombi lako kwa <strong>{{program_title}}</strong> limefuzu kuingia <strong>{{round_name}}</strong>.</p>'
                    . '<p>Fungua ombi lako uone kile raundi hii inahitaji, kisha ulikamilishe kabla ya tarehe ya mwisho.</p>',
                'button_label' => 'Fungua ombi langu',
            ],
        ],
        'round.not_selected' => [
            'en' => [
                'subject' => 'An update on your application to {{program_title}}',
                'heading' => 'Round update',
                'body_html' => '<p>Hi {{applicant_name}},</p>'
                    . '<p>Thank you for taking part in <strong>{{round_name}}</strong> of <strong>{{program_title}}</strong>.</p>'
                    . '<p>After reviewing every application, we are sorry to tell you that yours was not selected to continue this time.</p>'
                    . '<p>We appreciate the time and effort you put in, and we encourage you to look at other programs that fit your business.</p>',
                'button_label' => 'Browse programs',
            ],
            'sw' => [
                'subject' => 'Taarifa kuhusu ombi lako kwa {{program_title}}',
                'heading' => 'Taarifa ya raundi',
                'body_html' => '<p>Habari {{applicant_name}},</p>'
                    . '<p>Asante kwa kushiriki katika <strong>{{round_name}}</strong> ya <strong>{{program_title}}</strong>.</p>'
                    . '<p>Baada ya kukagua maombi yote, tunasikitika kukujulisha kuwa ombi lako halikuchaguliwa kuendelea wakati huu.</p>'
                    . '<p>Tunathamini muda na juhudi ulizoweka, na tunakuhimiza uangalie programu nyingine zinazofaa biashara yako.</p>',
                'button_label' => 'Tazama programu',
            ],
        ],
        'application.accepted' => [
            'en' => [
                'subject' => 'Congratulations: your application to {{program_title}} was accepted',
                'heading' => 'Application accepted',
                'body_html' => '<p>Hi {{applicant_name}},</p>'
                    . '<p>Congratulations! Your application to <strong>{{program_title}}</strong> has been accepted.</p>'
                    . '<p>Open your deal room to set up your funding plan and see the next steps.</p>',
                'button_label' => 'Open my deal room',
            ],
            'sw' => [
                'subject' => 'Hongera: ombi lako kwa {{program_title}} limekubaliwa',
                'heading' => 'Ombi limekubaliwa',
                'body_html' => '<p>Habari {{applicant_name}},</p>'
                    . '<p>Hongera! Ombi lako kwa <strong>{{program_title}}</strong> limekubaliwa.</p>'
                    . '<p>Fungua Chumba chako cha Mikataba uandae mpango wako wa ufadhili na kuona hatua zinazofuata.</p>',
                'button_label' => 'Fungua Chumba cha Mikataba',
            ],
        ],
        'application.rejected' => [
            'en' => [
                'subject' => 'An update on your application to {{program_title}}',
                'heading' => 'Application update',
                'body_html' => '<p>Hi {{applicant_name}},</p>'
                    . '<p>Thank you for applying to <strong>{{program_title}}</strong>.</p>'
                    . '<p>After careful review, we are sorry to tell you that your application was not selected this time.</p>'
                    . '<p>We appreciate your effort, and we encourage you to apply to other programs that fit your business.</p>',
                'button_label' => 'Browse programs',
            ],
            'sw' => [
                'subject' => 'Taarifa kuhusu ombi lako kwa {{program_title}}',
                'heading' => 'Taarifa ya ombi',
                'body_html' => '<p>Habari {{applicant_name}},</p>'
                    . '<p>Asante kwa kutuma ombi kwa <strong>{{program_title}}</strong>.</p>'
                    . '<p>Baada ya ukaguzi wa makini, tunasikitika kukujulisha kuwa ombi lako halikuchaguliwa wakati huu.</p>'
                    . '<p>Tunathamini juhudi zako, na tunakuhimiza utume ombi kwa programu nyingine zinazofaa biashara yako.</p>',
                'button_label' => 'Tazama programu',
            ],
        ],
    ];

    /** The words every program email carries around its body (footer and button note). :name is the brand. */
    private const CHROME = [
        'en' => [
            'regards' => 'Best regards,',
            'team' => 'The :name Team',
            'reason' => 'You are receiving this email because of your activity on :name. Please do not reply directly to this message.',
            'sign_in' => "You may be asked to sign in first \u{2014} you'll be taken straight to the right place afterwards.",
        ],
        'sw' => [
            'regards' => 'Wako,',
            'team' => 'Timu ya :name',
            'reason' => 'Unapokea barua pepe hii kwa sababu ya shughuli zako kwenye :name. Tafadhali usijibu ujumbe huu moja kwa moja.',
            'sign_in' => "Huenda ukaombwa kuingia kwanza \u{2014} utapelekwa moja kwa moja mahali sahihi baadaye.",
        ],
    ];

    /** What a token becomes when its value is not known, so a sentence never reads "Hi ," or "of ." */
    private const FALLBACKS = [
        'en' => ['applicant_name' => 'there', 'program_title' => 'the program', 'round_name' => 'the round'],
        'sw' => ['applicant_name' => 'rafiki', 'program_title' => 'programu', 'round_name' => 'raundi'],
    ];

    /** Tags an owner-written body may keep. Everything else (scripts, styles, forms, iframes) is removed. */
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><s><a><ul><ol><li><h1><h2><h3><h4><blockquote><span><div><hr>';

    public static function locale(?string $locale): string
    {
        $locale = strtolower(substr((string) $locale, 0, 2));

        return in_array($locale, self::LOCALES, true) ? $locale : 'en';
    }

    public static function events(): array
    {
        return array_keys(self::EMAILS);
    }

    /** ['subject', 'heading', 'body_html', 'button_label'] for one email in one language. */
    public static function forEvent(string $event, ?string $locale): array
    {
        return self::EMAILS[$event][self::locale($locale)] ?? self::EMAILS[$event]['en'] ?? [];
    }

    public static function chrome(?string $locale, string $brandName): array
    {
        return array_map(
            fn (string $text) => str_replace(':name', $brandName, $text),
            self::CHROME[self::locale($locale)]
        );
    }

    /** Fill the tokens of a body. Values are escaped: only the owner's own markup is HTML. */
    public static function render(string $html, array $values, ?string $locale): string
    {
        $values = self::withFallbacks($values, $locale);
        $html = str_replace('{{grant_title}}', '{{program_title}}', $html);

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => e((string) ($values[$m[1]] ?? '')), $html);
    }

    /** Fill the tokens of a subject line (plain text). */
    public static function renderSubject(string $subject, array $values, ?string $locale): string
    {
        $values = self::withFallbacks($values, $locale);
        $subject = str_replace('{{grant_title}}', '{{program_title}}', $subject);
        $text = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => (string) ($values[$m[1]] ?? ''), $subject);

        return trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    }

    /**
     * Clean a body the owner wrote before it is stored: only formatting tags stay, and no attribute can run
     * code (on* handlers, javascript: links, inline expressions).
     */
    public static function sanitize(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>#is', '', $html);
        $html = strip_tags($html, self::ALLOWED_TAGS);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/(href|src)\s*=\s*(["\']?)\s*(javascript|vbscript|data):[^"\'\s>]*\2/i', '$1="#"', $html);
        $html = preg_replace('/style\s*=\s*("[^"]*expression\([^"]*"|\'[^\']*expression\([^\']*\')/i', '', $html);

        return trim($html);
    }

    private static function withFallbacks(array $values, ?string $locale): array
    {
        foreach (self::FALLBACKS[self::locale($locale)] as $key => $fallback) {
            if (trim((string) ($values[$key] ?? '')) === '') {
                $values[$key] = $fallback;
            }
        }

        return $values;
    }
}
