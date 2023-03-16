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


class TapPayment extends PaymentAbstract implements IPaymentInterface
{
    public const PAYMENT_METHOD = "Tap";

    private $tap_secret_key;
    private $tap_public_key;
    private $tap_lang_code;
    private $verify_route_name;

    public function __construct()
    {
        $this->currency = config('nafezly-payments.TAP_CURRENCY');
        $this->tap_secret_key = config('nafezly-payments.TAP_SECRET_KEY');
        $this->tap_public_key = config('nafezly-payments.TAP_PUBLIC_KEY');
        $this->tap_lang_code = config('nafezly-payments.TAP_LANG_CODE');
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
                "authorization" => "Bearer " . $this->tap_secret_key,
                "content-type" => "application/json",
                'lang_code' => $this->tap_lang_code
            ])->post('https://api.tap.company/v2/charges', [
                "amount" => $this->response->request['transaction_code'],
                "currency" => $this->currency,
                "threeDSecure" => true,
                "save_card" => false,
                "description" => "Cerdit",
                "statement_descriptor" => "Cerdit",
                "reference" => [
                    "transaction" => $this->response->request['transaction_code'],
                    "order" => $this->response->request['order_id']
                ],
                "receipt" => [
                    "email" => true,
                    "sms" => true
                ], "customer" => [
                    "first_name" => $this->buyer->name ?? "",
                    "middle_name" => "",
                    "last_name" => $this->buyer->name ?? "",
                    "email" => $this->buyer->email ?? "",
                    "phone" => [
                        "country_code" => "20",
                        "number" => $this->buyer->phone ?? ""
                    ]
                ],
                "source" => ["id" => "src_all"],
                "post" => ["url" => route($this->verify_route_name, ['payment' => "tap"])],
                "redirect" => ["url" => route($this->verify_route_name, ['payment' => "tap"])]
            ]);

            if ($response->status() != 200) {

                $this->response->errors = (array)json_decode($response->body());
                $this->response->message = $response->body();
                throw new \Exception('Something went wrong');

            }
            $response = $response->json();

            $this->response->redirect_url = $response['transaction']['url'],

        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();

        }

        return $this;
    }

    /**
     * @param Request $request
     * @return array
     */
    public function verify(): self
    {
        try{
        $response = Http::withHeaders([
            "authorization" => "Bearer " . $this->tap_secret_key,
        ])->get('https://api.tap.company/v2/charges/' . $this->response->request['tap_id'])->json();
        if (isset($response['status']) && $response['status'] == "CAPTURED") {
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
