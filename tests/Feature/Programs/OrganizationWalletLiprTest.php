<?php

use App\Models\Organizations\Organization;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\Program\OrganizationWalletLipr;

uses(Tests\TestCase::class);

it('shows the reason LIPR gives for refusing a deposit, not its "initiated" headline', function () {
    $lipr = new class(new class extends LiprAuthService { public function __construct() {} }) extends OrganizationWalletLipr {
        protected function post(string $path, array $fields): array
        {
            return ['status' => 200, 'message' => 'Transactions initiated successfully', 'data' => [[
                'reference' => null, 'success' => false, 'reason' => 'Maximum amount allowed for this transaction is KES 250,000',
            ]]];
        }
    };
    $org = new Organization(['name' => 'Org']);
    $org->lipr_wallet = 'tujitume-xx';

    expect($lipr->stkDeposit($org, 500000, '254712345601'))->toBe([
        'success' => false, 'message' => 'The payment provider refused: Maximum amount allowed for this transaction is KES 250,000.',
    ]);
});
