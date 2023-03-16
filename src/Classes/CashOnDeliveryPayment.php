<?php

namespace Nafezly\Payments\Classes;

use Illuminate\Database\Eloquent\Model;
use Nafezly\Payments\Enums\CashOnDeliveryEnum;
use Nafezly\Payments\Enums\PaymentStatusEnum;
use Nafezly\Payments\Interfaces\IPaymentInterface;
use Nafezly\Payments\Interfaces\PaymentAbstract;
use Nafezly\Payments\Models\Payment;
use Nafezly\Payments\Services\PaymentResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Nafezly\Payments\Traits\PaymentSave;
use Nafezly\Payments\Traits\PaymentSaveToLogs;
use Nafezly\Payments\Traits\PaymentValidation;

class CashOnDeliveryPayment extends PaymentAbstract implements IPaymentInterface
{

    public const PPAYMENT_METHOD = "CashOnDelivery";


    public function pay(): self
    {

        $this->validations = CashOnDeliveryEnum::PAY_VALIDATION;

        try {

            $this->validate();

            $this->saveToPayment();

            $this->response->message = __("Paid Successfully");


        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();

        }

        return $this;

    }


    public function verify(): self
    {

        try {

            $this->validations = CashOnDeliveryEnum::VERIFY_VALIDATION;

            $this->validate();

            $this->updateToPayment(PaymentStatusEnum::PAID);

        } catch (\Exception $e) {

            $this->response->message = $e->getMessage();
            $this->saveToLogs();
        }
        return $this;

    }



}
