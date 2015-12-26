<?php

namespace LimeDeck\CashierBraintree;

use Braintree\PaymentMethod;
use Braintree\Transaction;
use Braintree\TransactionSearch;
use Illuminate\Support\Collection;
use Braintree\Customer as BraintreeCustomer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait Billable
{
    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param  int  $amount
     * @param  string $nonce
     * @param  array  $options
     * @return bool|mixed
     */
    public function charge($amount, $nonce, array $options = [])
    {
        $options = array_merge([
            'amount' => $amount,
            'paymentMethodNonce' => $nonce,
            'options' => [
                'submitForSettlement' => true
            ]
        ], $options);

        $result = Transaction::sale($options);

        if ($result->success) {
            return $result->transaction;
        }

        return false;
    }

    /**
     * Begin creating a new subscription.
     *
     * @param  string  $subscription
     * @param  string  $plan
     * @return \Laravel\Cashier\SubscriptionBuilder
     */
    public function newSubscription($subscription, $plan)
    {
        return new SubscriptionBuilder($this, $subscription, $plan);
    }

    /**
     * Determine if the user has a given subscription.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function subscribed($subscription = 'default', $plan = null)
    {
        $subscription = $this->subscription($subscription);

        if (is_null($plan)) {
            return $subscription && $subscription->active();
        } else {
            return $subscription && $subscription->active() && $subscription->braintree_plan === $plan;
        }
    }

    /**
     * Get a subscription instance by name.
     *
     * @param  string  $subscription
     * @return \Laravel\Cashier\Subscription|null
     */
    public function subscription($subscription = 'default')
    {
        return $this->subscriptions->sortByDesc(function ($value) {
            return $value->created_at->getTimestamp();
        })
        ->first(function ($key, $value) use ($subscription) {
            return $value->name === $subscription;
        });
    }

    /**
     * Get all of the subscriptions for the user.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class)->orderBy('created_at', 'desc');
    }

    /**
     * Invoice the billable entity outside of regular billing cycle.
     *
     * @return bool
     */
    public function invoice()
    {
        // TODO: can this be done??
        if ($this->stripe_id) {
            try {
                StripeInvoice::create(['customer' => $this->stripe_id], $this->getStripeKey())->pay();
            } catch (StripeErrorInvalidRequest $e) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the entity's upcoming invoice.
     *
     * @return \Laravel\Cashier\Invoice|null
     */
    public function upcomingInvoice()
    {
        // TODO: can this be done??
        try {
            $stripeInvoice = StripeInvoice::upcoming(
                ['customer' => $this->stripe_id], ['api_key' => $this->getStripeKey()]
            );

            return new Invoice($this, $stripeInvoice);
        } catch (StripeErrorInvalidRequest $e) {
            //
        }
    }

    /**
     * Find an invoice by ID.
     *
     * @param  string  $id
     * @return \Laravel\Cashier\Invoice|null
     */
    public function findInvoice($id)
    {
        try {
            return new Invoice($this, Transaction::find($id));
        } catch (Exception $e) {
            //
        }
    }

    /**
     * Find an invoice or throw a 404 error.
     *
     * @param  string  $id
     * @return \Laravel\Cashier\Invoice
     */
    public function findInvoiceOrFail($id)
    {
        $invoice = $this->findInvoice($id);

        if (is_null($invoice)) {
            throw new NotFoundHttpException;
        } else {
            return $invoice;
        }
    }

    /**
     * Create an invoice download Response.
     *
     * @param  string  $id
     * @param  array   $data
     * @param  string  $storagePath
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function downloadInvoice($id, array $data, $storagePath = null)
    {
        return $this->findInvoiceOrFail($id)->download($data, $storagePath);
    }

    /**
     * Get a collection of the entity's invoices.
     *
     * @param  bool  $includePending
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function invoices($includePending = false, $parameters = [])
    {
        $invoices = [];

        $customer = $this->asBraintreeCustomer();

        $parameters = array_merge([
            TransactionSearch::customerId()->is($customer->id)
        ], $parameters);

        $braintreeTransactions = Transaction::search($parameters);

        // Here we will loop through the Stripe invoices and create our own custom Invoice
        // instances that have more helper methods and are generally more convenient to
        // work with than the plain Stripe objects are. Then, we'll return the array.
        if (! is_null($braintreeTransactions)) {
            foreach ($braintreeTransactions as $transaction) {
                // TODO: SETTLED?
                if (($transaction->status == Transaction::SUBMITTED_FOR_SETTLEMENT) || $includePending) {
                    $invoices[] = new Invoice($this, $transaction);
                }
            }
        }

        return new Collection($invoices);
    }

    /**
     * Get an array of the entity's invoices.
     *
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function invoicesIncludingPending(array $parameters = [])
    {
        return $this->invoices(true, $parameters);
    }

    /**
     * Update customer's credit card.
     *
     * @param  string  $nonce
     * @return bool
     */
    public function updateCard($nonce)
    {
        $customer = $this->asBraintreeCustomer();

        $result = PaymentMethod::update($customer->paymentMethods[0], [
            'paymentMethodNonce' => $nonce
        ]);

        if ($result->success) {
            $card = $result->paymentMethod;

            $this->card_brand = $card->cardType;
            $this->card_last_four = $card->last4;

            $this->save();
        }

        return false;
    }

    /**
     * Determine if the entity is on the given plan.
     *
     * @param  string  $plan
     * @return bool
     */
    public function onPlan($plan)
    {
        return ! is_null($this->subscriptions->first(function ($key, $value) use ($plan) {
            return $value->stripe_plan === $plan;
        }));
    }

    /**
     * Determine if the entity has a Stripe customer ID.
     *
     * @return bool
     */
    public function hasBraintreeId()
    {
        return ! is_null($this->braintree_id);
    }

    /**
     * Create a Stripe customer for the given user.
     *
     * @param  string $nonce
     * @param  array  $options
     * @return bool|\Braintree\Customer
     */
    public function createAsBraintreeCustomer($nonce, array $options = [])
    {
        // Here we will create the customer instance on Braintree and store the provided ID of the
        // user from Braintree. This ID will correspond with the Braintree user instances
        // and allow us to retrieve users from Braintree later when we need to work.
        $result = BraintreeCustomer::create(
            array_merge($options, [
                'email' => $this->email,
                'paymentMethodNonce' => $nonce,
                'creditCard' => [
                    'options' => [
                        'makeDefault' => true
                    ]
                ]
            ])
        );

        if ($result->success) {
            $this->braintree_id = $result->customer->id;

            $this->save();

            return $result->customer;
        }

        return false;
    }

    /**
     * Get the Stripe customer for the user.
     *
     * @return \Braintree\Customer
     */
    public function asBraintreeCustomer()
    {
        return BraintreeCustomer::find($this->braintree_id);
    }

    /**
     * Get the tax percentage to apply to the subscription.
     *
     * @return int
     */
    public function taxPercentage()
    {
        return 0;
    }

}
