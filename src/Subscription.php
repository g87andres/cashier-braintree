<?php

namespace LimeDeck\CashierBraintree;

use Braintree\Subscription as BraintreeSubscription;
use Carbon\Carbon;
use Exception;
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
        $model = getenv('BRAINTREE_MODEL') ?: config('services.braintree.model');

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
        return !is_null($this->ends_at);
    }

    /**
     * Determine if the subscription is valid for a specific plan.
     *
     * @param string $plan
     *
     * @return bool
     */
    public function onPlan($plan)
    {
        return $this->braintree_plan === $plan;
    }

    /**
     * Determine if the subscription is within its trial period.
     *
     * @return bool
     */
    public function onTrial()
    {
        if (!is_null($this->trial_ends_at)) {
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
        if (!is_null($endsAt = $this->ends_at)) {
            return Carbon::now()->lt(Carbon::instance($endsAt));
        } else {
            return false;
        }
    }

    /**
     * Apply a coupon to the subscription.
     *
     * @param string $coupon
     *
     * @return void
     *
     * @throws \Exception
     */
    public function applyCoupon($coupon)
    {
        $subscription = $this->asBraintreeSubscription();

        $result = BraintreeSubscription::update($subscription->id, [
            'discounts' => [
                'add' => [
                    ['inheritedFromId' => $coupon],
                ],
            ],
        ]);

        if (! $result->success) {
            throw new Exception('Coupon was not applied');
        }
    }

    /**
     * Swap the subscription to a new Braintree plan.
     *
     * @param string $plan
     *
     * @return bool|$this
     */
    public function swap($plan)
    {
        $subscription = $this->asBraintreeSubscription();

        $changes = [
            'planId'  => $plan,
            'options' => [
                'prorateCharges' => true,
            ],
        ];

        $result = BraintreeSubscription::update($subscription->id, $changes);

        if ($result->success) {
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
     * Get the subscription as a Braintree subscription object.
     *
     * @return BraintreeSubscription
     */
    public function asBraintreeSubscription()
    {
        return BraintreeSubscription::find($this->braintree_id);
    }
}
