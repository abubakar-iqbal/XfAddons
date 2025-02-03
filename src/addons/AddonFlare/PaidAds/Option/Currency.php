<?php

namespace AddonFlare\PaidAds\Option;
use XF\Option\AbstractOption;

class Currency extends AbstractOption
{
    public static function renderSelect(\XF\Entity\Option $option, array $htmlParams)
    {
        $data = self::getSelectData($option, $htmlParams);

        return self::getTemplater()->formSelectRow(
            $data['controlOptions'], $data['choices'], $data['rowOptions']
        );
    }

    protected static function getSelectData(\XF\Entity\Option $option, array $htmlParams)
    {
        $currencyData = \XF::app()->data('XF:Currency');
        $currencies = $currencyData->getCurrencyOptions();

        $includePopular = isset($htmlParams['include_popular']) ? $htmlParams['include_popular'] : true;
        $includeEmpty = isset($htmlParams['include_empty']) ? $htmlParams['include_empty'] : false;

        $choices = [];
        if ($includeEmpty)
        {
            $choices = [
                0 => ['_type' => 'option', 'value' => 0, 'label' => \XF::phrase('(none)')]
            ];
        }

        $currenciesChoices = [];
        foreach ($currencies AS $code => $label)
        {
            $currenciesChoices[$code] = [
                'value' => $code,
                'label' => \XF::escapeString($label),
            ];
        }

        if ($includePopular)
        {
            $popularCurrencies = $currencyData->getCurrencyOptions(true);

            $popularCurrenciesChoices = [];
            foreach ($popularCurrencies AS $code => $label)
            {
                $popularCurrenciesChoices[$code] = [
                    'value' => $code,
                    'label' => \XF::escapeString($label),
                ];
            }

            $choices[] = ['_type' => 'optgroup', 'options' => $popularCurrenciesChoices];
            $choices[] = ['_type' => 'optgroup', 'options' => $currenciesChoices];
        }
        else
        {
            $choices = $currenciesChoices;
        }

        return [
            'choices' => $choices,
            'controlOptions' => self::getControlOptions($option, $htmlParams),
            'rowOptions' => self::getRowOptions($option, $htmlParams)
        ];
    }
}