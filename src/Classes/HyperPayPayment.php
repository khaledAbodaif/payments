<?php

namespace Nafezly\Payments\Classes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Nafezly\Payments\Enums\PaymentStatusEnum;
use Nafezly\Payments\Enums\PaymentValidationEnum;
use Nafezly\Payments\Exceptions\MissingPaymentInfoException;
use Nafezly\Payments\Interfaces\IPaymentInterface;
use Nafezly\Payments\Interfaces\PaymentAbstract;
use Nafezly\Payments\Interfaces\PaymentInterface;
use Nafezly\Payments\Classes\BaseController;


class HyperPayPayment extends PaymentAbstract implements IPaymentInterface
{

    public const PAYMENT_METHOD = "HyperPay";
    private $hyperpay_url;
    public $hyperpay_base_url;
    private $hyperpay_token;
    private $hyperpay_credit_id;
    private $hyperpay_mada_id;
    private $hyperpay_apple_id;
    public $app_name;
    public $verify_route_name;
    public $payment_id;

    public function __construct()
    {
        parent::__construct();

        $this->hyperpay_url = config('nafezly-payments.HYPERPAY_URL');
        $this->hyperpay_base_url = config('nafezly-payments.HYPERPAY_BASE_URL');
        $this->hyperpay_token = config('nafezly-payments.HYPERPAY_TOKEN');
        $this->currency = config('nafezly-payments.HYPERPAY_CURRENCY');
        $this->hyperpay_credit_id = config('nafezly-payments.HYPERPAY_CREDIT_ID');
        $this->hyperpay_mada_id = config('nafezly-payments.HYPERPAY_MADA_ID');
        $this->hyperpay_apple_id = config('nafezly-payments.HYPERPAY_APPLE_ID');
        $this->app_name = config('nafezly-payments.APP_NAME');
        $this->verify_route_name = config('nafezly-payments.VERIFY_ROUTE_NAME');
    }

    public function pay(): self
    {

        $this->validations = array_merge(PaymentValidationEnum::PAY_VALIDATION, PaymentValidationEnum::HyperPay_VALIDATION);

        try {

            $this->validate();

            $this->saveToPayment();
            $data = http_build_query([
                'entityId' => $this->getEntityId($this->response->request['source']),
                'amount' => $this->response->request['amount'],
                'currency' => $this->currency,
                'paymentType' => 'DB',
                'merchantTransactionId' => $this->response->request['transaction_code'],
                'billing.street1' => 'riyadh',
                'billing.city' => 'riyadh',
                'billing.state' => 'riyadh',
                'billing.country' => 'SA',
                'billing.postcode' => '123456',
                'customer.email' => $this->buyer->email ?? "",
                'customer.givenName' => $this->buyer->name ?? "",
                'customer.surname' => $this->buyer->name ?? "",
            ]);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->hyperpay_url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization:Bearer ' . $this->hyperpay_token
            ));
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // this should be set to true in production
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $responseData = curl_exec($ch);
            if (curl_errno($ch)) {
                return curl_error($ch);
            }
            curl_close($ch);
            $this->payment_id = json_decode($responseData)->id;
            Cache::forever($this->payment_id . '_source', $this->response->request['source']);
            $this->response->html = $this->generate_html();

        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();

        }

        return $this;
    }

    public function verify(): self
    {

        try {
            $source = Cache::get($this->response->request['id'] . '_source');
            Cache::forget($this->response->request['id'] . '_source');
            $entityId = $this->getEntityId($source);
            $url = $this->hyperpay_url . "/" . $this->response->request['id'] . "/payment" . "?entityId=" . $entityId;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: Bearer ' . $this->hyperpay_token
            ));
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // this should be set to true in production
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $responseData = curl_exec($ch);
            if (curl_errno($ch)) {
                return curl_error($ch);
            }
            curl_close($ch);
            $final_result = (array)json_decode($responseData, true);
            if (in_array($final_result["result"]["code"], ["000.000.000", "000.100.110", "000.100.111", "000.100.112"])) {
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

    public function generate_html(): string
    {
        return view('nafezly::html.hyper_pay', ['model' => $this, 'brand' => $this->getBrand()])->render();
    }

    private function getEntityId($source)
    {

        switch ($source) {
            case "CREDIT":
                return $this->hyperpay_credit_id;
            case "MADA":
                return $this->hyperpay_mada_id;
            case "APPLE":
                return $this->hyperpay_apple_id;
            default:
                return "";
        }
    }

    private function getBrand()
    {
        $form_brands = "VISA MASTER";
        if ($this->source == "MADA") {
            $form_brands = "MADA";
        } elseif ($this->source == "APPLE") {
            $form_brands = "APPLEPAY";
        }
        return $form_brands;
    }
}


