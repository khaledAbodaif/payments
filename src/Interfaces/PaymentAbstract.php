<?php

namespace Nafezly\Payments\Interfaces;

use Illuminate\Database\Eloquent\Model;
use Nafezly\Payments\Services\PaymentResponse;
use Nafezly\Payments\Traits\PaymentSave;
use Nafezly\Payments\Traits\PaymentSaveToLogs;
use Nafezly\Payments\Traits\PaymentValidation;

abstract class PaymentAbstract
{

    use PaymentSave, PaymentSaveToLogs,PaymentValidation;
    protected array $data;
    protected array $attributes;
    protected array $validations;
    protected Model $buyer;
    public PaymentResponse $response;



    public function __construct()
    {
        $this->response = new PaymentResponse();
    }

    public function setRequest($attributes):IPaymentInterface
    {

        $this->response->request = $attributes;
        return $this;
    }

    public function setBuyerModel(Model $buyer):IPaymentInterface
    {

        $this->buyer = $buyer;
        return $this;

    }
}
