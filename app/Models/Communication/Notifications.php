<?php

namespace App\Models\Communication;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notifications extends Model
{
    use HasFactory;
    protected $guarded = [];

    /**
     * C6 (owner's rule): a service provider never works in the Business Coach area, so a coach link sent to one becomes
     * the same page of the provider portal (Program Work, Program discovery); the frontend maps older rows the same way.
     */
    protected static function booted(): void
    {
        static::saving(function (self $n) {
            if ($n->link && str_starts_with((string) $n->link, 'dashboard.reviewerGrantOrg') && $n->receiver_id
                && (int) \App\Models\Auth\User::whereKey($n->receiver_id)->value('user_type_id') === 3) {
                $n->link = self::providerLink((string) $n->link);
            }
        });
    }

    public const PROVIDER_FOR_COACH = [
        'dashboard.reviewerGrantOrg' => 'dashboard.serviceProvider',
        'dashboard.reviewerGrantOrg.grants' => 'dashboard.serviceProvider.grantsDiscover',
        'dashboard.reviewerGrantOrg.requests' => 'dashboard.serviceProvider.grantsDiscover',
        'dashboard.reviewerGrantOrg.applications' => 'dashboard.serviceProvider.grantsDiscover',
        'dashboard.reviewerGrantOrg.applicationsSingle' => 'dashboard.serviceProvider.grantsDiscover',
        'dashboard.reviewerGrantOrg.applicationsMulti' => 'dashboard.serviceProvider.grantsDiscover',
        'dashboard.reviewerGrantOrg.applicationsSingleApplicant' => 'dashboard.serviceProvider.grantsDiscover',
        'dashboard.reviewerGrantOrg.applicationsMultiRound' => 'dashboard.serviceProvider.reviewWorkspace',
        'dashboard.reviewerGrantOrg.applicationsMultiRoundApplicant' => 'dashboard.serviceProvider.reviewWorkspace.applicant',
        'dashboard.reviewerGrantOrg.applicationReview' => 'dashboard.serviceProvider.reviewWorkspace.applicant',
        'dashboard.reviewerGrantOrg.siteVisits' => 'dashboard.serviceProvider.programWork.siteVisits',
        'dashboard.reviewerGrantOrg.siteVisitDetail' => 'dashboard.serviceProvider.programWork.siteVisitReport',
        'dashboard.reviewerGrantOrg.orders' => 'dashboard.serviceProvider.programWork.orders',
        'dashboard.reviewerGrantOrg.officeHours' => 'dashboard.serviceProvider.schedule',
        'dashboard.reviewerGrantOrg.meetings' => 'dashboard.serviceProvider.meetings',
        'dashboard.reviewerGrantOrg.messages' => 'dashboard.serviceProvider.messages',
        'dashboard.reviewerGrantOrg.account' => 'dashboard.serviceProvider.account',
        'dashboard.reviewerGrantOrg.transactions' => 'dashboard.serviceProvider.account.transactions',
        'dashboard.reviewerGrantOrg.settings' => 'dashboard.serviceProvider.settings',
    ];

    /** The provider-portal link for a coach link (params, query and #section kept, as utils/notificationLinkResolver.js) */
    public static function providerLink(string $link): string
    {
        preg_match('/^([^:?#]+)(.*)$/s', $link, $m);
        $target = self::PROVIDER_FOR_COACH[$m[1]] ?? 'dashboard.serviceProvider';
        $rest = $m[2] ?? '';
        if (str_ends_with($target, 'siteVisitReport')) {
            $rest = str_replace('::id=', '::visitId=', preg_replace('/^::(\d+)/', '::visitId=$1', $rest));
        }
        if (str_ends_with($target, 'grantsDiscover') || $target === 'dashboard.serviceProvider') {
            $rest = preg_replace('/::[^?#]*/', '', $rest);
        }

        return $target.$rest;
    }
}
