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


class PaytabsPayment extends PaymentAbstract implements IPaymentInterface
{
    public const PAYMENT_METHOD = "Paytabs";
    private $paytabs_profile_id;
    private $paytabs_base_url;
    private $paytabs_server_key;
    private $paytabs_checkout_lang;
    private $verify_route_name;


    public function __construct()
    {
        parent::__construct();

        $this->paytabs_profile_id = config('nafezly-payments.PAYTABS_PROFILE_ID');
        $this->paytabs_base_url = config('nafezly-payments.PAYTABS_BASE_URL');
        $this->paytabs_server_key = config('nafezly-payments.PAYTABS_SERVER_KEY');
        $this->paytabs_checkout_lang = config('nafezly-payments.PAYTABS_CHECKOUT_LANG');
        $this->currency = config('nafezly-payments.PAYTABS_CURRENCY');
        $this->verify_route_name = config('nafezly-payments.VERIFY_ROUTE_NAME');

    }


    public function pay(): self
    {
        $this->validations = array_merge(PaymentValidationEnum::PAY_VALIDATION, PaymentValidationEnum::THAWANI_PAY_VALIDATION);

        try {

            $this->validate();

            $this->saveToPayment();
            $unique_id = uniqid();

            $response = Http::withHeaders([
                'Authorization' => $this->paytabs_server_key,
                'Content-Type' => "application/json"
            ])->post($this->paytabs_base_url . "/payment/request", [
                'profile_id' => $this->paytabs_profile_id,
                "tran_type" => "sale",
                "tran_class" => "ecom",
                "cart_id" => $this->response->request['transaction_code'],
                "cart_currency" => $this->currency,
                "cart_amount" => $this->response->request['amount'],
                "hide_shipping" => true,
                "cart_description" => "items",
                "paypage_lang" => $this->paytabs_checkout_lang,
                "callback" => route($this->verify_route_name, ['payment_id' => $this->response->request['transaction_code']]), //Post end point  -the payment status will be sent to server
                "return" => route($this->verify_route_name, ['payment_id' => $this->response->request['transaction_code']]), //Get end point - The link to which the user will be redirected
                "customer_ref" => $this->response->request['transaction_code'],
                "customer_details" => [
                    "name" => $this->buyer->name ?? "",
                    "email" => $this->buyer->email ?? "",
                    "phone" =>$this->buyer->phone ?? "",
                    "street1" => "Not Available Data",
                    "city" => "Not Available Data",
                    "state" => "Not Available Data",
                    "country" => "Not Available Data",
                    "zip" => "00000"
                ],
                'valu_down_payment' => "0",
                "tokenise" => 1
            ])->json();

            if (!isset($response['code'])) {
                Cache::forever($unique_id, $response['tran_ref']);
                $this->response->message = __("Paid Successfully");
                $this->response->redirect_url = $response['redirect_url'];

            }else{
                $this->response->errors=(array)json_decode($response->body());
                throw new \Exception('Something went wrong');
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
        $payment_id = $request->tranRef != null ? $request->tranRef : Cache::get($request['tranRef']);
        Cache::forget($request['tranRef']);

        $response = Http::withHeaders([
            'Authorization' => $this->paytabs_server_key,
            'Content-Type' => "application/json"
        ])->post($this->paytabs_base_url . "/payment/query", [
            'profile_id' => $this->paytabs_profile_id,
            'tran_ref' => $payment_id
        ])->json();

        if (isset($response['payment_result']['response_status']) && $response['payment_result']['response_status'] == "A") {
            $this->updateToPayment(PaymentStatusEnum::PAID);
            $this->response->message = __("Paid Successfully");

        } else {
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
