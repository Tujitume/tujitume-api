<?php

namespace App\Service\DemoRequests;

use App\Models\DemoRequest;
use App\Models\Misc\Setting;
use App\Service\Notification\EmailService;

/**
 * Emails of the demo-request flow, all through one template (resources/views/demo_requests/message.blade.php).
 * Links are passed as *_href so EmailService does not move them onto another address: a sign-up link must open
 * on the main site, a trial link on the trial workspace, the "workspace ready" link on the new workspace.
 *
 * Replies to proposal / pilot / general-inquiry requests are written by the admin from an editable template per
 * request type (settings table, key "demo_template.<type>"; placeholders {name}, {org}, {program_type}, and {terms} /
 * {scope} filled from the latest proposal terms in the admin calculator, ProposalQuote).
 */
class DemoRequestMailer
{
    public const VIEW = 'demo_requests.message';

    public function __construct(private EmailService $email = new EmailService())
    {
    }

    public function received(DemoRequest $r): bool
    {
        $next = $r->request_type === DemoRequest::TYPE_DEMO
            ? 'Our team reviews every demo request. Once yours is approved you will get a second email with a link to your own 7-day demo account.'
            : 'Our team will review it and email you at this address with the next steps.';

        return $this->send('We received your request', self::VIEW, [
            'title' => 'Thank you, '.$this->firstName($r),
            'greeting' => 'Hi '.$this->firstName($r).',',
            'paragraphs' => [
                'Thank you for your interest in Tujitume. We have received your "'.$r->requestTypeLabel().'" request for '.$r->org.'.',
                $next,
            ],
            'details' => $this->summary($r),
        ], $r->email);
    }

    public function teamNotice(DemoRequest $r): bool
    {
        return $this->send('New '.$r->requestTypeLabel().' request: '.$r->org, self::VIEW, [
            'title' => 'New '.$r->requestTypeLabel().' request',
            'greeting' => 'Hi Tujitume team,',
            'paragraphs' => ['A new request came in from the website. Review it in the admin under Demo Requests.'],
            'details' => $this->summary($r) + ['Email' => $r->email],
            'button_href' => rtrim((string) config('app.url'), '/').'/admin/demo-requests?status=pending',
            'button_label' => 'Review request',
        ], (string) config('mail.tujitume.contact_email', 'info@tujitume.com'));
    }

    public function teamNoticeFullAccess(DemoRequest $r): bool
    {
        return $this->send('Full access requested: '.$r->org, self::VIEW, [
            'title' => 'Full access requested',
            'greeting' => 'Hi Tujitume team,',
            'paragraphs' => [$r->name.' ('.$r->email.') asked for full access from their demo account. They have been emailed their one-time sign-up link.'],
            'details' => $this->summary($r) + ['Demo ends' => $r->onRequesterClock($r->trial_expires_at)],
            'button_href' => rtrim((string) config('app.url'), '/').'/admin/demo-requests/'.$r->id,
            'button_label' => 'Open request',
        ], (string) config('mail.tujitume.contact_email', 'info@tujitume.com'));
    }

    /** Short on purpose: the rest is explained inside the demo. $fullAccessLink: one-time sign-up for the real account */
    public function trialReady(DemoRequest $r, string $accessLink, ?string $fullAccessLink = null): bool
    {
        return $this->send('Your Tujitume demo account is ready', self::VIEW, [
            'title' => 'Your demo account is ready',
            'greeting' => 'Hi '.$this->firstName($r).',',
            'paragraphs' => [
                'Your demo for '.$r->org.' is ready. Set your password to open it. It is open for '.DemoRequest::TRIAL_DAYS.' days.',
            ],
            'details' => [
                'Sign-in email' => $r->email,
                'Demo ends' => $r->onRequesterClock($r->trial_expires_at),
            ],
            'button_href' => $accessLink,
            'button_label' => 'Set password and open demo',
            'secondary_text' => $fullAccessLink ? 'Ready for full access already?' : null,
            'secondary_href' => $fullAccessLink,
            'secondary_label' => 'Create your full account',
        ], $r->email);
    }

