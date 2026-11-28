<?php

namespace Tests\Unit\Payments;

use App\Http\Controllers\CheckoutMpesaController;
use PHPUnit\Framework\TestCase;

/** ISSUE-45: a LIPR reply that did not start a payment is recognised, with its reason. */
class LiprRefusalTest extends TestCase
{
    public function test_refusals_and_a_started_payment(): void
    {
        $this->assertSame('M-Pesa could not be reached', CheckoutMpesaController::liprRefusal(null));
        $this->assertSame('Invalid wallet', CheckoutMpesaController::liprRefusal(['status' => 400, 'message' => 'Invalid wallet']));
        $this->assertSame('Insufficient funds', CheckoutMpesaController::liprRefusal(['status' => 200, 'data' => [['success' => false, 'reason' => 'Insufficient funds.']]]));
        $this->assertNull(CheckoutMpesaController::liprRefusal(['status' => 200, 'data' => [['success' => true, 'reference' => 'R1']]]));
    }
}
