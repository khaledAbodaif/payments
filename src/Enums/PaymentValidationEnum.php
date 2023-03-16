<?php

namespace Nafezly\Payments\Enums;

class PaymentValidationEnum
{

    const PAY_VALIDATION =
        [
            "amount" => 'required|numeric',
            "notes" => 'nullable|max:500',
            "transaction_code" => 'required|max:100|unique:nafezly_payments,transaction_code',

            // morphed columns to allow any table for payment (orders,services ,etc...)
            "order_id" => 'required|numeric',
            "order_table" => 'required|string',
        ];

    const VERIFY_VALIDATION =
        [
            "amount" => 'required|numeric',
            "transaction_code" => 'required|exists:payments,transaction_code',
            "status" =>'required|string'

        ];

    const  FAWRY_PAY_VALIDATION=[
        "items*"=>'required',
        "items.*.itemId"=>'required|numeric',
        "items.*.price"=>'required|numeric',
        "items.*.quantity"=>'required|numeric',
    ];

    const  THAWANI_PAY_VALIDATION=[
        "items*"=>'required',
        "items.*.name"=>'required|string',
        "items.*.unit_amount"=>'required|numeric',
        "items.*.quantity"=>'required|numeric',
    ];
    
    const HyperPay_VALIDATION=[
        "source"=>'required'
    ];

    const Kashier_VALIDATION=[
        "source"=>'required'
    ];

    const OPAY_VALIDATION=[
        "items*"=>'required',
        "items.*.price"=>'required|numeric',
        "items.*.productId"=>'required',
        "items.*.quantity"=>'required|numeric',
        ];

}
