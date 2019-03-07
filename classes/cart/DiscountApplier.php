<?php

namespace OFFLINE\Mall\Classes\Cart;

use Illuminate\Support\Collection;
use OFFLINE\Mall\Classes\Utils\Money;
use OFFLINE\Mall\Models\Cart;
use OFFLINE\Mall\Models\Discount;

class DiscountApplier
{
    /**
     * @var Cart
     */
    private $cart;
    /**
     * @var int
     */
    private $total;
    /**
     * @var int
     */
    private $reducedTotal;
    /**
     * @var Discount[]
     */
    private $discounts;
    /**
     * @var bool
     */
    private $reducedTotalIsFixed = false;
    /**
     * @var Money
     */
    private $money;

    public function __construct(Cart $cart, float $total, float $baseTotal = null)
    {
        $this->cart         = $cart;
        $this->total        = $total;
        $this->reducedTotal = $baseTotal ?? $total;
        $this->discounts    = collect([]);
        $this->money        = app(Money::class);
    }

    public function apply(Discount $discount): ?bool
    {
        if ( ! $this->discountCanBeApplied($discount)) {
            return null;
        }

        $savings = 0;
        $discountableProducts = $this->getDiscountableProducts($discount);

        if ($discount->type === 'alternate_price') {
            // Here we set each product's total to the specified alternate price
            $this->reducedTotal        = is_null($discountableProducts) ?
                                        $discount->alternatePrice()->integer :
                                        $discountableProducts->count() * $discount->alternatePrice()->integer;
            $savings                   = $this->total - $this->reducedTotal;
        }

        if ($discount->type === 'shipping') {
            // Shipping price discounts always apply to the entire cart
            $this->reducedTotal        = $discount->shippingPrice()->integer;
            $savings                   = $this->cart->shipping_method->price()->integer - $discount->shippingPrice()->integer;
        }

        if ($discount->type === 'fixed_amount') {
            $savings            = $discount->amount()->integer;
            $this->reducedTotal -= $savings;
        }

        if ($discount->type === 'rate') {
            $discountableTotal = 0;
            foreach ($discountableProducts as $discountableProduct) {

            }
            $savings            = $this->total * ($discount->rate / 100);
            $this->reducedTotal -= $savings;
        }

        $this->discounts->push([
            'discount'          => $discount,
            'savings'           => $savings * -1,
            'savings_formatted' => $this->money->format($savings * -1),
        ]);

        return true;
    }

    public function applyMany(Collection $discounts): Collection
    {
        foreach ($discounts as $discount) {
            // A return value of `false` indicates that a discount is applied that
            // fixes the final amount so no other discounts would have an effect.
            if ($this->apply($discount) === false) {
                break;
            }
        }

        return $this->discounts;
    }

    public function reducedTotal(): ?float
    {
        return $this->reducedTotal;
    }

    protected function discountCanBeApplied(Discount $discount): bool
    {
        if ($discount->max_number_of_usages !== null && $discount->max_number_of_usages < $discount->number_of_usages) {
            return false;
        }

        if ($discount->trigger === 'total' && (int)$discount->totalToReach()->integer <= $this->total) {
            return true;
        }

        if ($discount->trigger === 'product' && $this->productIsInCart($discount->product_id)) {
            return true;
        }

        if ($discount->trigger === 'category' && $this->productWithCategoryIsInCart($discount->category_id)) {
            return true;
        }

        return $discount->trigger === 'code';
    }

    private function productIsInCart(int $productId): bool
    {
        return $this->cart->products->pluck('product_id')->contains($productId);
    }

    private function productWithCategoryIsInCart(int $categoryId): bool
    {
        return $this->cart->products()->whereHas('product.categories', function ($q) use ($categoryId) {
            $q->where('category_id', $categoryId);
        })->count() > 0;
    }

    /**
     * Get all products in cart that are applicable to the discount.
     * If null, the discount applies to the entire cart.
     *
     * @param Discount $discount
     * @return null|Collection
     */
    private function getDiscountableProducts(Discount $discount)
    {
        if ($discount->trigger === 'product') {
            return $this->cart->products()->where('product_id', $discount->product_id)->get();
        }

        if ($discount->trigger === 'category') {
            return $this->cart->products()->whereHas('product.categories', function ($q) use ($discount) {
                $q->where('category_id', $discount->category_id);
            })->get();
        }

        return null;
    }
}
