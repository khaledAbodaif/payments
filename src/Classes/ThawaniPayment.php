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

class ThawaniPayment extends PaymentAbstract implements IPaymentInterface
{
    public const PAYMENT_METHOD = "Thawani";

    private $thawani_url;
    private $thawani_api_key;
    private $thawani_publishable_key;
    private $verify_route_name;

    public function __construct()
    {
        parent::__construct();

        $this->thawani_url = config('nafezly-payments.THAWANI_URL');
        $this->thawani_api_key = config('nafezly-payments.THAWANI_API_KEY');
        $this->thawani_publishable_key = config('nafezly-payments.THAWANI_PUBLISHABLE_KEY');
        $this->verify_route_name = config('nafezly-payments.VERIFY_ROUTE_NAME');
    }

    public function pay():self
    {
        $this->validations = array_merge(PaymentValidationEnum::PAY_VALIDATION, PaymentValidationEnum::THAWANI_PAY_VALIDATION);

        try {

            $this->validate();

            $this->saveToPayment();
            $response = Http::withHeaders([
                'Content-Type' => "application/json",
                "Thawani-Api-Key" => $this->thawani_api_key
            ])->post(this->thawani_url . '/api/v1/checkout/session', [
                "client_reference_id" => $this->response->request['transaction_code'],
                "model" => 'payment',
                "products" => $this->response->request['items'],
                "success_url" => route($this->verify_route_name, ['payment' => "thawani", 'payment_id' => $this->response->request['transaction_code']]),
                "cancel_url" => route($this->verify_route_name, ['payment' => "thawani", 'payment_id' => $this->response->request['transaction_code']]),
                "metadata" => [
                    "customer" => $this->buyer->name ?? "",
                    "order_id" => $this->response->request['order_id'],
                    "phone" => $this->buyer->phone??""
                ]
            ]);

            if ($response->status() != 200){
                $this->response->status=false;
                $this->response->errors=(array)json_decode($response->body());
                throw new \Exception('Something went wrong');

            }
            $response=$response->json();

            Cache::forever($this->response->request['transaction_code'], $response['data']['session_id']);
            $this->response->message = __("Paid Successfully");
            $this->response->redirect_url = $this->thawani_url . '/pay/' . $response['data']['session_id'] . "?key=" . $this->thawani_publishable_key;

        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();

        }

        return $this;
    }


    public function verify(): self
    {

        try {

            $payment_id = $this->response->request['payment_id'] != null ? $this->response->request['payment_id'] : Cache::get($this->response->request['payment_id']);
            Cache::forget($this->response->request['payment_id']);
            $response = Http::withHeaders([
                'content-type' => 'application/json',
                'Thawani-Api-Key' => $this->thawani_api_key
            ])->get($this->thawani_url . '/api/v1/checkout/session/' . $payment_id)->json();

            if ($response['data']['payment_status'] == "paid") {

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
