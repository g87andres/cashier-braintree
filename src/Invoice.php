<?php

namespace LimeDeck\CashierBraintree;

use Braintree\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class Invoice
{
    /**
     * The user instance.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $user;

    /**
     * The Stripe invoice instance.
     *
     * @var \Braintree\Transaction
     */
    protected $invoice;

    /**
     * Create a new invoice instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $user
     * @param  \Braintree\Transaction  $invoice
     * @return void
     */
    public function __construct($user, Transaction $invoice)
    {
        $this->user = $user;
        $this->invoice = $invoice;
    }

    /**
     * Get a Carbon date for the invoice.
     *
     * @param  \DateTimeZone|string  $timezone
     * @return \Carbon\Carbon
     */
    public function date($timezone = null)
    {
        $carbon = Carbon::instance($this->invoice->createdAt);

        return $timezone ? $carbon->setTimezone($timezone) : $carbon;
    }

    /**
     * Get the total amount that was paid (or will be paid).
     *
     * @return string
     */
    public function total()
    {
        return $this->formatAmount(
            $this->totalCalculation()
        );
    }

    protected function totalCalculation()
    {
        return max(0, $this->invoice->amount - $this->rawStartingBalance());
    }

    /**
     * Get the total of the invoice (before discounts).
     *
     * @return string
     */
    public function subtotal()
    {
        return $this->formatAmount(
            $this->subtotalCalculation()
        );
    }

    protected function subtotalCalculation()
    {
        return max(0, $this->invoice->amount + $this->discount() - $this->rawStartingBalance());
    }

    /**
     * Determine if the account had a starting balance.
     *
     * @return bool
     */
    public function hasStartingBalance()
    {
        return $this->rawStartingBalance() > 0;
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
        return count($this->invoice->discounts) > 0;
    }

    /**
     * Get the discount amount.
     *
     * @return string
     */
    public function discount()
    {
        $amount = 0;

        foreach ($this->invoice->discounts as $discount) {
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
        return isset($this->invoice->discounts[0]) ? $this->invoice->discounts[0] : null;
    }

    /**
     * Determine if the discount is a percentage.
     *
     * @return bool
     * @throws \Exception
     */
    public function discountIsPercentage()
    {
        throw new \Exception('Somewhat hard to figure out, since discounts are applied as value not percentage.');
    }

    /**
     * Get the discount percentage for the invoice.
     *
     * @return int
     */
    public function percentOff()
    {
        return max(0, round($this->discount()/$this->subtotalCalculation()*100))."%";
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
     * @param  string  $type
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
     * @param  int  $amount
     * @return string
     */
    protected function formatAmount($amount)
    {
        return Cashier::formatAmount($amount);
    }

    /**
     * Get the View instance for the invoice.
     *
     * @param  array  $data
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
     * @param  array  $data
     * @return string
     */
    public function pdf(array $data)
    {
        if (! defined('DOMPDF_ENABLE_AUTOLOAD')) {
            define('DOMPDF_ENABLE_AUTOLOAD', false);
        }

        if (file_exists($configPath = base_path().'/vendor/dompdf/dompdf/dompdf_config.inc.php')) {
            require_once $configPath;
        }

        $dompdf = new \DOMPDF;

        $dompdf->load_html($this->view($data)->render());

        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Create an invoice download response.
     *
     * @param  array   $data
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function download(array $data)
    {
        $filename = $data['product'].'_'.$this->date()->month.'_'.$this->date()->year.'.pdf';

        return new Response($this->pdf($data), 200, [
            'Content-Description' => 'File Transfer',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Transfer-Encoding' => 'binary',
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Get the raw starting balance for the invoice.
     *
     * @return int
     */
    protected function rawStartingBalance()
    {
        return 0;
    }

    /**
     * Get the Stripe invoice instance.
     *
     * @return \Braintree\Transaction
     */
    public function asBraintreeTransaction()
    {
        return $this->invoice;
    }

    /**
     * Dynamically get values from the Stripe invoice.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->invoice->{$key};
    }
}
