<?php
/**
 * Entity Ninja (https://entityninja.com).
 *
 * @link https://github.com/entityninja/entityninja source repository
 *
 * @copyright Copyright (c) 2022. Entity Ninja LLC (https://entityninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Jobs\Entity;

use App\Exceptions\FilePermissionsFailure;
use App\Models\Credit;
use App\Models\CreditInvitation;
use App\Models\Invoice;
use App\Models\InvoiceInvitation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderInvitation;
use App\Models\Quote;
use App\Models\QuoteInvitation;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceInvitation;
use App\Services\Pdf\PdfService;
use App\Utils\Traits\MakesHash;
use App\Utils\Traits\MakesInvoiceHtml;
use App\Utils\Traits\NumberFormatter;
use App\Utils\Traits\Pdf\PageNumbering;
use App\Utils\Traits\Pdf\PdfMaker;

class CreateRawPdf
{
    use NumberFormatter;
    use MakesInvoiceHtml;
    use PdfMaker;
    use MakesHash;
    use PageNumbering;

    public Invoice | Credit | Quote | RecurringInvoice | PurchaseOrder $entity;

    public $company;

    public $contact;

    public $invitation;

    public $entity_string = '';

    /**
     * @param $invitation
     */
    public function __construct($invitation, private ?string $type = null)
    {

        $this->invitation = $invitation;

        if ($invitation instanceof InvoiceInvitation) {
            $this->entity = $invitation->invoice;
            $this->entity_string = 'invoice';
        } elseif ($invitation instanceof QuoteInvitation) {
            $this->entity = $invitation->quote;
            $this->entity_string = 'quote';
        } elseif ($invitation instanceof CreditInvitation) {
            $this->entity = $invitation->credit;
            $this->entity_string = 'credit';
        } elseif ($invitation instanceof RecurringInvoiceInvitation) {
            $this->entity = $invitation->recurring_invoice;
            $this->entity_string = 'recurring_invoice';
        } elseif ($invitation instanceof PurchaseOrderInvitation) {
            $this->entity = $invitation->purchase_order;
            $this->entity_string = 'purchase_order';
        }

    }

    private function resolveType(): string
    {
        if($this->type) {
            return $this->type;
        }

        $type = 'product';

        match($this->entity_string) {
            'purchase_order' => $type = 'purchase_order',
            'invoice' => $type = 'product',
            'quote' => $type = 'product',
            'credit' => $type = 'product',
            'recurring_invoice' => $type = 'product',
        };

        return $type;

    }

    public function handle()
    {
        /** Testing this override to improve PDF generation performance */
        $ps = new PdfService($this->invitation, $this->resolveType(), [
            'client' => $this->entity->client ?? false,
            'vendor' => $this->entity->vendor ?? false,
            "{$this->entity_string}s" => [$this->entity],
        ]);

        $pdf = $ps->boot()->getPdf();
        nlog("pdf timer = ". $ps->execution_time);
        return $pdf;

        throw new FilePermissionsFailure('Unable to generate the raw PDF');
    }

    public function failed($e)
    {
    }
}