    public function rejected(DemoRequest $r): bool
    {
        return $this->send('About your Tujitume request', self::VIEW, [
            'title' => 'About your request',
            'greeting' => 'Hi '.$this->firstName($r).',',
            'paragraphs' => [
                'Thank you for your "'.$r->requestTypeLabel().'" request for '.$r->org.'. After reviewing it, we are not able to go ahead with it at this time.',
            ],
            'details' => ['Reason' => $r->rejection_reason],
            'footnote' => 'If anything has changed or you have questions, write to '.self::contact().' or send a new request from our website.',
        ], $r->email);
    }

    public function proposal(DemoRequest $r): bool
    {
        return $this->send($r->proposal_subject ?: 'Your Tujitume proposal', self::VIEW, [
            'title' => $r->proposal_subject ?: 'Your Tujitume proposal',
            'body' => $r->proposal_body,
        ], $r->email);
    }

    public function trialEndingSoon(DemoRequest $r): bool
    {
        return $this->send('Your Tujitume demo ends soon', self::VIEW, [
            'title' => 'Your demo ends soon',
            'greeting' => 'Hi '.$this->firstName($r).',',
            'paragraphs' => [
                'Your demo account for '.$r->org.' ends on '.$r->onRequesterClock($r->trial_expires_at).'. After that it is closed and everything created in it is deleted.',
                'To keep using Tujitume, sign in to the demo and choose "Get full access". We will email you a personal link to set up your organization\'s own workspace.',
            ],
        ], $r->email);
    }

    public function trialClosed(DemoRequest $r): bool
    {
        return $this->send('Your Tujitume demo has ended', self::VIEW, [
            'title' => 'Your demo has ended',
            'greeting' => 'Hi '.$this->firstName($r).',',
            'paragraphs' => [
                'Your '.DemoRequest::TRIAL_DAYS.'-day demo account for '.$r->org.' has ended, and the account and its data have been deleted.',
                'Thank you for trying Tujitume. If you would like to continue, write to '.self::contact().' or send a new request from our website.',
            ],
        ], $r->email);
    }

    /** The one-time link to /auth/create/program. */
    public function registrationInvite(string $email, ?string $name, string $inviteLink, \DateTimeInterface $expiresAt, ?DemoRequest $r = null): bool
    {
        $first = $name ? (preg_split('/\s+/', trim($name))[0] ?? $name) : null;
        $expires = $r ? $r->onRequesterClock($expiresAt) : \Carbon\Carbon::instance($expiresAt)->format('D j M Y, H:i').' (UTC)';

        return $this->send('Set up your Tujitume workspace', self::VIEW, [
            'title' => 'Set up your workspace',
            'greeting' => $first ? 'Hi '.$first.',' : 'Hello,',
            'paragraphs' => [
                'You have been invited to create your organization\'s Tujitume account and workspace.',
                'The link below is personal: it only works for '.$email.' and only once. After you create the account you will choose a subscription plan and pay, and then your workspace opens.',
            ],
            'details' => ['Link valid until' => $expires],
            'button_href' => $inviteLink,
            'button_label' => 'Create your account',
            'footnote' => 'If you did not expect this email you can ignore it.',
        ], $email);
    }

    public function workspaceReady(string $email, string $name, string $workspaceLink, string $orgName): bool
    {
        return $this->send('Your Tujitume workspace is ready', self::VIEW, [
            'title' => 'Your workspace is ready',
            'greeting' => 'Hi '.$name.',',
            'paragraphs' => [
                'Thank you for your payment. '.$orgName.' now has full access to Tujitume.',
                'Your organization works from its own address. Sign in there from now on, and share it with your team.',
            ],
            'details' => ['Workspace address' => $workspaceLink, 'Sign-in email' => $email],
            'button_href' => $workspaceLink,
            'button_label' => 'Open your workspace',
        ], $email);
    }

