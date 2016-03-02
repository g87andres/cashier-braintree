<?php

namespace LimeDeck\CashierBraintree;

use Braintree\Customer as BraintreeCustomer;
use Braintree\PaymentMethod;
use Braintree\PayPalAccount;
use Braintree\Subscription as BraintreeSubscription;
use Braintree\SubscriptionSearch;
use Braintree\Transaction;
use Braintree\TransactionSearch;
use Exception;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait Billable
{
    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param int    $amount
     * @param string $nonce
     * @param array  $options
     *
     * @return bool|mixed
     */
    public function charge($amount, $nonce, array $options = [])
    {
        $options = array_merge([
            'amount'             => $amount,
            'paymentMethodNonce' => $nonce,
            'options'            => [
                'submitForSettlement' => true,
            ],
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
     * @param string $subscription
     * @param string $plan
     *
     * @return \LimeDeck\CashierBraintree\SubscriptionBuilder
     */
    public function newSubscription($subscription, $plan)
    {
        return new SubscriptionBuilder($this, $subscription, $plan);
    }

    /**
     * Determine if the user has a given subscription.
     *
     * @param string      $subscription
     * @param string|null $plan
     *
     * @return bool
     */
    public function subscribed($subscription = 'default', $plan = null)
    {
        $subscription = $this->subscription($subscription);

        if (is_null($plan)) {
            return $subscription && $subscription->active();
        } else {
            return $subscription && $subscription->active() && $subscription->onPlan($plan);
        }
    }

    /**
     * Get a subscription instance by name.
     *
     * @param string $name
     *
     * @return \LimeDeck\CashierBraintree\Subscription|null
     */
    public function subscription($name = 'default')
    {
        return $this->subscriptions->sortByDesc(function (Subscription $subscription) {
            return $subscription->created_at->getTimestamp();
        })
        ->first(function ($key, Subscription $subscription) use ($name) {
            return $subscription->name === $name;
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
     * Find an invoice for subscription by transaction ID.
     *
     * @param string $id
     *
     * @return \LimeDeck\CashierBraintree\Invoice|null
     */
    public function findInvoice($id)
    {
        $transaction = Transaction::find($id);

        $subscription = BraintreeSubscription::find($transaction->subscriptionId);

        try {
            return new Invoice($this, $subscription, $transaction);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Find an invoice or throw a 404 error.
     *
     * @param string $id
     *
     * @return \LimeDeck\CashierBraintree\Invoice
     */
    public function findInvoiceOrFail($id)
    {
        $invoice = $this->findInvoice($id);

        if (is_null($invoice)) {
            throw new NotFoundHttpException();
        } else {
            return $invoice;
        }
    }

    /**
     * Create an invoice download Response.
     *
     * @param string $id
     * @param array  $data
     * @param string $storagePath
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function downloadInvoice($id, array $data, $storagePath = null)
    {
        return $this->findInvoiceOrFail($id)->download($data, $storagePath);
    }

    /**
     * Get a collection of the entity's invoices.
     *
     * @param bool  $includePending
     * @param array $parameters
     *
     * @return \Illuminate\Support\Collection
     */
    public function invoices($includePending = false, $parameters = [])
    {
        $invoices = new Collection();

        // return empty array if user does not have a braintree customer ID yet
        if(is_null($this->braintree_id))
            return [];

        $customer = $this->asBraintreeCustomer();

        $parameters = array_merge([
            TransactionSearch::customerId()->is($customer->id),
        ], $parameters);

        $braintreeTransactions = Transaction::search($parameters);

        $subscriptionIds = [];

        foreach ($braintreeTransactions as $braintreeTransaction) {
            $subscriptionIds[] = $braintreeTransaction->subscriptionId;
        }

        $braintreeSubscriptions = BraintreeSubscription::fetch([], array_unique($subscriptionIds));

        // Here we will loop through the Braintree invoices and create our own custom Invoice
        // instances that have more helper methods and are generally more convenient to
        // work with than the plain Braintree objects are. Then, we'll return the array.
        if (!is_null($braintreeSubscriptions)) {
            foreach ($braintreeSubscriptions as $subscription) {
                if (($subscription->status == BraintreeSubscription::ACTIVE) || $includePending) {
                    foreach ($subscription->transactions as $transaction) {
                        $invoices->push(new Invoice($this, $subscription, $transaction));
                    }
                }
            }
        }

        return $invoices->sortByDesc(function($invoice) {
            return $invoice->date();
        });
    }

    /**
     * Get an array of the entity's invoices.
     *
     * @param array $parameters
     *
     * @return \Illuminate\Support\Collection
     */
    public function invoicesIncludingPending(array $parameters = [])
    {
        return $this->invoices(true, $parameters);
    }

    /**
     * Update customer's credit card.
     *
     * @param string $nonce
     *
     * @return bool
     */
    public function updateCard($nonce)
    {
        $customer = $this->asBraintreeCustomer();

        $result = PaymentMethod::update($customer->paymentMethods[0]->token, [
            'paymentMethodNonce' => $nonce,
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
     * @param string $plan
     *
     * @return bool
     */
    public function onPlan($plan)
    {
        return !is_null($this->subscriptions->first(function ($key, Subscription $subscription) use ($plan) {
            return $subscription->onPlan($plan);
        }));
    }

    /**
     * Determine if the entity has a Braintree customer ID.
     *
     * @return bool
     */
    public function hasBraintreeId()
    {
        return !is_null($this->braintree_id);
    }

    /**
     * Create a Braintree customer for the given user.
     *
     * @param string $nonce
     * @param array  $options
     *
     * @return bool|\Braintree\Customer
     */
    public function createAsBraintreeCustomer($nonce, array $options = [])
    {
        // Here we will create the customer instance on Braintree and store the provided ID of the
        // user from Braintree. This ID will correspond with the Braintree user instances
        // and allow us to retrieve users from Braintree later when we need to work.
        $result = BraintreeCustomer::create(
            array_merge($options, [
                'email'              => $this->email,
                'paymentMethodNonce' => $nonce,
                'creditCard'         => [
                    'options' => [
                        'makeDefault' => true,
                    ],
                ],
            ])
        );

        if ($result->success) {
            $this->braintree_id = $result->customer->id;

            $paymentMethod = $result->customer->paymentMethods[0];

            if ($paymentMethod instanceof PayPalAccount) {
                $this->payment_type = 'paypal';
            } else {
                $this->payment_type = 'card';
                $this->card_brand = $paymentMethod->cardType;
                $this->card_last_four = $paymentMethod->last4;
            }

            $this->save();

            return $result->customer;
        }

        return false;
    }

    /**
     * Get the Braintree customer for the user.
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
