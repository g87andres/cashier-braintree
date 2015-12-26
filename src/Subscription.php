<?php

namespace LimeDeck\CashierBraintree;

use Braintree\Subscription as BraintreeSubscription;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'trial_ends_at', 'ends_at',
        'created_at', 'updated_at',
    ];

    /**
     * Get the user that owns the subscription.
     */
    public function user()
    {
        $model = getenv('STRIPE_MODEL') ?: config('services.stripe.model');

        return $this->belongsTo($model, 'user_id');
    }

    /**
     * Determine if the subscrition is active.
     *
     * @return bool
     */
    public function active()
    {
        return is_null($this->ends_at) || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is no longer active.
     *
     * @return bool
     */
    public function cancelled()
    {
        return ! is_null($this->ends_at);
    }

    /**
     * Determine if the subscription is within its trial period.
     *
     * @return bool
     */
    public function onTrial()
    {
        if (! is_null($this->trial_ends_at)) {
            return Carbon::today()->lt($this->trial_ends_at);
        } else {
            return false;
        }
    }

    /**
     * Determine if the subscription is within its grace period after cancellation.
     *
     * @return bool
     */
    public function onGracePeriod()
    {
        if (! is_null($endsAt = $this->ends_at)) {
            return Carbon::now()->lt(Carbon::instance($endsAt));
        } else {
            return false;
        }
    }

    /**
     * Apply a coupon to the subscription.
     *
     * @param  string  $coupon
     * @return void
     */
    public function applyCoupon($coupon)
    {
        $subscription = $this->asBraintreeSubscription();

        BraintreeSubscription::update($subscription->id, [
            'discounts' => [
                'add' => [
                    ['inheritedFromId' => $coupon]
                ],
            ]
        ]);
    }

    /**
     * Swap the subscription to a new Braintree plan.
     *
     * @param  string  $plan
     * @return bool|$this
     */
    public function swap($plan)
    {
        $subscription = $this->asBraintreeSubscription();

        $changes = [
            'planId' => $plan,
            'options' => [
                'prorateCharges' => true
            ]
        ];

        // If no specific trial end date has been set, the default behavior should be
        // to maintain the current trial state, whether that is "active" or to run
        // the swap out with the exact number of days left on this current plan.
        if ($this->onTrial()) {
            //TODO: return new subscription with shifted start by $this->trial_ends_at
        }

        $result = BraintreeSubscription::update($subscription->id, $changes);

        if ($result->success) {
            // TODO: invoice user in some magical way
//        $this->user->invoice();

            $this->fill(['braintree_plan' => $plan])->save();

            return $this;
        }

        return false;
    }

    /**
     * Cacnel the subscription at the end of the billing period.
     *
     * @return $this
     */
    public function cancel()
    {
        $subscription = $this->asBraintreeSubscription();

        BraintreeSubscription::cancel($subscription->id);

        // If the user was on trial, we will set the grace period to end when the trial
        // would have ended. Otherwise, we'll retrieve the end of the billing period
        // period and make that the end of the grace period for this current user.
        if ($this->onTrial()) {
            $this->ends_at = $this->trial_ends_at;
        } else {
            $this->ends_at = $subscription->billingPeriodEndDate;
        }

        $this->save();

        return $this;
    }

    /**
     * Cancel the subscription immediately.
     *
     * @return $this
     */
    public function cancelNow()
    {
        $subscription = $this->asBraintreeSubscription();

        BraintreeSubscription::cancel($subscription->id);

        $this->markAsCancelled();

        return $this;
    }

    /**
     * Mark the subscription as cancelled.
     *
     * @return void
     */
    public function markAsCancelled()
    {
        $this->fill(['ends_at' => Carbon::now()])->save();
    }

    /**
     * Resume the cancelled subscription.
     *
     * @return $this
     * @throws \Exception
     */
    public function resume()
    {
        throw new \Exception('Cannot be implemented with Braintree.');
    }

    /**
     * Get the subscription as a Stripe subscription object.
     *
     * @return BraintreeSubscription
     */
    public function asBraintreeSubscription()
    {
        return BraintreeSubscription::find($this->braintree_id);
    }
}
