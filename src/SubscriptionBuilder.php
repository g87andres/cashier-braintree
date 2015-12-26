<?php

namespace LimeDeck\CashierBraintree;

use Braintree\Discount;
use Braintree\Plan;
use Braintree\Subscription as BraintreeSubscription;
use Carbon\Carbon;

class SubscriptionBuilder
{
    /**
     * The user model that is subscribing.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $user;

    /**
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;

    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $plan;

    /**
     * The number of trial days to apply to the subscription.
     *
     * @var int|null
     */
    protected $trialDays;

    /**
     * The coupon code being applied to the customer.
     *
     * @var string|null
     */
    protected $coupon;

    /**
     * The type of coupon code applied to the customer.
     *
     * @var bool
     */
    protected $couponPercentage = false;

    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $user
     * @param  string  $name
     * @param  string  $plan
     * @return void
     */
    public function __construct($user, $name, $plan)
    {
        $this->user = $user;
        $this->name = $name;
        $this->plan = $plan;
    }

    /**
     * Specify the ending date of the trial.
     *
     * @param  int  $trialDays
     * @return $this
     */
    public function trialDays($trialDays)
    {
        $this->trialDays = $trialDays;

        return $this;
    }

    /**
     * The coupon to apply to a new subscription.
     *
     * @param  string $coupon
     * @param  bool   $percentage
     * @return $this
     */
    public function withCoupon($coupon, $percentage = false)
    {
        $this->coupon = $coupon;
        $this->couponPercentage = $percentage;

        return $this;
    }

    /**
     * Add a new Stripe subscription to the user.
     *
     * @param  array  $options
     * @return \Laravel\Cashier\Subscription
     */
    public function add(array $options = [])
    {
        return $this->create(null, $options);
    }

    /**
     * Create a new Braintree subscription.
     *
     * @param  string|null  $nonce
     * @param  array  $options
     * @return \Laravel\Cashier\Subscription
     */
    public function create($nonce = null, array $options = [])
    {
        $customer = $this->getBraintreeCustomer($nonce, $options);

        $subscriptionOptions = [
            'paymentMethodToken' => $customer->paymentMethods[0]->token,
            'planId' => $this->plan,
            'trialDuration' => $this->trialDays ?: 0,
            'trialDurationUnit' => 'day',
            'trialPeriod' => $this->trialDays ? true : false,
        ];

        if ($this->coupon) {

            $braintreeCoupons = Discount::all();
            $braintreePlans = Plan::all();

            $coupon = null;
            $plan = null;

            foreach ($braintreeCoupons as $braintreeCoupon) {
                if ($braintreeCoupon->id == $this->coupon) {
                    $coupon = $braintreeCoupon;
                }
            }

            foreach ($braintreePlans as $braintreePlan) {
                if ($braintreePlan->id == $this->plan) {
                    $plan = $braintreePlan;
                }
            }

            $amount = $this->couponPercentage ? ($coupon->amount/100) * $plan->price : $coupon->amount;

            $subscriptionOptions['discounts'] = [
                'add' => [
                    [
                        'inheritedFromId' => $this->coupon,
                        'amount' => $amount
                    ]
                ],
            ];
        }

        $result = BraintreeSubscription::create($subscriptionOptions);

        return $this->user->subscriptions()->create([
            'name' => $this->name,
            'braintree_id' => $result->subscription->id,
            'braintree_plan' => $this->plan,
            'quantity' => 1,//$this->quantity,
            'trial_ends_at' => $this->trialDays ? Carbon::now()->addDays($this->trialDays) : null,
            'ends_at' => null,
        ]);
    }

    /**
     * Get the Stripe customer instance for the current user and token.
     *
     * @param  string|null  $nonce
     * @param  array  $options
     * @return \Braintree\Customer
     */
    protected function getBraintreeCustomer($nonce = null, array $options = [])
    {
        if (! $this->user->braintree_id) {
            $customer = $this->user->createAsBraintreeCustomer(
                $nonce, $options
            );
        } else {
            $customer = $this->user->asBraintreeCustomer();

            if ($nonce) {
                $this->user->updateCard($nonce);
            }
        }

        return $customer;
    }

    /**
     * Get the trial ending date for the Stripe payload.
     *
     * @return int|null
     */
    protected function getTrialEndForPayload()
    {
        if ($this->trialDays) {
            return Carbon::now()->addDays($this->trialDays)->getTimestamp();
        }
    }

    /**
     * Get the tax percentage for the Stripe payload.
     *
     * @return int|null
     */
    protected function getTaxPercentageForPayload()
    {
        if ($taxPercentage = $this->user->taxPercentage()) {
            return $taxPercentage;
        }
    }
}
