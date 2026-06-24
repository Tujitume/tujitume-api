<?php

namespace App\Http\Controllers\Account\Account\Account\Account\Unused;

use App\Http\Controllers\Account\Account\Account\Account\Controller;
use App\Http\Controllers\Account\Account\Account\Account\Exception;
use Illuminate\Http\Request;
use PayPal\Api\Amount;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Exception\PayPalConnectionException;
use PayPal\Rest\ApiContext;


class PayPalController extends Controller
{
     public function payment()
    {
        $sum = 500;
        $apiContext = new ApiContext(
          new OAuthTokenCredential( 'ClientID',  'ClientSecret'  ) );
      // dd($apiContext);
      $payer = new Payer();
      $payer->setPaymentMethod("paypal");
      // dd($payer);
      // Set redirect URLs
      $redirectUrls = new RedirectUrls();
      $redirectUrls->setReturnUrl(route('paypal.success'))
          ->setCancelUrl(route('paypal.cancel'));
      // dd($redirectUrls);
      // Set payment amount
      $amount = new Amount();
      $amount->setCurrency("USD")
          ->setTotal($sum);


      // Set transaction object
      $transaction = new Transaction();
      $transaction->setAmount($amount)
          ->setDescription(" Hello ");
      //   dd($transaction);
      // Create the full payment object
      $payment = new Payment();
      $payment->setIntent('sale')
          ->setPayer($payer)
          ->setRedirectUrls($redirectUrls)
          ->setTransactions(array($transaction));
      // dd($payment);
      // Create payment with valid API context
      try {

          $payment->create($apiContext);
          // dd($payment);
          // Get PayPal redirect URL and redirect the customer
          // $approvalUrl =
          return redirect($payment->getApprovalLink());
          // dd($approvalUrl);
          // Redirect the customer to $approvalUrl
      } catch (PayPalConnectionException $ex) {
          echo $ex->getCode();
          echo $ex->getData();
          die($ex);
      } catch (Exception $ex) {
          die($ex);
      }
  }



// this is success route
public function success(Request $request)
{
    $apiContext = new ApiContext(
        new OAuthTokenCredential('ClientID', 'ClientSecret') );

    // Get payment object by passing paymentId
    $paymentId = $_GET['paymentId'];
    $payment = Payment::get($paymentId, $apiContext);
    $payerId = $_GET['PayerID'];

    // Execute payment with payer ID
    $execution = new PaymentExecution();
    $execution->setPayerId($payerId);

    try {
        dd('success');


    } catch (PayPalConnectionException $ex) {
        echo $ex->getCode();
        echo $ex->getData();
        die($ex);
    } catch (Exception $ex) {
        die($ex);
    }
}

// this is cancel route
  public function cancel()
{
        dd('payment cancel');
}
}

