<?php

/*
 * Pilot pricing rules (Tujitume Pilot Pricing and Proposal Flow). Every early customer gets a tailored proposal,
 * worked out the same way in the admin calculator on each demo request (App\Service\DemoRequests\ProposalQuote).
 * AI credits are not part of the calculator yet.
 */
return [
    'currency' => 'USD',

    // Pilot discounts are short and end on a date; they are never extended automatically
    'max_discount_months' => 6,
    'max_discount_percent' => 50,

    // Usage and margins are reviewed this many days after the customer goes live
    'review_days' => ['min' => 60, 'max' => 90, 'default' => 60],

    'billing_periods' => ['monthly' => 1, 'quarterly' => 3, 'annual' => 12],
];
