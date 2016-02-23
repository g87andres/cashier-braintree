<?php

namespace LimeDeck\CashierBraintree;

use Braintree\Transaction as BraintreeTransaction;
use Braintree\Subscription as BraintreeSubscription;
use Carbon\Carbon;
use DOMPDF;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class Invoice
{
    /**
     * The user instance.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    public $user;

    /**
     * The Braintree subscription instance.
     *
     * @var \Braintree\Subscription
     */
    public $subscription;

    /**
     * The Braintree transaction instance.
     *
     * @var \Braintree\Transaction
     */
    public $transaction;

    /**
     * Create a new invoice instance.
     *
     * @param \Illuminate\Database\Eloquent\Model $user
     * @param \Braintree\Subscription             $subscription
     * @param \Braintree\Transaction              $transaction
     */
    public function __construct($user, BraintreeSubscription $subscription, BraintreeTransaction $transaction)
    {
        $this->user = $user;
        $this->subscription = $subscription;
        $this->transaction = $transaction;
    }

    /**
     * Get a Carbon date for the invoice.
     *
     * @param \DateTimeZone|string $timezone
     *
     * @return \Carbon\Carbon
     */
    public function date($timezone = null)
    {
        $carbon = Carbon::instance($this->transaction->createdAt);

        return $timezone ? $carbon->setTimezone($timezone) : $carbon;
    }

    /**
     * Get the total amount that was paid (or will be paid) as money formatted value.
     *
     * @return string
     */
    public function total()
    {
        return $this->formatAmount(
            max(0, $this->transaction->amount)
        );
    }

    /**
     * Get the total amount that was paid (or will be paid).
     *
     * @return mixed
     */
    protected function totalCalculation()
    {
        return max(0, $this->transaction->amount);
    }

    /**
     * Get the total of the invoice (before discounts) as money formatted value.
     *
     * @return string
     */
    public function subtotal()
    {
        return $this->formatAmount(
            $this->subtotalCalculation()
        );
    }

    /**
     * Get the total of the invoice (before discounts).
     *
     * @return mixed
     */
    protected function subtotalCalculation()
    {
        return max(0, $this->transaction->amount + $this->discount() - $this->rawStartingBalance());
    }

    /**
     * Determine if the account had a starting balance.
     *
     * @return bool
     */
    public function hasStartingBalance()
    {
        return $this->rawStartingBalance() != 0;
    }

    /**
     * Get the starting balance for the invoice.
     *
     * @return string
     */
    public function startingBalance()
    {
        return $this->formatAmount($this->rawStartingBalance());
    }

    /**
     * Determine if the invoice has a discount.
     *
     * @return bool
     */
    public function hasDiscount()
    {
        return count($this->transaction->discounts) > 0;
    }

    /**
     * Get the discount amount.
     *
     * @return string
     */
    public function discount()
    {
        $amount = 0;

        foreach ($this->transaction->discounts as $discount) {
            $amount += $discount->amount;
        }

        return $amount;
    }

    /**
     * Get the coupon code applied to the invoice.
     *
     * @return string|null
     */
    public function coupon()
    {
        return isset($this->transaction->discounts[0]) ? $this->transaction->discounts[0] : null;
    }

    /**
     * Get the discount percentage for the invoice.
     *
     * @return int
     */
    public function percentOff()
    {
        return max(0, round($this->discount() / $this->subtotalCalculation() * 100)).'%';
    }

    /**
     * Get the discount amount for the invoice.
     *
     * @return string
     */
    public function amountOff()
    {
        return $this->formatAmount($this->discount());
    }

    /**
     * Get all of the "invoice item" line items.
     *
     * @return array
     */
    public function invoiceItems()
    {
        return $this->invoiceItemsByType('invoiceitem');
    }

    /**
     * Get all of the "subscription" line items.
     *
     * @return array
     */
    public function subscriptions()
    {
        return $this->invoiceItemsByType('subscription');
    }

    /**
     * Get all of the invoie items by a given type.
     *
     * @param string $type
     *
     * @return array
     */
    public function invoiceItemsByType($type)
    {
        $lineItems = [];

        if (isset($this->lines->data)) {
            foreach ($this->lines->data as $line) {
                if ($line->type == $type) {
                    $lineItems[] = new InvoiceItem($this->user, $line);
                }
            }
        }

        return $lineItems;
    }

    /**
     * Format the given amount into a string based on the user's preferences.
     *
     * @param int $amount
     *
     * @return string
     */
    protected function formatAmount($amount)
    {
        return Cashier::formatAmount($amount);
    }

    /**
     * Get the View instance for the invoice.
     *
     * @param array $data
     *
     * @return \Illuminate\View\View
     */
    public function view(array $data)
    {
        return View::make('cashier::receipt', array_merge(
            $data, ['invoice' => $this, 'user' => $this->user]
        ));
    }

    /**
     * Capture the invoice as a PDF and return the raw bytes.
     *
     * @param array $data
     *
     * @return string
     */
    public function pdf(array $data)
    {
        if (!defined('DOMPDF_ENABLE_AUTOLOAD')) {
            define('DOMPDF_ENABLE_AUTOLOAD', false);
        }

        if (file_exists($configPath = base_path().'/vendor/dompdf/dompdf/dompdf_config.inc.php')) {
            require_once $configPath;
        }

        $dompdf = new DOMPDF();

        $dompdf->load_html($this->view($data)->render());

        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Create an invoice download response.
     *
     * @param array $data
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function download(array $data)
    {
        $filename = $data['product'].'_'.$this->date()->month.'_'.$this->date()->year.'.pdf';

        return new Response($this->pdf($data), 200, [
            'Content-Description'       => 'File Transfer',
            'Content-Disposition'       => 'attachment; filename="'.$filename.'"',
            'Content-Transfer-Encoding' => 'binary',
            'Content-Type'              => 'application/pdf',
        ]);
    }

    /**
     * Get the raw starting balance for the invoice.
     *
     * @return int
     */
    protected function rawStartingBalance()
    {
        $balance = $this->subscription->statusHistory[0]->balance;

        return isset($balance) ? $balance : 0;
    }

    /**
     * Get the Braintree invoice instance.
     *
     * @return \Braintree\Subscription
     */
    public function asBraintreeSubscription()
    {
        return $this->subscription;
    }

    /**
     * Dynamically get values from the Braintree transaction.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function __get($key)
    {
        return $this->transaction->{$key};
    }
}
