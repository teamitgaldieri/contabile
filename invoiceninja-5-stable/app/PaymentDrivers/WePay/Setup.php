<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2023. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\PaymentDrivers\WePay;

use App\PaymentDrivers\WePayPaymentDriver;

class Setup
{
    public $wepay;

    public function __construct(WePayPaymentDriver $wepay)
    {
        $this->wepay = $wepay;
    }

    public function boot($data)
    {
        /*
        'user_id',
        'user_company',
         */

        return render('gateways.wepay.signup.index', $data);
    }
}
