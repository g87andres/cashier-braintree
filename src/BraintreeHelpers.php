<?php

namespace LimeDeck\CashierBraintree;

use Braintree\Discount as BraintreeDiscount;
use Braintree\Plan as BraintreePlan;
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
        return number_format((1 + $taxPercentage) * floatval($plan->price), 2);
    }
}