    /** Subject and body the admin starts from when answering a proposal / pilot / inquiry request. */
    public static function templateFor(DemoRequest $r): array
    {
        $type = $r->request_type;
        $stored = Setting::where('key', 'demo_template.'.$type)->value('value');
        $template = $stored ? (json_decode($stored, true) ?: []) : [];
        $template += self::defaultTemplate($type);

        $quote = $r->request_type === DemoRequest::TYPE_DEMO ? null : $r->quotes()->first();
        $replace = [
            '{name}' => self::firstNameOf($r->name),
            '{org}' => $r->org,
            '{program_type}' => $r->programIndustry?->name ?? 'capital',
            '{terms}' => $quote ? ProposalQuote::summary($quote) : '[Pricing: fill in the proposal terms calculator on this page, then reload]',
            '{scope}' => $quote?->scope_notes ?: ($r->notes ?: '[add scope]'),
        ];
        $body = $template['body'];
        // An older saved template without {terms}: the terms go in before the sign-off
        if ($quote && ! str_contains($body, '{terms}')) {
            $body = str_contains($body, "\n\nKind regards") ? str_replace("\n\nKind regards", "\n\n{terms}\n\nKind regards", $body) : $body."\n\n{terms}";
        }

        return [
            'subject' => strtr($template['subject'], $replace),
            'body' => strtr($body, $replace),
        ];
    }

    public static function rawTemplate(string $type): array
    {
        $stored = Setting::where('key', 'demo_template.'.$type)->value('value');

        return ($stored ? (json_decode($stored, true) ?: []) : []) + self::defaultTemplate($type);
    }

    public static function saveTemplate(string $type, string $subject, string $body): void
    {
        Setting::updateOrCreate(['key' => 'demo_template.'.$type], [
            'value' => json_encode(['subject' => $subject, 'body' => $body]),
        ]);
    }

    public static function defaultTemplate(string $type): array
    {
        return match ($type) {
            DemoRequest::TYPE_PILOT => [
                'subject' => 'Your Tujitume pilot plan for {org}',
                'body' => "Hi {name},\n\nThank you for your interest in running a pilot of Tujitume for your {program_type} program.\n\nA pilot runs one real program end to end on Tujitume: applications, review, milestones, evidence and staged fund releases, with our team supporting setup.\n\nScope: {scope}\n\n{terms}\n\nReply to this email to agree the start date, and we will send you a link to set up your workspace.\n\nKind regards,\nThe Tujitume team",
            ],
            DemoRequest::TYPE_INFO => [
                'subject' => 'Re: your question to Tujitume',
                'body' => "Hi {name},\n\nThank you for getting in touch with Tujitume.\n\n[Write your answer here.]\n\nIf you would like to see the platform, write to info@tujitume.com and we will set up a demo for {org}.\n\nKind regards,\nThe Tujitume team",
            ],
            default => [
                'subject' => 'Your Tujitume proposal for {org}',
                'body' => "Hi {name},\n\nThank you for requesting a proposal for your {program_type} program.\n\nWhat Tujitume will set up for {org}:\n- Program setup with application forms, review rounds and Business Coaches\n- Milestone-based fund releases backed by evidence and approvals\n- Monitoring, site visits and portfolio reporting\n\nScope: {scope}\n\n{terms}\n\nReply to this email with any questions. When you are ready, we will send you a link to set up your organization's workspace.\n\nKind regards,\nThe Tujitume team",
            ],
        };
    }

    private function summary(DemoRequest $r): array
    {
        return [
            'Request' => $r->requestTypeLabel(),
            'Name' => $r->name,
            'Organization' => $r->org,
            'Program type' => $r->programIndustry?->name,
            'Program needs' => $r->notes,
        ];
    }

    private function firstName(DemoRequest $r): string
    {
        return self::firstNameOf($r->name);
    }

    private static function firstNameOf(string $name): string
    {
        return preg_split('/\s+/', trim($name))[0] ?? $name;
    }

    /**
     * Every email of this flow comes from Tujitume to a prospect: always Tujitume's name, logo and colour, even when the
     * address already belongs to someone in an organisation (EmailService would otherwise use that organisation's brand).
     */
    private function send(string $subject, string $view, array $data, $to): bool
    {
        $data['brand'] = \App\Service\Notification\EmailBrand::defaults();

        return $this->email->send($subject, $view, $data, $to);
    }

    /** Where people write to the team (the footer says not to reply to these emails) */
    private static function contact(): string
    {
        return (string) (config('mail.tujitume.contact_email') ?: 'info@tujitume.com');
    }
}
