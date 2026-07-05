<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PayscribeService
{
    protected $baseUrl;
    protected $apiKey;

    public function __construct()
    {
        $this->baseUrl = env('PAYSCRIBE_URL');
        $this->apiKey  = env('PAYSCRIBE_KEY');
    }

    private function request($method, $endpoint, $data = [])
    {
        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->{$method}($this->baseUrl . $endpoint, $data);

        return $response->json();
    }

    // ✅ Airtime

    /**
     * Purchase airtime (Glo, Mtn, Airtel, 9mobile)
     */
    public function purchaseAirtime($recipient, $network, $amount, $ported = false, $ref)
    {
        return $this->request('post', '/airtime', [
            "network" => $network,
            "amount" => $amount,
            "recipient" => $recipient,
            "ported" => $ported,
            "ref" => $ref
        ]);
    }

    // ✅ Data Bundle. Data purchase - MTN, GLO, AIRTEL, 9MOBILE, SMILE etc

    /**
     * Data Lookup
     */
    public function dataLookup($network)
    {
        return $this->request('get', 'data/lookup?network=' . $network);
    }

    /**
     * Purchase data for Glo, Mtn, Airtel, 9mobile, DSTVsHOWMAX
     * It is advisable to access the BASE_URL/data/lookup to get the updated plan_id and amount before vending for data.
     */
    public function purchaseData($plan, $recipient, $network, $ref)
    {
        return $this->request('post', '/data/vend', [
            "plan" => $plan,
            "recipient" => $recipient,
            "network" => $network,
            "ref" => $ref
        ]);
    }

    // ePins (WAEC, NECO, UTME)...

    /**
     * Get Available ePins
     */
    public function availableEPins()
    {
        return $this->request('get', '/epins');
    }

    /**
     * Please note that some account and phone number is required when you are vending JAMB.
     */
    public function purchasePin($qty, $id, $ref)
    {
        return $this->request('post', '/epins/vend', [
            "qty" => $qty,
            "id" => $id,
            "ref" => $ref
        ]);
    }

    /**
     * Lookup JAMB user details.
     */
    public function jambUserLookup($id, $account)
    {
        return $this->request('post', '/epins/jamb/user/lookup', [
            "id" => $id,
            "account" => $account
        ]);
    }

    /**
     * fetch all generated ePins for a particular transaction using the transaction ID sent when puchased.
     */
    public function retrieveEPin($trans_id)
    {
        return $this->request('get', '/epins/retrieve?trans_id=' . $trans_id);
    }


    // ✅ Cable

    public function fetchBouquets($service)
    {
        return $this->request('get', '/bouquets/?service=' . $service);
    }

    public function validateSmartCard($service, $account, $month, $planId)
    {
        return $this->request('post', '/multichoice/validate', [
            "service" => $service,
            "account" => $account,
            "month" => $month,
            "plan_id"  => $planId
        ]);
    }

    /**
     * Pay Cable TV - GOTV, DSTV,STARTIMES, DSTVSHOWMAX
     */
    public function payCableTv($planId, $customer_name, $account, $service, $ref, $phone, $email, $month)
    {
        return $this->request('post', '/multichoice/vend', [
            "plan_id"  => $planId,
            "customer_name" => $customer_name,
            "account" => $account,
            "service" => $service,
            "ref" => $ref,
            "phone" => $phone,
            "email" => $email,
            "month" => $month
        ]);
    }

    /**
     * Topup GoTV, DSTV
     */
    public function topUpTv($planId, $customer_name, $account, $service, $ref, $phone, $email, $month)
    {
        return $this->request('post', '/multichoice/topup', [
            "plan_id"  => $planId,
            "customer_name" => $customer_name,
            "account" => $account,
            "service" => $service,
            "ref" => $ref,
            "phone" => $phone,
            "email" => $email,
            "month" => $month
        ]);
    }

    // Internet Subscription - Ntel,Spectranet...

    /**
     * List Internet Services
     */
    public function listInternetServices($type, $account)
    {
        return $this->request('get', '/internet/list', [
            "type" => $type,
            "account" => $account
        ]);
    }

    /**
     * Spectranet Pin Plans
     */
    public function getSpectranetPinPlans()
    {
        return $this->request('get', '/internet/spectranet/pins/plans');
    }

    /**
     * Purchase Spectranet Pins
     */
    public function purchaseSpectranetPins($planId, $qty, $ref)
    {
        return $this->request('post', '/internet/spectranet/pins/vend', [
            "plan_id" => $planId,
            "qty"  => $qty,
            "ref" => $ref
        ]);
    }

    // ✅ Electricity

    /**
     * validate electricity
     */
    public function validateElectricity($number, $type, $amount, $service)
    {
        return $this->request('post', '/electricity/validate', [
            "meter_number" => $number,
            "meter_type"  => $type,
            "amount"  => $amount,
            "service"  => $service
        ]);
    }

    /**
     * electricity payment
     */
    public function electricityPayment($number, $type, $amount, $service, $phone, $customer_name, $ref)
    {
        return $this->request('post', '/electricity/vend', [
            "meter_number" => $number,
            "meter_type"  => $type,
            "amount"  => $amount,
            "service"  => $service,
            "phone"  => $phone,
            "customer_name"  => $customer_name,
            "ref" => $ref
        ]);
    }

    /**
     * requery transaction if request fail. This is recommended in the docs
     */
    public function requeryTransaction($trans_id)
    {
        return $this->request('get', '/requery/?trans_id=' . $trans_id);
    }
}
