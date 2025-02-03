<?php

namespace AddonFlare\PaidAds\Purchasable;

use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;

class Cart extends \XF\Purchasable\AbstractPurchasable
{
    public function getTitle()
    {
        return \XF::phrase('af_paidads');
    }

    public function getPurchaseFromRequest(\XF\Http\Request $request, \XF\Entity\User $purchaser, &$error = null)
    {
        $profileId = $request->filter('payment_profile_id', 'uint');
        $paymentProfile = \XF::em()->find('XF:PaymentProfile', $profileId);

        if (!$paymentProfile || !$paymentProfile->active)
        {
            $error = \XF::phrase('please_choose_valid_payment_profile_to_continue_with_your_purchase');
            return false;
        }

        $cartRepo = $this->getCartRepo();

        if ($cart = $cartRepo->findUserCart())
        {
            $cartAds = $cartRepo->rebuildCart($cart);
        }

        if (!$cart || !$cart->total_amount || !$cartAds->count())
        {
            $error = \XF::phrase('this_item_cannot_be_purchased_at_moment');
            return false;
        }

        if (!in_array($profileId, \XF::options()->af_paidads_payment_profile_ids))
        {
            $error = \XF::phrase('selected_payment_profile_is_not_valid_for_this_purchase');
            return false;
        }

        $cart->in_transaction = 1;
        $cart->save();

        $db = \XF::db();
        $db->update('xf_af_paidads_cart_ad',
            ['status' => 'transaction'],
            'cart_ad_id IN (' . $db->quote($cartAds->keys()) . ')'
        );

        return $this->getPurchaseObject($paymentProfile, $cart, $purchaser);
    }

    public function getPurchasableFromExtraData(array $extraData)
    {
        $output = [
            'link' => '',
            'title' => '',
            'purchasable' => null
        ];

        $cart = \XF::em()->findOne('AddonFlare\PaidAds:Cart', [
            'cart_id'        => $extraData['cart_id'],
            'in_transaction' => 1,
        ]);

        if ($cart)
        {
            $output['link'] = '';
            $output['title'] = '';
            $output['purchasable'] = $cart;
        }
        return $output;
    }

    public function getPurchaseFromExtraData(array $extraData, \XF\Entity\PaymentProfile $paymentProfile, \XF\Entity\User $purchaser, &$error = null)
    {
        $cart = $this->getPurchasableFromExtraData($extraData);
        if (!$cart['purchasable'])
        {
            $error = \XF::phrase('this_item_cannot_be_purchased_at_moment');
            return false;
        }

        if (!in_array($paymentProfile->payment_profile_id, \XF::options()->af_paidads_payment_profile_ids))
        {
            $error = \XF::phrase('selected_payment_profile_is_not_valid_for_this_purchase');
            return false;
        }

        return $this->getPurchaseObject($paymentProfile, $cart['purchasable'], $purchaser);
    }

    /**
     * @param \XF\Entity\PaymentProfile $paymentProfile
     * @param \XF\Entity\UserUpgrade $purchasable
     * @param \XF\Entity\User $purchaser
     *
     * @return Purchase
     */
    public function getPurchaseObject(\XF\Entity\PaymentProfile $paymentProfile, $purchasable, \XF\Entity\User $purchaser)
    {
        $purchase = new Purchase();

        $purchase->title = \XF::phrase('af_paidads_purchase') . ': ' . $purchaser->username;
        $purchase->description = $purchasable->description;
        $purchase->cost = $purchasable->total_amount;
        $purchase->currency = $purchasable->currency;
        $purchase->recurring = false;
        $purchase->lengthAmount = 0;
        $purchase->lengthUnit = '';
        $purchase->purchaser = $purchaser;
        $purchase->paymentProfile = $paymentProfile;
        $purchase->purchasableTypeId = $this->purchasableTypeId;
        $purchase->purchasableId = $purchasable->cart_id;
        $purchase->purchasableTitle = \XF::phrase('af_paidads_purchase');
        $purchase->extraData = [
            'cart_id' => $purchasable->cart_id
        ];

        $router = \XF::app()->router('public');

        $purchase->returnUrl = $router->buildLink('canonical:paid-ads/cart-purchase');
        $purchase->cancelUrl = $router->buildLink('canonical:paid-ads/cart');

        return $purchase;
    }

