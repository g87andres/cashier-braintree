<?php

namespace LimeDeck\CashierBraintree;

use Braintree\Discount as BraintreeDiscount;
use Braintree\Plan as BraintreePlan;
use Braintree\Plan;
use Exception;

trait BraintreeHelpers
{
    /**
     * Find Braintree plan object by id.
     *
     * @param int $id
     * @return \Braintree\Plan
     * @throws \Exception
     */
    public function findPlanById($id)
    {
        $braintreePlans = BraintreePlan::all();

        $plan = null;

        foreach ($braintreePlans as $braintreePlan) {
            if ($braintreePlan->id == $id) {
                return $braintreePlan;
            }
        }

        throw new Exception("Plan with id '{$id}' does not exist in your Braintree account.");
    }

    /**
     * Compares the billing frequency of two plans.
     *
     * @param \Braintree\Plan $firstPlan
     * @param \Braintree\Plan $secondPlan
     * @return bool
     */
    public function haveTheSameBillingFrequency(Plan $firstPlan, Plan $secondPlan)
    {
        return $firstPlan->billingFrequency === $secondPlan->billingFrequency;
    }

    /**
     * Find Braintree discount object by id.
     *
     * @param int $id
     * @return \Braintree\Discount
     * @throws \Exception
     */
    public function findCouponById($id)
    {
        $braintreeCoupons = BraintreeDiscount::all();

        $coupon = null;

        foreach ($braintreeCoupons as $braintreeCoupon) {
            if ($braintreeCoupon->id == $this->coupon) {
                return $braintreeCoupon;
            }
        }

        throw new Exception("Coupon with id '{$id}' does not exist in your Braintree account.");
    }

    /**
     * Calculate added price of the plan with provided tax percentage.
     *
     * @param \Braintree\Plan $plan
     * @param int             $taxPercentage
     * @return string
     */
    public function planPriceWithTax(BraintreePlan $plan, $taxPercentage)
    {
        return $this->formatAmount((1 + ($taxPercentage / 100)) * floatval($plan->price));
    }

    /**
     * Format float amounts into Braintree accepted form.
     *
     * @param $amount
     * @return string
     */
    public function formatAmount($amount)
    {
        return number_format($amount, 2, '.', '');
    }
}
