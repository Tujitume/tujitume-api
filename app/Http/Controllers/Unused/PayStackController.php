<?php

namespace App\Http\Controllers\Account\Account\Account\Account\Unused;

use App\Http\Controllers\Account\Account\Account\Account\Controller;
use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Conversation;
use App\Models\Business\Listing;
use App\Models\Cart;
use App\Models\Communication\Notifications;
use App\Models\Milestones\Milestones;
use App\Models\orders;
use App\Models\Services\serviceBook;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use App\Models\Shop;
use Auth;
use Hash;
use Illuminate\Http\Request;
use Mail;
use Session;

class PayStackController extends Controller
{
    public function __construct()
    {
        $this->public = 'pk_test_05479d57206db767b2cc76467cab1c7824237ffa';
        $this->secret = 'sk_test_fb3bf64f0b4da439f52bf6b482fa7395a5b4511b';
        //$this->Bearer = $this->secret;
    }

    public function initialize(Request $request)
    {
        $investor_id = Auth::id();
        $business_id = $request->listing;
        $Business = listing::where('id',$business_id)->first();
        $owner = User::where('id', $Business->user_id)->first();
        $amount= round($request->amount,2);
        $amountReal= round($request->amountOriginal,2);
        $JitumeAmount = round( ($amount - $amountReal), 2);

        $amount = round( (($amount)*128.5*100), 2); //$ to KES
        $JitumeAmount = round( (($JitumeAmount)*128.5*100), 2); //$ to KES


        try {

          $subaccount = 'ACCT_n9mpmg5jdy7nit2';//$owner->subaccount_id;
          $url = "https://api.paystack.co/transaction/initialize";
          $fields = [
            'email' => "customer@email.com",
            'amount' => $amount,
            'subaccount' => $subaccount,
            'transaction_charge' => $JitumeAmount,
            //'callback_url' => 'http://localhost:81/'
          ];
          $fields_string = http_build_query($fields);
          //open connection
          $ch = curl_init();
          //set the url, number of POST vars, POST data
          curl_setopt($ch,CURLOPT_URL, $url);
          curl_setopt($ch,CURLOPT_POST, true);
          curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
          curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer ".$this->secret,
            "Cache-Control: no-cache",
          ));
          //So that curl_exec returns the contents of the cURL; rather than echoing it
          curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
          //execute post
          $result = curl_exec($ch);
          $resultArray = json_decode($result, true);
          $ref = $resultArray['data']['reference'];
          //$validAmount = $this->verify($ref);


