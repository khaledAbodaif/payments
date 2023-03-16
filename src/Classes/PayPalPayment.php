<?php

namespace Nafezly\Payments\Classes;

use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Nafezly\Payments\Enums\PaymentStatusEnum;
use Nafezly\Payments\Enums\PaymentValidationEnum;
use Nafezly\Payments\Interfaces\IPaymentInterface;
use Nafezly\Payments\Interfaces\PaymentAbstract;
use Nafezly\Payments\Interfaces\PaymentInterface;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use PayPalCheckoutSdk\Orders\OrdersGetRequest;
use Nafezly\Payments\Classes\BaseController;

class PayPalPayment extends PaymentAbstract implements IPaymentInterface
{
    public const PAYMENT_METHOD = "PayPal";

    private $paypal_client_id;
    private $paypal_secret;
    private $verify_route_name;
    public $paypal_mode;
    public $currency;


    public function __construct()
    {
        parent::__construct();

        $this->paypal_client_id = config('nafezly-payments.PAYPAL_CLIENT_ID');
        $this->paypal_secret = config('nafezly-payments.PAYPAL_SECRET');
        $this->verify_route_name = config('nafezly-payments.VERIFY_ROUTE_NAME');
        $this->paypal_mode = config('nafezly-payments.PAYPAL_MODE');
        $this->currency = config('nafezly-payments.PAYPAL_CURRENCY');
    }

    public function pay():self
    {
        $this->validations = array_merge(PaymentValidationEnum::PAY_VALIDATION, PaymentValidationEnum::THAWANI_PAY_VALIDATION);

        try {

            $this->validate();

            $this->saveToPayment();
        if($this->paypal_mode=="live")
            $environment = new ProductionEnvironment($this->paypal_client_id, $this->paypal_secret);
        else
            $environment = new SandboxEnvironment($this->paypal_client_id, $this->paypal_secret);


        $client = new PayPalHttpClient($environment);

        $request = new OrdersCreateRequest();
        $request->prefer('return=representation');
        $request->body = [
            "intent" => "CAPTURE",
            "purchase_units" => [[
                "reference_id" => $this->response->request['transaction_code'],
                "amount" => [
                    "value" => $this->response->request['amount'],
                    "currency_code" => $this->currency
                ]
            ]],
            "application_context" => [
                "cancel_url" => route($this->verify_route_name, ['payment' => "paypal"]),
                "return_url" => route($this->verify_route_name, ['payment' => "paypal"])
            ]
        ];

            $response = json_decode(json_encode($client->execute($request)), true);
            $this->response->redirect_url =collect($response['result']['links'])->where('rel', 'approve')->firstOrFail()['href'];


        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();

        }

        return $this;
    }

    public function verify(): self
    {

        try {
        if($this->paypal_mode=="live")
            $environment = new ProductionEnvironment($this->paypal_client_id, $this->paypal_secret);
        else
            $environment = new SandboxEnvironment($this->paypal_client_id, $this->paypal_secret);

        $client = new PayPalHttpClient($environment);

            $response = $client->execute(new OrdersCaptureRequest($this->response->request['token']) );
            $result = json_decode(json_encode($response), true);
            if ($result['result']['status'] == "COMPLETED" && $result['statusCode']==201) {
                $this->updateToPayment(PaymentStatusEnum::PAID);

                $this->response->message = __("Paid Successfully");


            } else {

                $this->response->status = false;
                $this->response->message = __('nafezly::messages.PAYMENT_FAILED_WITH_CODE', ['CODE' => $this->getErrorMessage($request['txn_response_code'])]);
                $this->saveToLogs();
            }

        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();
        }

        return $this;
    }
}
