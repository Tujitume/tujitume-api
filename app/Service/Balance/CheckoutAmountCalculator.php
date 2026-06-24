<?php
namespace App\Service\Balance;

use App\Models\Misc\Setting;

class CheckoutAmountCalculator
{
    protected $X;
    protected $tujitume_fee;

    public function __construct(){
        $this->tujitume_fee = (float) Setting::where('key', 'tujitume_fee')->first()?->value ?? 3;
        //$this->tujitume_fee_percent = $this->tujitume_fee / 100;
    }

    public function stripe($originalAmount, $currency = 'USD', $country = 'US'): float
    {
        $X = 0;
        //Formula without Intl and FX buffer
        //X − (0.029X + 0.30) = 0.971X − 0.30 == $originalAmount + 3(fee)

        // Platform fee
        $tujitumeFeePercent = $this->tujitume_fee / 100;
        $platformFeeAmount = $originalAmount * $tujitumeFeePercent;

        // Stripe buffered fees
        $stripeBasePercent = 0.029;
        $intlBufferPercent = 0.015; //worst case
        $fxBufferPercent   = 0.01;  //worst case

        $stripePercent = $stripeBasePercent + $intlBufferPercent + $fxBufferPercent; // 0.054

        $stripeFixed = 0.30;

        // Correct gross-up formula
        $X = (
                $originalAmount
                + $platformFeeAmount
                + $stripeFixed
            ) / (1 - $stripePercent);

        return round($X, 2);
    }

    //Reverse Calculation to reduce from SME amount
    public function stripeGC($originalAmount, $currency = 'USD', $country = 'US'): float
    {
        $X = 0;
        //Formula  /  X = amount SME gets
        //X + (0.03 * $originalAmount) + (0.054 * $originalAmount) + 0.30  == $originalAmount($100)


        // Platform fee
        $tujitumeFeePercent = $this->tujitume_fee / 100;
        $platformFeeAmount = $originalAmount * $tujitumeFeePercent;

        // Stripe buffered fees
        $stripeBasePercent = 0.029;
        $intlBufferPercent = 0.015; //worst case
        $fxBufferPercent   = 0.01;  //worst case

        $stripePercent = $stripeBasePercent + $intlBufferPercent + $fxBufferPercent; // 0.054
        $stripeFixed = 0.30;

        // Correct Deduction formula
        $X = $originalAmount - $stripeFixed - (($stripePercent+$tujitumeFeePercent) * $originalAmount);

        return round($X, 2);
    }

    // M P E S A      P A Y M E N T     C A L C U L A T O R
    public function mpesa(float $targetAmount, string $channel = 'mpesa' // mpesa | local_card
    ): float {
        // Platform fee
        $platformPercent = $this->tujitume_fee / 100;
        $platformFee = $targetAmount * $platformPercent;

        // Paystack gateway percent by channel
        $gatewayPercent = match ($channel) {
            'mpesa'      => 0.015,
            'local_card' => 0.029,
            'intl_card'  => 0.038,
            default      => 0.029, // safe fallback
        };

        /**
         * Gross-up formula
         * X = (targetAmount + platformFee) / (1 - gatewayPercent)
         */
        $X = ($targetAmount + $platformFee) / (1 - $gatewayPercent);

        return round($X, 2);
    }

    public function mpesaGC(
        float $investorPays,
        string $channel = 'mpesa' // mpesa | local_card | intl_card
    ): float {
        // Platform fee
        $platformPercent = $this->tujitume_fee / 100;
        $platformFee = $investorPays * $platformPercent;

        // Paystack gateway percent by channel
        $gatewayPercent = match ($channel) {
            'mpesa'      => 0.015,
            'local_card' => 0.029,
            'intl_card'  => 0.038,
            default      => 0.029,
        };

        /**
         * Net-down formula
         * BO = investorPays - platformFee - (gatewayPercent × investorPays)
         */
        $X = $investorPays
            - $platformFee
            - ($gatewayPercent * $investorPays);

        return round($X, 2);
    }


}

