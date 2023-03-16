<?php

namespace Nafezly\Payments\Classes;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nafezly\Payments\Enums\PaymentStatusEnum;
use Nafezly\Payments\Enums\PaymentValidationEnum;
use Nafezly\Payments\Exceptions\MissingPaymentInfoException;
use Nafezly\Payments\Interfaces\IPaymentInterface;
use Nafezly\Payments\Interfaces\PaymentAbstract;
use Nafezly\Payments\Interfaces\PaymentInterface;
use Nafezly\Payments\Classes\BaseController;


class OpayPayment  extends PaymentAbstract implements IPaymentInterface
{
    public const PAYMENT_METHOD = "Opay";

    private $opay_secret_key;
    private $opay_public_key;
    private $opay_merchant_id;
    private $opay_country_code;
    private $opay_base_url;
    private $verify_route_name;


    public function __construct()
    {
        parent::__construct();

        $this->currency = config('nafezly-payments.OPAY_CURRENCY');
        $this->opay_secret_key = config('nafezly-payments.OPAY_SECRET_KEY');
        $this->opay_public_key = config('nafezly-payments.OPAY_PUBLIC_KEY');
        $this->opay_merchant_id = config('nafezly-payments.OPAY_MERCHANT_ID');
        $this->opay_country_code = config('nafezly-payments.OPAY_COUNTRY_CODE');
        $this->opay_base_url = config('nafezly-payments.OPAY_BASE_URL');
        $this->verify_route_name = config('nafezly-payments.VERIFY_ROUTE_NAME');
    }

    public function pay():self
    {
        $this->validations = array_merge(PaymentValidationEnum::PAY_VALIDATION, PaymentValidationEnum::THAWANI_PAY_VALIDATION);

        try {

            $this->validate();

            $this->saveToPayment();
        $unique_id=$this->response->request['transaction_code'];
        $response = Http::withHeaders([
            "MerchantId"=>$this->opay_merchant_id,
            "authorization"=>"Bearer ".$this->opay_public_key,
            "content-type"=>"application/json"  
        ])->post($this->opay_base_url.'/api/v1/international/cashier/create',[
           "amount" => [
                 "currency" => $this->currency, 
                 "total" => $this->response->request['amount']
            ], 
           "callbackUrl" => $this->verify_route_name."?reference_id=".$unique_id, 
           "cancelUrl" => $this->verify_route_name."?reference_id=".$unique_id, 
           "country" => "EG", 
           "expireAt" => 780, 
           "payMethod" => "BankCard", 
           "productList" => $this->response->request['items'], 
           "reference" => $unique_id, 
           "returnUrl" => $this->verify_route_name."?reference_id=".$unique_id, 
           "userInfo" => [
              "userEmail" => $this->buyer->email ?? "", 
              "userId" => $$this->buyer->id ?? "", 
              "userMobile" => $this->buyer->phone ?? "", 
              "userName" => $this->buyer->name ?? ""
           ] 
        ])->json();
        if($response['code']=="00000"){
            $this->response->redirect_url =$response['data']['cashierUrl'];
    
        }else{
            $this->response->html=$response['message'];
           
        }
        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();

        }

        return $this;

    }
    public function verify(): self
    {

        try {
        $data = (string)json_encode(['country' => "EG",'reference' => $this->response->request['reference_id']],JSON_UNESCAPED_SLASHES);
        $auth = hash_hmac('sha512', $data, $this->opay_secret_key); 
        $response = Http::withHeaders([
            "MerchantId"=>$this->opay_merchant_id,
            "authorization"=>"Bearer ".$auth
        ])->post($this->opay_base_url.'/api/v1/international/cashier/status',[
            'reference'=>$this->response->request['reference_id'],
            'country'=>"EG"
        ])->json();
        if($response['code']=="00000" && isset($response['data']['status']) && $response['data']['status']){
            $this->updateToPayment(PaymentStatusEnum::PAID);

            $this->response->message = __("Paid Successfully");
  

        }else{
            $this->response->status=false;
            $this->response->message =$response;
            $this->saveToLogs();
        }
        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();
        }

        return $this;
    }
}