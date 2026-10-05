<?php

namespace Database\Seeders\Showcase\Ledger;

use Carbon\Carbon;
use Database\Seeders\Showcase\Concerns\AnnouncesFixtures;
use Database\Seeders\Showcase\Concerns\SeedsShowcaseData;
use Database\Seeders\Showcase\Ledger\Concerns\BelongsToLedger;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\InvoiceItem;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Models\Wallet;
use Fleetbase\Ledger\Services\LedgerService;
use Fleetbase\Models\Company;
use Fleetbase\Models\Transaction;
use Illuminate\Database\Seeder;

/**
 * Four months of books that balance: September 2025 to January 2026.
 *
 * Ledger is double-entry — every journal debits one account and credits another for the same
 * amount — and every report (balance sheet, trial balance, income statement, cash flow, AR
 * aging) is computed from the journals. So nothing here writes a balance or a report figure:
 * each business event is posted through Ledger's own LedgerService::createJournalEntry, which
 * also recalculates the cached account balances. The books balance by construction, the way
 * they would in production.
 *
 * The chart of accounts is Ledger's default, created by its own CompanyObserver when the
 * showcase company was created; if it is missing, Ledger's seeder is run for the company.
 *
 * Invoices are created without model events — InvoiceObserver would otherwise start the
 * revenue lifecycle and post its own journals alongside these. Their due dates are chosen so
 * that, as of the capture date (2026-01-20, which capture/src/determinism.mjs also pins on
 * the report API calls), the AR aging has a balance in every bucket.
 *
 * All amounts are minor units (cents), Ledger's own convention.
 */
class LedgerSeeder extends Seeder
{
    use SeedsShowcaseData;
    use BelongsToLedger;
    use AnnouncesFixtures;

