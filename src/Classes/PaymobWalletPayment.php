<?php

namespace Nafezly\Payments\Classes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Nafezly\Payments\Enums\PaymentStatusEnum;
use Nafezly\Payments\Enums\PaymentValidationEnum;
use Nafezly\Payments\Exceptions\MissingPaymentInfoException;
use Nafezly\Payments\Interfaces\IPaymentInterface;
use Nafezly\Payments\Interfaces\PaymentAbstract;
use Nafezly\Payments\Interfaces\PaymentInterface;
use Nafezly\Payments\Classes\BaseController;

class PaymobWalletPayment extends PaymentAbstract implements IPaymentInterface
{
    public const PAYMENT_METHOD = "PaymobWallet";

    private $paymob_api_key;
    private $paymob_wallet_integration_id;

    public function __construct()
    {
        parent::__construct();

        $this->paymob_api_key = config('nafezly-payments.PAYMOB_API_KEY');
        $this->currency = config("nafezly-payments.PAYMOB_CURRENCY");
        $this->paymob_wallet_integration_id = config("nafezly-payments.PAYMOB_WALLET_INTEGRATION_ID");
    }

    public function pay(): self
    {
        $this->validations = array_merge(PaymentValidationEnum::PAY_VALIDATION, PaymentValidationEnum::THAWANI_PAY_VALIDATION);

        try {

            $this->validate();

            $this->saveToPayment();
            $request_new_token = Http::withHeaders(['content-type' => 'application/json'])
                ->post('https://accept.paymobsolutions.com/api/auth/tokens', [
                    "api_key" => $this->paymob_api_key
                ])->json();

            $get_order = Http::withHeaders(['content-type' => 'application/json'])
                ->post('https://accept.paymobsolutions.com/api/ecommerce/orders', [
                    "auth_token" => $request_new_token['token'],
                    "delivery_needed" => "false",
                    "amount_cents" => $this->response->request['amount'],
                    "items" => []
                ])->json();
            $get_url_token = Http::withHeaders(['content-type' => 'application/json'])
                ->post('https://accept.paymobsolutions.com/api/acceptance/payment_keys', [
                    "auth_token" => $request_new_token['token'],
                    "expiration" => 36000,
                    "amount_cents" => $get_order['amount_cents'],
                    "order_id" => $get_order['id'],
                    "billing_data" => [
                        "apartment" => "NA",
                        "email" => $this->buyer->email ?? "",
                        "floor" => "NA",
                        "first_name" =>$this->buyer->name ?? "",
                        "street" => "NA",
                        "building" => "NA",
                        "phone_number" => $this->buyer->phone ?? "",
                        "shipping_method" => "NA",
                        "postal_code" => "NA",
                        "city" => "NA",
                        "country" => "NA",
                        "last_name" => $this->buyer->name ?? "",
                        "state" => "NA"
                    ],
                    "currency" => $this->currency,
                    "integration_id" => $this->paymob_wallet_integration_id,
                    'lock_order_when_paid' => true
                ])->json();

            $get_pay_link = Http::withHeaders(['content-type' => 'application/json'])
                ->post('https://accept.paymob.com/api/acceptance/payments/pay', [
                    'source' => [
                        "identifier" => $this->buyer->phone ?? "",
                        'subtype' => "WALLET"
                    ],
                    "payment_token" => $get_url_token['token']
                ])->json();

            $this->response->message = __("Paid Successfully");
            $this->response->redirect_url = $get_pay_link['redirect_url'];
       

        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();

        }

        return $this;

    }

    public function verify(): self
    {

        try {

        $string = $this->response->request['amount_cents'] . $this->response->request['created_at'] . $this->response->request['currency'] . $this->response->request['error_occured'] . $this->response->request['has_parent_transaction'] . $this->response->request['id'] . $this->response->request['integration_id'] . $this->response->request['is_3d_secure'] . $this->response->request['is_auth'] . $this->response->request['is_capture'] . $this->response->request['is_refunded'] . $this->response->request['is_standalone_payment'] . $this->response->request['is_voided'] . $this->response->request['order'] . $this->response->request['owner'] . $this->response->request['pending'] . $this->response->request['source_data_pan'] . $this->response->request['source_data_sub_type'] . $this->response->request['source_data_type'] . $this->response->request['success'];

        if (hash_hmac('sha512', $string, config('nafezly-payments.PAYMOB_HMAC'))) {
            if ($this->response->request['success'] == "true") {
                $this->updateToPayment(PaymentStatusEnum::PAID);

                $this->response->message = __("Paid Successfully");
     
            } else {
                $this->response->status = false;
                $this->response->message = __('nafezly::messages.PAYMENT_FAILED_WITH_CODE', ['CODE' => $this->getErrorMessage($request['txn_response_code'])]);
                $this->saveToLogs();
            }

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

    public function getErrorMessage($code)
    {
        $errors = [
            'BLOCKED' => __('nafezly::messages.Process_Has_Been_Blocked_From_System'),
            'B' => __('nafezly::messages.Process_Has_Been_Blocked_From_System'),
            '5' => __('nafezly::messages.Balance_is_not_enough'),
            'F' => __('nafezly::messages.Your_card_is_not_authorized_with_3D_secure'),
            '7' => __('nafezly::messages.Incorrect_card_expiration_date'),
            '2' => __('nafezly::messages.Declined'),
            '6051' => __('nafezly::messages.Balance_is_not_enough'),
            '637' => __('nafezly::messages.The_OTP_number_was_entered_incorrectly'),
            '11' => __('nafezly::messages.Security_checks_are_not_passed_by_the_system'),
        ];
        if (isset($errors[$code]))
            return $errors[$code];
        else
            return __('nafezly::messages.An_error_occurred_while_executing_the_operation');
    }
}
