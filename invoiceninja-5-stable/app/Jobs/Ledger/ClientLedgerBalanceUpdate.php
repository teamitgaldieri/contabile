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

namespace App\Jobs\Ledger;

use App\Libraries\MultiDB;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyLedger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ClientLedgerBalanceUpdate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    private ?CompanyLedger $next_balance_record;

    public function __construct(public Company $company, public Client $client)
    {
    }

    /**
     * Execute the job.
     *
     *
     * @return void
     */
    public function handle(): void
    {
        $uuid = \Illuminate\Support\Str::uuid();

        // nlog("Updating company ledger for client {$this->client->id} {$uuid}");

        MultiDB::setDb($this->company->db);

        // $dupes = CompanyLedger::query()
        //     ->where('client_id', $this->client->id)
        //     ->where('balance', 0)
        //     ->where('hash', '<>', '')
        //     ->groupBy(['adjustment','hash'])
        //     ->havingRaw('COUNT(*) > 1')
        //     ->pluck('id');

        // CompanyLedger::query()->whereIn('id', $dupes)->delete();

        // $dupes = CompanyLedger::query()
        //     ->where('client_id', $this->client->id)
        //     ->where('balance', 0)
        //     ->where('hash', '<>', '')
        //     ->groupBy(['adjustment','hash'])
        //     ->havingRaw('COUNT(*) > 1')
        //     ->pluck('id');

        // CompanyLedger::query()->whereIn('id', $dupes)->delete();

        CompanyLedger::query()
                        ->where('balance', 0)
                        ->where('client_id', $this->client->id)
                        ->orderBy('id', 'ASC')
                        ->get()
                        ->each(function ($company_ledger) {

                            $parent_ledger = CompanyLedger::query()
                                                    ->where('id', '<', $company_ledger->id)
                                                    ->where('client_id', $company_ledger->client_id)
                                                    ->where('company_id', $company_ledger->company_id)
                                                    ->where('balance', '!=', 0)
                                                    ->orderBy('id', 'DESC')
                                                    ->first();

                            // $company_ledger->balance = $last_record->balance + $company_ledger->adjustment;
                            $company_ledger->balance = ($parent_ledger ? $parent_ledger->balance : 0) + $company_ledger->adjustment;
                            $company_ledger->save();

                        });

        // nlog("finished job {$uuid}");
    }

    public function middleware()
    {
        return [(new WithoutOverlapping($this->client->id))->dontRelease()];
    }
}