    protected array $accounts = [];
    protected string $currency = 'SGD';
    protected Company $company;

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }
        $this->company = $company;

        $this->purgeSeedData();
        if (!$this->loadAccounts()) {
            return;
        }

        $this->postOpeningBalance();
        $invoices = $this->seedInvoices();
        $this->postExpenses();
        $gateways = $this->seedGateways();

        $this->recalculateBalances();

        $wallet = Wallet::where('company_uuid', $company->uuid)->orderBy('created_at')->first();
        $this->announceFixtures(array_filter([
            'invoice-details' => $invoices['inv_04']->public_id ?? null,
            'gateway-details' => $gateways['gateway_stripe']->public_id ?? null,
            'wallet-details'  => $wallet?->public_id,
            'wallet-add-funds'      => $wallet?->public_id,
            'wallet-transfer-funds' => $wallet?->public_id,
        ]));

        $this->command?->info('Seeded ' . $this->seedName() . ' books for company: ' . $company->public_id);
    }

    public function purgeSeedData(): void
    {
        $this->purgeModel(Transaction::class);
        $this->purgeModel(Journal::class);
        $this->purgeModel(InvoiceItem::class);
        $this->purgeModel(Invoice::class);
        $this->purgeModel(Gateway::class);

        // Cached balances still include the journals just removed.
        if (isset($this->company)) {
            $this->recalculateBalances();
        }
    }

    protected function recalculateBalances(): void
    {
        Account::where('company_uuid', $this->company->uuid)->get()->each->updateBalance();
    }

    protected function loadAccounts(): bool
    {
        $codes = ['BANK-DEFAULT', 'AR-DEFAULT', 'AP-DEFAULT', 'TAX-PAYABLE', 'EQUITY-DEFAULT', 'REVENUE-DELIVERY',
                  'REVENUE-SERVICE-FEE', 'EXPENSE-DRIVER-PAYOUT', 'EXPENSE-GATEWAY-FEES', 'EXPENSE-OTHER', 'PREPAID-DEFAULT'];

        $find = fn () => Account::where('company_uuid', $this->company->uuid)->whereIn('code', $codes)->get()->keyBy('code');
        $accounts = $find();

        if ($accounts->count() < count($codes)) {
            // The CompanyObserver did not run for this company (Ledger installed after it was
            // created); run Ledger's own chart seeder rather than inventing accounts.
            (new \Fleetbase\Ledger\Seeders\LedgerSeeder())->runForCompany($this->company->uuid);
            $accounts = $find();
        }

        $missing = array_diff($codes, $accounts->keys()->all());
        if ($missing) {
            $this->command?->warn('Ledger fixtures skipped: chart of accounts lacks ' . implode(', ', $missing));

            return false;
        }

        $this->accounts = $accounts->all();
        $this->currency = $this->accounts['AR-DEFAULT']->currency ?: 'SGD';

        return true;
    }

    protected function post(string $debit, string $credit, int $amount, string $description, Carbon $date, array $extra = []): Journal
    {
        static $sequence = 0;
        $sequence++;

        return app(LedgerService::class)->createJournalEntry(
            $this->accounts[$debit],
            $this->accounts[$credit],
            $amount,
            $description,
            array_merge([
                'company_uuid'    => $this->company->uuid,
                'currency'        => $this->currency,
                'entry_date'      => $date,
                'is_system_entry' => false,
                'meta'            => $this->meta('journal_' . $sequence),
            ], $extra)
        );
    }

    protected function day(string $date): Carbon
    {
        return Carbon::parse($date . ' 10:00:00', 'Asia/Singapore');
    }

    protected function publicId(string $type, string $seedId): string
    {
        return $type . '_' . substr(hash('sha256', $this->seedName() . ':' . $seedId), 0, 10);
    }

    protected function postOpeningBalance(): void
    {
        $this->post('BANK-DEFAULT', 'EQUITY-DEFAULT', 25000000, 'Owner capital contribution', $this->day('2025-09-01'), ['reference' => 'CAP-2025-001']);
    }

    protected function customer(string $name): ?Contact
    {
        return Contact::where('company_uuid', $this->company->uuid)->where('name', $name)->first();
    }

    protected function seedInvoices(): array
    {
        // seed id => [customer, issued, due, status, paid (cents, null = in full), paid on, gateway, lines]
        // Lines: [description, quantity, unit price in cents].
        $invoices = [
            'inv_01' => ['Wei Lin Teo',  '2025-10-06', '2025-11-05', 'paid',    null,    '2025-11-03', 'stripe', [['Last-mile deliveries — CBD zone', 42, 1850], ['Same-day express deliveries', 12, 3200]]],
            'inv_02' => ['Farhan Yusof', '2025-10-20', '2025-11-19', 'paid',    null,    '2025-11-18', 'cash',   [['Airport freight transfers', 18, 6500]]],
            'inv_03' => ['Chloe Ng',     '2025-11-03', '2025-12-03', 'paid',    null,    '2025-12-01', 'stripe', [['Retail store replenishment runs', 26, 2400], ['Cold-chain handling surcharge', 6, 1500]]],
            'inv_04' => ['Wei Lin Teo',  '2025-11-10', '2025-12-10', 'partial', 250000,  '2025-12-12', 'stripe', [['Last-mile deliveries — CBD zone', 96, 1850], ['Failed-delivery return trips', 8, 1200], ['Weekend surcharge', 1, 18000]]],
            'inv_05' => ['Farhan Yusof', '2025-11-24', '2025-12-24', 'overdue', 0,       null,         null,     [['Airport freight transfers', 22, 6500]]],
            'inv_06' => ['Chloe Ng',     '2025-12-01', '2025-12-31', 'paid',    null,    '2025-12-29', 'stripe', [['Retail store replenishment runs', 31, 2400]]],
            'inv_07' => ['Wei Lin Teo',  '2025-10-13', '2025-11-12', 'overdue', 0,       null,         null,     [['Warehouse shuttle — Jurong to Tampines', 14, 4800]]],
            'inv_08' => ['Chloe Ng',     '2025-12-15', '2026-01-14', 'overdue', 0,       null,         null,     [['Festive peak deliveries', 64, 2100], ['Gift-wrap handling', 64, 300]]],
            'inv_09' => ['Wei Lin Teo',  '2026-01-05', '2026-02-04', 'sent',    0,       null,         null,     [['Last-mile deliveries — CBD zone', 58, 1850]]],
            'inv_10' => ['Farhan Yusof', '2026-01-12', '2026-02-11', 'sent',    0,       null,         null,     [['Airport freight transfers', 16, 6500], ['After-hours handover', 4, 4500]]],
            'inv_11' => ['Chloe Ng',     '2026-01-15', '2026-02-14', 'draft',   0,       null,         null,     [['Retail store replenishment runs', 12, 2400]]],
            'inv_12' => ['Farhan Yusof', '2025-09-22', '2025-10-22', 'overdue', 0,       null,         null,     [['Customs documentation handling', 9, 7500]]],
        ];

        $models = [];
        $number = 1040;
        foreach ($invoices as $seedId => [$name, $issued, $due, $status, $paid, $paidOn, $gateway, $lines]) {
            $customer = $this->customer($name);
            $subtotal = array_sum(array_map(fn ($l) => $l[1] * $l[2], $lines));
            $tax      = (int) round($subtotal * 0.09); // Singapore GST
            $total    = $subtotal + $tax;
            $paidAmount = $paid === null ? $total : $paid;
            $number++;

            /** @var Invoice $invoice */
            $invoice = $this->createRecord(Invoice::class, [
                '_key'          => $this->fixtureKey($seedId),
                'public_id'     => $this->publicId('invoice', $seedId),
                'company_uuid'  => $this->company->uuid,
                'created_by_uuid' => $this->company->owner_uuid,
                'customer_uuid' => $customer?->uuid,
                'customer_type' => $customer ? Contact::class : null,
                'number'        => 'INV-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT),
                'date'          => $this->day($issued),
                'due_date'      => $this->day($due),
                'subtotal'      => $subtotal,
                'tax'           => $tax,
                'total_amount'  => $total,
                'amount_paid'   => $paidAmount,
                'balance'       => $total - $paidAmount,
                'currency'      => $this->currency,
                'status'        => $status,
                'notes'         => 'Thank you for shipping with Fleetbase Pte Ltd.',
                'terms'         => 'Net 30. Please quote the invoice number with your payment.',
                'meta'          => $this->meta($seedId),
                'sent_at'       => $status === 'draft' ? null : $this->day($issued)->addHours(2),
                'paid_at'       => $status === 'paid' ? $this->day($paidOn) : null,
                'created_at'    => $this->day($issued),
                'updated_at'    => $this->day($paidOn ?? $issued),
            ], true);

            foreach ($lines as $index => [$description, $quantity, $unit]) {
                $amount = $quantity * $unit;
                $this->createRecord(InvoiceItem::class, [
                    '_key'         => $this->fixtureKey($seedId . '_line_' . $index),
                    'invoice_uuid' => $invoice->uuid,
                    'description'  => $description,
                    'quantity'     => $quantity,
                    'unit_price'   => $unit,
                    'amount'       => $amount,
                    'tax_rate'     => 9,
                    'tax_amount'   => (int) round($amount * 0.09),
                    'meta'         => $this->meta($seedId . '_line_' . $index),
                ], true);
            }

            // A draft is not yet a receivable: it posts nothing.
            if ($status !== 'draft') {
                $ref = ['reference' => $invoice->number, 'subject_uuid' => $invoice->uuid, 'subject_type' => Invoice::class];
                $this->post('AR-DEFAULT', 'REVENUE-DELIVERY', $subtotal, "Invoice {$invoice->number} — delivery services", $this->day($issued), $ref + ['journal_type' => 'revenue']);
                $this->post('AR-DEFAULT', 'TAX-PAYABLE', $tax, "Invoice {$invoice->number} — GST 9%", $this->day($issued), $ref);
            }

            if ($paidAmount > 0) {
                $transaction = $this->createRecord(Transaction::class, [
                    'public_id'      => $this->publicId('transaction', $seedId),
                    'company_uuid'   => $this->company->uuid,
                    'subject_uuid'   => $invoice->uuid,
                    'subject_type'   => Invoice::class,
                    'payer_uuid'     => $customer?->uuid,
                    'payer_type'     => $customer ? Contact::class : null,
                    'customer_uuid'  => $customer?->uuid,
                    'customer_type'  => $customer ? Contact::class : null,
                    'type'           => 'invoice_payment',
                    'direction'      => 'credit',
                    'status'         => 'success',
                    'amount'         => $paidAmount,
                    'fee_amount'     => $gateway === 'stripe' ? (int) round($paidAmount * 0.029) + 50 : 0,
                    'net_amount'     => $paidAmount - ($gateway === 'stripe' ? (int) round($paidAmount * 0.029) + 50 : 0),
                    'currency'       => $this->currency,
                    'gateway'        => $gateway,
                    'payment_method' => $gateway === 'stripe' ? 'card' : 'cash',
                    'payment_method_brand' => $gateway === 'stripe' ? 'visa' : null,
                    'reference'      => $invoice->number,
                    'description'    => "Payment for {$invoice->number}",
                    'meta'           => $this->meta($seedId . '_payment'),
                    'settled_at'     => $this->day($paidOn),
                    'created_at'     => $this->day($paidOn),
                    'updated_at'     => $this->day($paidOn),
                ], true);

                $this->post('BANK-DEFAULT', 'AR-DEFAULT', $paidAmount, "Payment received — {$invoice->number}", $this->day($paidOn), [
                    'reference' => $invoice->number, 'transaction_uuid' => $transaction->uuid, 'journal_type' => 'payment',
                ]);
                if ($gateway === 'stripe') {
                    $this->post('EXPENSE-GATEWAY-FEES', 'BANK-DEFAULT', (int) round($paidAmount * 0.029) + 50, "Stripe processing fee — {$invoice->number}", $this->day($paidOn), [
                        'reference' => $invoice->number, 'transaction_uuid' => $transaction->uuid,
                    ]);
                }
            }

            $models[$seedId] = $invoice;
        }

        return $models;
    }

    protected function postExpenses(): void
    {
        $monthly = [
            //  month          driver payouts  fuel     end of period
            ['2025-09-30', 520000, 168000],
            ['2025-10-31', 620000, 185000],
            ['2025-11-30', 680000, 201000],
            ['2025-12-31', 710000, 224000],
            ['2026-01-15', 340000, 98000],
        ];
        foreach ($monthly as [$date, $payouts, $fuel]) {
            $month = Carbon::parse($date)->format('F Y');
            $this->post('EXPENSE-DRIVER-PAYOUT', 'BANK-DEFAULT', $payouts, "Driver payouts — {$month}", $this->day($date), ['reference' => 'PAY-' . Carbon::parse($date)->format('Ym')]);
            $this->post('EXPENSE-OTHER', 'BANK-DEFAULT', $fuel, "Fuel — fleet card statement, {$month}", $this->day($date), ['reference' => 'FUEL-' . Carbon::parse($date)->format('Ym')]);
        }

        $this->post('PREPAID-DEFAULT', 'BANK-DEFAULT', 360000, 'Fleet insurance — annual premium', $this->day('2025-10-01'), ['reference' => 'INS-2025-10']);
        // An unpaid supplier bill keeps Accounts Payable on the balance sheet.
        $this->post('EXPENSE-OTHER', 'AP-DEFAULT', 142000, 'Isuzu NPR (WRK-03) service — supplier invoice', $this->day('2026-01-09'), ['reference' => 'SUP-88214']);
    }

    protected function seedGateways(): array
    {
        $gateways = [
            'gateway_stripe' => ['Stripe', 'stripe', 'Card payments for customer invoices', true, 'sandbox',
                ['publishable_key' => 'pk_test_showcase' . substr(hash('sha256', 'pk'), 0, 24), 'secret_key' => 'sk_test_showcase' . substr(hash('sha256', 'sk'), 0, 24), 'webhook_secret' => 'whsec_showcase' . substr(hash('sha256', 'wh'), 0, 24)],
                ['purchase', 'refund', 'webhooks']],
            'gateway_cash'   => ['Cash on Delivery', 'cash', 'Cash collected by drivers at handover', false, 'production', [], ['purchase']],
        ];

        $models = [];
        foreach ($gateways as $seedId => [$name, $driver, $description, $sandbox, $environment, $config, $capabilities]) {
            $models[$seedId] = $this->createRecord(Gateway::class, [
                'public_id'       => $this->publicId('gateway', $seedId),
                'company_uuid'    => $this->company->uuid,
                'created_by_uuid' => $this->company->owner_uuid,
                'name'            => $name,
                'driver'          => $driver,
                'description'     => $description,
                'config'          => $config,
                'capabilities'    => $capabilities,
                'is_sandbox'      => $sandbox,
                'environment'     => $environment,
                'status'          => 'active',
                'return_url'      => 'https://pay.fleetbase-demo.example/return',
                'webhook_url'     => 'http://localhost/ledger/v1/webhooks/' . $driver,
                'meta'            => $this->meta($seedId),
                'created_at'      => $this->day('2025-09-01'),
                'updated_at'      => $this->day('2025-09-01'),
            ], true);
        }

        return $models;
    }
}
