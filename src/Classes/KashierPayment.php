<?php

namespace Nafezly\Payments\Classes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Nafezly\Payments\Enums\PaymentStatusEnum;
use Nafezly\Payments\Enums\PaymentValidationEnum;
use Nafezly\Payments\Interfaces\IPaymentInterface;
use Nafezly\Payments\Interfaces\PaymentAbstract;
use Nafezly\Payments\Interfaces\PaymentInterface;
use Nafezly\Payments\Classes\BaseController;

class KashierPayment  extends PaymentAbstract implements IPaymentInterface
{
    public const PAYMENT_METHOD = "Kashier";

    public  $kashier_url;
    public  $kashier_mode;
    private $kashier_account_key;
    private $kashier_iframe_key;
    private $kashier_token;
    public  $app_name;
    private $verify_route_name;

    public function __construct() 
    {
        parent::__construct();

        $this->kashier_url = config("nafezly-payments.KASHIER_URL");
        $this->kashier_mode = config("nafezly-payments.KASHIER_MODE");
        $this->kashier_account_key = config("nafezly-payments.KASHIER_ACCOUNT_KEY");
        $this->kashier_iframe_key = config("nafezly-payments.KASHIER_IFRAME_KEY");
        $this->kashier_token = config("nafezly-payments.KASHIER_TOKEN");
        $this->currency = config('nafezly-payments.KASHIER_CURRENCY');
        $this->app_name = config('nafezly-payments.APP_NAME');
        $this->verify_route_name = config('nafezly-payments.VERIFY_ROUTE_NAME');
    }


    public function pay():self
    {
        $this->validations = array_merge(PaymentValidationEnum::PAY_VALIDATION, PaymentValidationEnum::Kashier_VALIDATION);

        try {

            $this->validate();

            $this->saveToPayment();
        $payment_id = $this->response->request['transaction_code'];

        $mid = $this->kashier_account_key;
        $order_id = $payment_id;
        $secret = $this->kashier_iframe_key;
        $path = "/?payment={$mid}.{$order_id}.{$this->response->request['amount']}.{$this->currency}";
        $hash = hash_hmac('sha256', $path, $secret);

        $data = [
            'mid' => $mid,
            'amount' => $this->response->request['amount'],
            'currency' => $this->currency,
            'order_id' => $order_id,
            'path' => $path,
            'hash' => $hash,
            'source'=>$this->response->request['source'],
            'redirect_back' => route($this->verify_route_name, ['payment' => "kashier"])
        ];

            $this->response->message = __("Paid Successfully");
            $this->response->html =$this->generate_html($data);

        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();

        }

        return $this;

    }

    public function verify(): self
    {

        try {

        if ($this->response->request['paymentStatus'] == "SUCCESS") {
            $queryString = "";
            foreach ($this->response->request as $key => $value) {

                if ($key == "signature" || $key == "mode") {
                    continue;
                }
                $queryString = $queryString . "&" . $key . "=" . $value;
            }

            $queryString = ltrim($queryString, $queryString[0]);
            $signature = hash_hmac('sha256', $queryString, $this->kashier_iframe_key,false);
            if ($signature == $this->response->request["signature"]) {
                $this->updateToPayment(PaymentStatusEnum::PAID);

                $this->response->message = __("Paid Successfully");
                
            } else {

                $this->response->message = __('nafezly::messages.PAYMENT_FAILED');
                $this->saveToLogs();
            }
        }else if($this->response->request['signature']==null){
            $url_mode = $this->kashier_mode == "live"?'':'test-';
            $response = Http::withHeaders([
                'Authorization' => $this->kashier_token
            ])->get('https://'.$url_mode.'api.kashier.io/payments/orders/'.$this->response->request['merchantOrderId'])->json();
            if(isset($response['response']['status']) && $response['response']['status']=="CAPTURED"){
                $this->updateToPayment(PaymentStatusEnum::PAID);

                $this->response->message = __("Paid Successfully");

            }else{
                $this->response->message = __('nafezly::messages.PAYMENT_FAILED');
                $this->saveToLogs();
            }
            
        } else {
            $this->response->message = __('nafezly::messages.PAYMENT_FAILED');
            $this->saveToLogs();
        }
        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();
        }
    }

    /**
     * @param $amount
     * @param $data
     * @return string
     */
    private function generate_html($data): string
    {
        return view('nafezly::html.kashier', ['model' => $this, 'data' => $data])->render();
    }

}