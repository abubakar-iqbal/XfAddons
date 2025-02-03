<?php

namespace AddonFlare\PaidAds\Option;
use XF\Option\AbstractOption;

class PaymentProfile extends AbstractOption
{
    public static function renderCheckbox(\XF\Entity\Option $option, array $htmlParams)
    {
        $paymentRepo = \XF::repository('XF:Payment');
        $profiles = $paymentRepo->findPaymentProfilesForList()->fetch();

        $choices = [];

        foreach ($profiles as $profileId => $profile)
        {
            $choices[$profileId] = $profile->Provider->title !== $profile->title ? $profile->Provider->title . ' - ' . $profile->title : $profile->Provider->title;
        }

        return self::getCheckboxRow($option, $htmlParams, $choices);
    }
}