          return response($result);
          //else response(['Error: Verification failed!'],400);
          //echo '<pre>'; print_r($result); echo '<pre>';

        } catch (\Exception $e) {
            return response()->json(['status' => 400, 'message' => $e->getMessage()]);
        }
    }

    public function verify($business_id,$percent,$amountKFront,$amountReal,$ref)
    {
        try {
            //$ref = "T751929395745907";
            $curl = curl_init();
            curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/transaction/verify/".$ref,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
              "Authorization: Bearer ".$this->secret,
              "Cache-Control: no-cache",
            ),
          ));

          $response = curl_exec($curl);
          $response = json_decode($response, true);
          $err = curl_error($curl);
          curl_close($curl);

        if($err) {
            return response()->json(['error' =>$err, 'status' => 400 ]);
          }
        else {
            $amountKEN = $response['data']['amount'];
            $status = $response['data']['status'];
            //$subaccount=$response['data']['subaccount']['subaccount_code'];
            //BACKEND UPADATE
            if($status == 'success' && $amountKEN == $amountKFront)
            {
                $investor_id = Auth::id();
                //$business_id = $request->listing;
                $Business = listing::where('id',$business_id)->first();
                $owner = User::where('id', $Business->user_id)->first();
                //$percent = $request->percent;
                    $type = 'Monetary';
                    $bids = BusinessBids::create([
                      'date' => date('Y-m-d'),
                      'investor_id' => $investor_id,
                      'business_id' => $business_id,
                      'owner_id' => $Business->user_id,
                      'type' => $type,
                      'amount' => $amountReal,
                      'representation' => $percent,
                      'paystack_charge_id' => $ref
                    ]);

                // Milestone Fulfill check
                    $total_bid_amount = 0;
                    $mile1 = Milestones::where('listing_id',$business_id)
                    ->where('status','In Progress')->first();
                    $this_bids = BusinessBids::where('business_id',$business_id)->get();
                    foreach($this_bids as $b)
                    if($b)
                    $total_bid_amount = $total_bid_amount+($b->amount);

                    if($mile1)
                    if($total_bid_amount >= $mile1->amount){
                        $owner = User::where('id',$Business->user_id)->first();
                        $info=[ 'business_name'=>$Business->name ];
                        $user['to'] = $owner->email; //'tottenham266@gmail.com'; //
                         Mail::send('bids.mile_fulfill', $info, function($msg) use ($user){
                             $msg->to($user['to']);
                             $msg->subject('Fulfills a milestone!');
                         });
                 }
                 // Milestone Fulfill check

                 //NotificationService
                     $now=date("Y-m-d H:i"); $date=date('d M, h:i a',strtotime($now));
                     $addNoti = Notifications::create([
                        'date' => $date,
                        'receiver_id' => $Business->user_id,
                        'customer_id' => $investor_id,
                        'text' => 'You have a new bid from _name!',
                        'link' => 'investment-bids',
                        'type' => 'investor',

                      ]);
                     //NotificationService
                    if($bids){
                    return response()->json(['message' =>  'Bid placed! you will get a notification if your bid is accepted!', 'status' => 200]);
                    }
                    else {
                      return response()->json(['error' =>'Amount mismatch!', 'status' => 422 ]);
                    }

           }
           //BACKEND UPADATE
        }
         // echo '<pre>'; print_r($response); echo '<pre>';
        } catch (\Exception $e) {
            return response()->json(['status' => 400, 'message' => $e->getMessage()]);
        }
    }


    public function verifySmallFee($package,$business_id,$amountKFront,$amountReal,$ref)
    {
        try {
            //$ref = "T751929395745907";
            $curl = curl_init();
            curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/transaction/verify/".$ref,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
              "Authorization: Bearer ".$this->secret,
              "Cache-Control: no-cache",
            ),
          ));
          $response = curl_exec($curl);
          $response = json_decode($response, true);
          $err = curl_error($curl);
          curl_close($curl);

        if($err) {
            return response()->json(['error' =>$err, 'status' => 400 ]);
          }
        else {
            $amountKEN = $response['data']['amount'];
            $status = $response['data']['status'];
            //$subaccount=$response['data']['subaccount']['subaccount_code'];
            //BACKEND UPADATE
            if($status == 'success' && $amountKEN == $amountKFront)
            {
                //BACKEND UPADATE
                $investor_id = Auth::id();
                $Business = listing::where('id',$business_id)->first();
                $owner = User::where('id', $Business->user_id)->first();
                //$percent = $request->percent;
                    $type = 'Monetary';
                    $conv = Conversation::create([
                                'investor_id' => $investor_id,
                                'listing_id' => $business_id,
                                'package' => $package,
                                'price' => $amountReal
                            ]);

                    if($conv){
                    return response()->json(['message' =>  'Success', 'status' => 200]);
                    }
                    else {
                      return response()->json(['message' =>'Something wrong!', 'status' => 422 ]);
                    }

          }
          //BACKEND UPADATE
        }
         // echo '<pre>'; print_r($response); echo '<pre>';
        } catch (\Exception $e) {
            return response()->json(['status' => 400, 'message' => $e->getMessage()]);
        }
    }


        public function verifyService($true_mile_id,$rep_id,$amountKFront,$amountReal,$ref)
    {
        try {
            //$ref = "T751929395745907";
            $curl = curl_init();
            curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/transaction/verify/".$ref,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
              "Authorization: Bearer ".$this->secret,
              "Cache-Control: no-cache",
            ),
          ));
          $response = curl_exec($curl);
          $response = json_decode($response, true);
          $err = curl_error($curl);
          curl_close($curl);

        if($err) {
            return response()->json(['error' =>$err, 'status' => 400 ]);
          }
        else {
            $amountKEN = $response['data']['amount'];
            $status = $response['data']['status'];
            //$subaccount=$response['data']['subaccount']['subaccount_code'];

            //BACKEND UPADATE
            if($status == 'success' && $amountKEN == $amountKFront)
            {
                $investor_id = Auth::id();
                $mile = Smilestones::where('id',$true_mile_id)->first();
                $service = Services::select('id', 'name', 'user_id')
                ->where('id',$mile->listing_id)->first();
                $owner = User::select('email')->where('id', $service->user_id)->first();
                $customer = User::select('fname','lname','email')->where('id',$investor_id)->first();

                //ASSET-related
                if ($service->category == '0')
                {
                    $booking = serviceBook::select('business_bid_id')
                    ->where('service_id',$service->id)
                    ->where('booker_id',$investor_id)->first();
                    $accepted_bids = AcceptedBids::select('business_id')
                    ->where('bid_id',$booking->business_bid_id)
                    ->first();
                    $info=[ 'business_owner'=>$accepted_bids->business_id, 'manager'=>$owner->id];
                    $user['to'] = $customer->email;
                    Mail::send('services.equip_release_request', $info, function($msg) use ($user){
                         $msg->to($user['to']);
                         $msg->subject('Equipment release request!');
                    });
                }
                //ASSET-related

                $update = ServiceBookingMilestone::where('id',$rep_id)->update([ 'status' => 'In Progress']);
                $info=['name'=>$mile->title,  'amount'=>$mile->amount,
                'business'=>$service->name, 's_id' => $service->id,
                'customer'=>$customer->fname. ' '.$customer->lname ];
                $user['to'] = $owner->email;//'sohaankane@gmail.com';



                if($update){
                    Mail::send('milestoneS.milestone_mail', $info, function($msg) use ($user){
                     $msg->to($user['to']);
                     $msg->subject('Milestone Paid!');
                    });
                    return response()->json(['message' =>  'Success',
                        'service_id' => $service->id, 'status' => 200]);
                }
                else {
                    return response()->json(['message' =>'Something wrong!', 'status' => 422 ]);
                }

            }
           //BACKEND UPADATE
        }
         // echo '<pre>'; print_r($response); echo '<pre>';
        } catch (\Exception $e) {
            return response()->json(['status' => 400, 'message' => $e->getMessage()]);
        }
    }


    public function create_subaccount()
    {

          $url = "https://api.paystack.co/subaccount";
          $fields = [
            'business_name' => "Oasis Wasis",
            'bank_code' => "057",
            'account_number' => "0000000000",
            'percentage_charge' => 95
          ];

          $fields_string = json_encode($fields);
          //open connection
          $ch = curl_init();
          //set the url, number of POST vars, POST data
          curl_setopt($ch,CURLOPT_URL, $url);
          curl_setopt($ch,CURLOPT_POST, true);
          curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
          curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer ".$this->secret,
            "Cache-Control: no-cache",
            "content-type: application/json"
          ));
          //So that curl_exec returns the contents of the cURL; rather than echoing it
          curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
          //execute post
          $result = curl_exec($ch);
          $result = json_decode($result,true);
           echo '<pre>'; print_r($result); echo '<pre>';
    }

    public function transfer_funds(){

        //Retrive Subaccount
        $ref = 'ACCT_n9mpmg5jdy7nit2';
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/subaccount/".$ref,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
              "Authorization: Bearer ".$this->secret,
              "Cache-Control: no-cache",
            ),
          ));

          $response = curl_exec($curl);
          $err = curl_error($curl);

          curl_close($curl);

          if ($err) {
            echo "cURL Error #:" . $err;
          } else {
            $result = json_decode($response,true);
            $bank = $result['data']['bank'];
            $account_number = $result['data']['account_number'];
            $name = $result['data']['business_name'];
            $curr = $result['data']['currency'];
              //echo '<pre>'; print_r($result); echo '<pre>';exit;
          }
        // Retrive Subaccount

        //Generate Recipient
          $url = "https://api.paystack.co/transferrecipient";
          $fields = [
            'type' => "nuban",
            'name' => $name,
            'account_number' => $account_number,
            'bank_code' => '057',
            'currency' => $curr
          ];

          $fields_string = http_build_query($fields);
          //open connection
          $ch = curl_init();
          //set the url, number of POST vars, POST data
          curl_setopt($ch,CURLOPT_URL, $url);
          curl_setopt($ch,CURLOPT_POST, true);
          curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
          curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer ".$this->secret,
            "Cache-Control: no-cache",
          ));

          //So that curl_exec returns the contents of the cURL; rather than echoing it
          curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);

          //execute post
          $result = curl_exec($ch);
          $result = json_decode($result,true);
          $recipient_code = $result['data']['recipient_code'];
          return $recipient_code;
          // echo '<pre>'; print_r($result); echo '<pre>';exit;

          //Generate Recipient

          //TRANSFER
          $url = "https://api.paystack.co/transfer";
              $fields = [
                "source" => "balance", "reason" => "Calm down",
                "amount" => 500, "recipient" => $recipient_code,
            "reference" => "your-unique-reference",];
              $fields_string = http_build_query($fields);$ch = curl_init();
              curl_setopt($ch,CURLOPT_URL, $url);
              curl_setopt($ch,CURLOPT_POST, true);
              curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
              curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                "Authorization: Bearer sk_test_fb3bf64f0b4da439f52bf6b482fa7395a5b4511b",
                "Cache-Control: no-cache",
              ));
              curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
              $result = curl_exec($ch);$result = json_decode($result,true);
              echo '<pre>'; print_r($result); echo '<pre>';
    }

//Class Ends
}