    public function completePurchase(CallbackState $state)
    {
        $purchaseRequest = $state->getPurchaseRequest();
        $cartId = $purchaseRequest->extra_data['cart_id'];

        $paymentResult = $state->paymentResult;
        $purchaser = $state->getPurchaser();

        // only state currently supported
        if ($paymentResult != CallbackState::PAYMENT_RECEIVED)
        {
            return false;
        }

        $cart = \XF::em()->findOne('AddonFlare\PaidAds:Cart', [
            'cart_id'        => $cartId,
            'in_transaction' => 1,
            'is_paid'        => 0,
        ]);

        if (!$cart)
        {
            $state->logType = 'info';
            $state->logMessage = 'Unable to find cart ID ' . $cartId;
            return false;
        }

        $cartAds = $cart->CartAds;

        $cartAdsData = [];

        foreach ($cartAds as $cart_ad_id => $cartAd)
        {
            if ($cartAd->status != 'transaction')
            {
                // only process the cart ads that are in the transaction, there may be expired ones here so skip them
                continue;
            }

            $cartAdDays = $cartAd->CartAdDays;

            if ($ad = $cartAd->ExistingAd)
            {
                // adding days to existing ad, do nothing
            }
            else
            {
                // creating new ad
                $ad = \XF::em()->create('AddonFlare\PaidAds:Ad');
                $ad->bulkSet([
                    'location_id' => $cartAd->location_id,
                    'user_id'     => $cartAd->user_id,
                    'node_id'     => $cartAd->node_id,
                    'status'      => 'pending',
                    'total_days'  => $cartAd->total_days,
                    'type'        => $cartAd->type,
                ]);
                $ad->save();
            }

            $insertAdDays = $insertAdDaysClean = [];

            foreach ($cartAdDays as $cartAdDay)
            {
                $insertAdDays["{$cartAdDay->date}|{$cartAdDay->type}"] = [
                    'ad_id' => $ad->ad_id,
                    'date'  => $cartAdDay->date,
                    'type'  => $cartAdDay->type,
                ];
                // same as above without ad_id to save space since we're storing this...
                $insertAdDaysClean["{$cartAdDay->date}|{$cartAdDay->type}"] = [
                    'date'  => $cartAdDay->date,
                    'type'  => $cartAdDay->type,
                ];
            }

            // should always be true but just incase...
            if ($insertAdDays)
            {
                \XF::db()->insertBulk('xf_af_paidads_ad_day', $insertAdDays, false, false, 'IGNORE');
                $cartAdsData[] = [
                    'location_id'  => $cartAd->location_id,
                    'node_id'      => $cartAd->node_id,
                    'total_days'   => $cartAd->total_days,
                    'total_amount' => $cartAd->total_amount,
                    'type'         => $cartAd->type,
                    'insert_ad_id' => $ad->ad_id,
                    'addedDays'    => $cartAd->ExistingAd ? true : false,
                    'days'         => $insertAdDaysClean,
                ];
                $this->getAdRepo()->rebuildAd($ad);
            }
        }

        $cart->is_paid = true;
        $cart->paid_data = $cartAdsData;
        $cart->save();

        \XF::db()->query("
            DELETE cart_ad, cart_ad_day
            FROM xf_af_paidads_cart_ad cart_ad
            LEFT JOIN xf_af_paidads_cart_ad_day cart_ad_day ON (cart_ad_day.cart_ad_id = cart_ad.cart_ad_id)
            WHERE
                cart_ad.cart_id = ?
        ", $cart->cart_id);

        $state->logType = 'payment';
        $state->logMessage = 'Payment received.';

        if ($purchaseRequest)
        {
            $extraData = $purchaseRequest->extra_data;
            $extraData['cart_ads_data'] = $cartAdsData;
            $purchaseRequest->extra_data = $extraData;
            $purchaseRequest->save();
        }
    }

    public function sendPaymentReceipt(CallbackState $state)
    {
        switch ($state->paymentResult)
        {
            case CallbackState::PAYMENT_RECEIVED:
            {
                $purchaser = $state->getPurchaser();
                $purchaseRequest = $state->getPurchaseRequest();
                if ($purchaseRequest)
                {
                    $purchasable = $this->getPurchasableFromExtraData($purchaseRequest->extra_data);

                    $locations = $this->getLocationRepo()->findLocationsForList()->fetch();

                    $receiptItems = [];

                    foreach ((array)$purchaseRequest->extra_data['cart_ads_data'] as $cartAd)
                    {
                        $locationId = $cartAd['location_id'];
                        // quick error check but should never be the case
                        if (!isset($locations[$locationId])) continue;

                        $location = $locations[$locationId];
                        $receiptItems[] = [
                            'title' => $location->title,
                            'days'  => $cartAd['total_days'],
                            'cost_phrase' => $location->getCostPhrase($cartAd['total_amount']),
                        ];
                    }

                    $params = [
                        'purchaser'       => $purchaser,
                        'purchaseRequest' => $purchaseRequest,
                        'purchasable'     => $purchasable,
                        'receiptItems'    => $receiptItems,
                    ];

                    \XF::app()->mailer()->newMail()
                        ->setToUser($purchaser)
                        ->setTemplate('af_paidads_cart_receipt', $params)
                        ->send();
                }
            }
        }
    }

    public function reversePurchase(CallbackState $state)
    {
        $purchaseRequest = $state->getPurchaseRequest();
        $purchaser = $state->getPurchaser();

        $extraData = $purchaseRequest->extra_data;

        $db = \XF::db();

        foreach ((array)$extraData['cart_ads_data'] as $cartAd)
        {
            if (empty($cartAd['addedDays']))
            {
                // was a new ad, delete the ad + days
                $db->query("
                    DELETE ad, ad_day
                    FROM xf_af_paidads_ad ad
                    LEFT JOIN xf_af_paidads_ad_day ad_day ON (ad_day.ad_id = ad.ad_id)
                    WHERE
                        ad.ad_id = ".intval($cartAd['insert_ad_id'])."
                ");
            }
            else
            {
                // added days, delete just the days
                $db->query("
                    DELETE ad_day
                    FROM xf_af_paidads_ad_day ad_day
                    INNER JOIN xf_af_paidads_ad ad ON (ad.ad_id = ad_day.ad_id)
                    WHERE
                        ad.ad_id = ".intval($cartAd['insert_ad_id'])."
                        AND ad_day.date IN (".$db->quote(array_keys($cartAd['days'])).")
                ");
                if ($ad = \XF::em()->find('AddonFlare\PaidAds:Ad', $cartAd['insert_ad_id']))
                {
                    $this->getAdRepo()->rebuildAd($ad);
                }
            }
        }

        $this->getLocationRepo()->rebuildDailyCacheOnce();

        $state->logType = 'cancel';
        $state->logMessage = 'Payment refunded/reversed.';
    }

    public function getPurchasablesByProfileId($profileId)
    {
        // not supported
        return;
    }

    protected function getLocationRepo()
    {
        return \XF::repository('AddonFlare\PaidAds:Location');
    }

    protected function getAdRepo()
    {
        return \XF::repository('AddonFlare\PaidAds:Ad');
    }

    protected function getCartAdRepo()
    {
        return \XF::repository('AddonFlare\PaidAds:CartAd');
    }

    protected function getCartRepo()
    {
        return \XF::repository('AddonFlare\PaidAds:Cart');
    }
}