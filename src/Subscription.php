<?php

namespace LimeDeck\CashierBraintree;

use Braintree\Plan;
use Braintree\Subscription as BraintreeSubscription;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use BraintreeHelpers;

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
     * Obtain the current balance of the subscription.
     *
     * @return mixed
     */
    public function balance()
    {
        return $this->asBraintreeSubscription()->balance;
    }

    /**
     * Determine how many days are remaining until next billing.
     *
     * @return int
     */
    public function remainingDaysBeforeNextBilling()
    {
        return Carbon::now()->diffInDays(
            Carbon::instance($this->asBraintreeSubscription()->billingPeriodEndDate)
        );
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
        $currentPlan = $this->findPlanById($this->braintree_plan);
        $newPlan = $this->findPlanById($plan);

        if ($this->haveTheSameBillingFrequency($currentPlan, $newPlan)) {
            $subscription = $this->updatePlan($newPlan);
        } else {
            $subscription = $this->replacePlan($currentPlan, $newPlan);
        }

        return $subscription;
    }

    /**
     * Cancel the subscription at the end of the billing period.
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
        BraintreeSubscription::cancel($this->braintree_id);

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
        $this->update([
            'ends_at' => Carbon::now()
        ]);
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

    /**
     * Update current subscription plan to another with the same billing cycle.
     *
     * @param \Braintree\Plan $newPlan
     * @return $this
     * @throws \Exception
     */
    protected function updatePlan(Plan $newPlan) {
        $changes = [
            'price' => $this->planPriceWithTax($newPlan, $this->user->taxPercentage()),
            'planId'  => $newPlan->id,
            'options' => [
                'prorateCharges' => true,
            ],
        ];

        $result = BraintreeSubscription::update($this->braintree_id, $changes);

        if (! $result->success) {
            throw new Exception('Plan was not swapped.');
        }

        $this->update([
            'braintree_plan' => $newPlan->id
        ]);

        return $this;
    }

    /**
     * Replace current subscription plan with another while allowing for different billing cycles.
     *
     * @param \Braintree\Plan $currentPlan
     * @param \Braintree\Plan $newPlan
     * @return \LimeDeck\CashierBraintree\Subscription
     * @throws \Exception
     */
    protected function replacePlan(Plan $currentPlan, Plan $newPlan)
    {
        $amount = $this->calculateCreditAmount($currentPlan);

        $options['discounts'] = [
            'add' => [
                [
                    'inheritedFromId' => 'coupon-universal',
                    'amount'          => $this->formatAmount($amount),
                ],
            ],
        ];

        try {
            $newSubscription = (new SubscriptionBuilder($this->user, $this->name, $newPlan->id))
                ->create(null, [], $options);

            $this->cancelNow();

            return $newSubscription;
        } catch (Exception $exception) {
            throw new Exception('Plan was not swapped.');
        }
    }

    /**
     * Calculate credit amount as a replacement for unused subscription period.
     *
     * @param \Braintree\Plan $plan
     * @return mixed
     */
    protected function calculateCreditAmount(Plan $plan)
    {
        $subscription = $this->asBraintreeSubscription();

        // We can use only 360 days as opposed to 365 in a year for easier calculations
        // since the difference is present only in marginal cases, with plan changes
        // requested close to the beginning of the billing cycle for yearly plans.
        $unusedDays = min(360, $this->remainingDaysBeforeNextBilling());

        $valueOfDay = (floatval($subscription->price) / ($plan->billingFrequency * 30));

        return  $valueOfDay * $unusedDays - $subscription->balance;
    }
}
