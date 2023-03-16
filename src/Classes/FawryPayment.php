<?php

namespace Nafezly\Payments\Classes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

use Nafezly\Payments\Enums\PaymentValidationEnum;
use Nafezly\Payments\Interfaces\IPaymentInterface;
use Nafezly\Payments\Interfaces\PaymentAbstract;
use Nafezly\Payments\Interfaces\PaymentInterface;
use Nafezly\Payments\Classes\BaseController;

class FawryPayment extends PaymentAbstract implements IPaymentInterface
{

    public const PAYMENT_METHOD = "Fawry";

    public $fawry_url;
    public $fawry_secret;
    public $fawry_merchant;
    public $verify_route_name;
    public $fawry_display_mode;
    public $fawry_pay_mode;

    public function __construct()
    {
        parent::__construct();
        $this->fawry_url = config('nafezly-payments.FAWRY_URL');
        $this->fawry_merchant = config('nafezly-payments.FAWRY_MERCHANT');
        $this->fawry_secret = config('nafezly-payments.FAWRY_SECRET');
        $this->fawry_display_mode = config('nafezly-payments.FAWRY_DISPLAY_MODE');
        $this->fawry_pay_mode = config('nafezly-payments.FAWRY_PAY_MODE');
        $this->verify_route_name = config('nafezly-payments.VERIFY_ROUTE_NAME');
        $this->return_url = config('nafezly-payments.FAWRY_RETURN_URL');
        $this->api_testing_url = config('nafezly-payments.FAWRY_API_TESTING_URL');
        $this->api_prod_url = config('nafezly-payments.FAWRY_API_PROD_URL');
    }


    public function pay(): self
    {

        $this->validations = array_merge( PaymentValidationEnum::PAY_VALIDATION,PaymentValidationEnum::FAWRY_PAY_VALIDATION);

        try {

            $this->validate();

            $this->saveToPayment();
            $data = [
                'fawry_url' => $this->fawry_url,
                'fawry_merchant' => $this->fawry_merchant,
                'fawry_secret' => $this->fawry_secret,
                'user_id' => $this->buyer->id,
                'user_name' => $this->buyer->name ?? "",
                'user_email' => $this->buyer->email ?? "example@example.com",
                'user_phone' => $this->buyer->phone ?? "",
                'unique_id' => $this->response->request['transaction_code'],
                'item_id' => 1,
                'item_quantity' => 1,
                'amount' => $this->response->request['amount'],
                'payment_id' => $this->response->request['transaction_code']
            ];

            $data['secret'] = $data['fawry_merchant'] . $data['unique_id'] . $data['user_id'] . $data['item_id'] . $data['item_quantity'] . $data['amount'] . $data['fawry_secret'];

            $this->response->message = __("Paid Successfully");
            $this->response->html = $this->generate_html($data);

            $this->setRedirectUrl();

        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();

        }

        return $this;

    }

    private function setRedirectUrl()
    {


        $this->init();

        $response= Http::timeout(10000)->post(
            App::environment(['local', 'staging'])
                ? $this->api_testing_url
                : $this->api_prod_url
            , $this->data);

        if ($response->status() != 200){

            $this->response->errors=(array)json_decode($response->body());
            throw new \Exception('Something went wrong');

        }

        $this->response->redirect_url=$response->body();

    }

    private function init()
    {

        $appLocal = App::getLocale();
        $this->data = [
            'merchantCode' => $this->fawry_merchant,
            'customerEmail' => $this->buyer->email ?? "example@example.com",
            'customerMobile' => $this->buyer->phone ?? "+201000000000",
            'customerName' => $this->buyer->name ?? "buyer",
            "authCaptureModePayment" => false,
            "language" => ($appLocal == "ar") ? $appLocal . "eg" : $appLocal . "-gb",
            "chargeItems" => $this->response->request['items'],
            "returnUrl" => $this->return_url,
            'merchantRefNum' => $this->response->request['transaction_code'],
            'signature' => $this->generateSignature()

        ];
    }

    /**
     * @param Request $request
     * @return array|void
     */
    public function verify(): self
    {
        try {
            $res = json_decode($this->response->request['chargeResponse'], true);
            $reference_id = $res['merchantRefNumber'];

            $hash = hash('sha256', $this->fawry_merchant . $reference_id . $this->fawry_secret);

            $response = Http::get($this->fawry_url . 'ECommerceWeb/Fawry/payments/status/v2?merchantCode=' . $this->fawry_merchant . '&merchantRefNumber=' . $reference_id . '&signature=' . $hash);

            if ($response->offsetGet('statusCode') == 200 && $response->offsetGet('paymentStatus') == "PAID") {
                return [
                    'success' => true,
                    'payment_id' => $reference_id,
                    'message' => __('nafezly::messages.PAYMENT_DONE'),
                    'process_data' => $request->all()
                ];
            } else if ($response->offsetGet('statusCode') != 200) {
                return [
                    'success' => false,
                    'payment_id' => $reference_id,
                    'message' => __('nafezly::messages.PAYMENT_FAILED'),
                    'process_data' => $request->all()
                ];
            }
        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();
        }

        return $this;

    }

    private function generate_html($data): string
    {
        return view('nafezly::html.fawry', ['model' => $this, 'data' => $data])->render();
    }

    private function generateSignature(): string
    {


        $items = collect($this->response->request['items'])->map(function ($item) {
            return $item['itemId'] . $item['quantity'] . number_format((float)$item['price'], 2, '.', '');
        })->join('');
        return hash('sha256', $this->fawry_merchant . $this->response->request['transaction_code'] . $this->return_url . $items . $this->fawry_secret);


    }
}
