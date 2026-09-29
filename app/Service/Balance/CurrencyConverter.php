<?php
namespace App\Service\Balance;
use App\Service\Misc\ErrorLogService;

class CurrencyConverter
{
    public function KesToUsd()
    {
        try {
            $curl = curl_init();
            curl_setopt_array($curl, array(
                //CURLOPT_URL => "https://api.exchangerate.fun/latest?base=KES",
                CURLOPT_URL => "https://api.frankfurter.dev/v2/rates?base=KES",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => array(
                    "Accept: */*"
                ),
            ));
            $response = curl_exec($curl);
            $response = json_decode($response, true);
            $err = curl_error($curl);
            curl_close($curl);

            if($err){
                return $err;
            }
            //return $response['rates']['USD'];
            $rate = collect($response)->firstWhere('quote', 'USD')['rate'] ?? null;

            return $rate;

        } catch (\Exception $e) {

            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);
            
            return false;
        }
    }

    public function UsdToKes()
    {
        try {
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://api.exchangerate.fun/latest?base=USD",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => array(
                    "Accept: */*"
                ),
            ));
            
            $rawResponse = curl_exec($curl);

            if ($rawResponse === false) {
                $error = curl_error($curl);
                curl_close($curl);

                return false;
            }

            curl_close($curl);

            $response = json_decode($rawResponse, true);

            $rate = collect($response)->firstWhere('quote', 'KES')['rate'] ?? null;

            return $rate;

        } catch (\Exception $e) {

            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return false;
        }
    }

}
