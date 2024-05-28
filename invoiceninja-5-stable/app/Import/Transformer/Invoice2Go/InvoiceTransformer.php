<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2022. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Import\Transformer\Invoice2Go;

use App\Import\ImportException;
use App\Import\Transformer\BaseTransformer;
use App\Models\Invoice;
use Illuminate\Support\Str;

/**
 * Class InvoiceTransformer.
 */
class InvoiceTransformer extends BaseTransformer
{
    /**
     * @param $line_items_data
     *
     * @return bool|array
     */
    public function transform($invoice_data)
    {
        if (!isset($invoice_data['DocumentNumber'])) {
            throw new ImportException('DocumentNumber key not found in this import file.');
        }

        if ($this->hasInvoice($invoice_data['DocumentNumber'])) {
            throw new ImportException('Invoice number already exists');
        }

        $invoiceStatusMap = [
            'unsent' => Invoice::STATUS_DRAFT,
            'sent'   => Invoice::STATUS_SENT,
        ];

        $transformed = [
            'company_id'  => $this->company->id,
            'number'      => $this->getString($invoice_data, 'DocumentNumber'),
            'notes'       => $this->getString($invoice_data, 'Comment'),
            'date'        => isset($invoice_data['DocumentDate']) ? date('Y-m-d', strtotime($invoice_data['DocumentDate'])) : null,
            // 'currency_id' => $this->getCurrencyByCode( $invoice_data, 'Currency' ),
            'amount'      => $this->getFloat($invoice_data, 'TotalAmount'),
            'status_id'   => $invoiceStatusMap[$status =
                    strtolower($this->getString($invoice_data, 'DocumentStatus'))] ?? Invoice::STATUS_SENT,
            // 'viewed'      => $status === 'viewed',
            'line_items'  => [
                [
                    'cost'             	  => $this->getFloat($invoice_data, 'TotalAmount'),
                    'quantity'           => 1,
                    'discount'           => $this->getFloat($invoice_data, 'DiscountValue'),
                    'is_amount_discount' => false,
                ],
            ],
        ];

        $client_id = null;

        if($this->hasClient($this->getString($invoice_data, 'Name') || $this->getContact($this->getString($invoice_data, 'EmailRecipient')))) {

            $client_id = $this->getClient($this->getString($invoice_data, 'Name'), $this->getString($invoice_data, 'EmailRecipient'));

        }

        if ($client_id) {
            $transformed['client_id'] = $client_id;
        } else {
            $settings = new \stdClass();
            $settings->currency_id = $this->getCurrencyByCode($invoice_data, 'Currency');

            $transformed['client'] = [
                'name'              => $this->getString($invoice_data, 'Name'),
                'address1'          => $this->getString($invoice_data, 'DocumentRecipientAddress'),
                'shipping_address1' => $this->getString($invoice_data, 'ShipAddress'),
                'credit_balance'    => 0,
                'settings'          => $settings,
                'client_hash'       => Str::random(40),
                'contacts'          => [
                    [
                        'email' => $this->getString($invoice_data, 'EmailRecipient'),
                    ],
                ],
            ];
        }
        if (! empty($invoice_data['Date Paid'])) {
            $transformed['payments'] = [
                [
                    'date'   => date('Y-m-d', strtotime($invoice_data['DatePaid'])),
                    'amount' => $this->getFloat($invoice_data, 'Payments'),
                ],
            ];
        }

        return $transformed;
    }
}
