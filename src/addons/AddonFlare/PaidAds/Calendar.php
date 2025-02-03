<?php

namespace AddonFlare\PaidAds;

use AddonFlare\PaidAds\Entity\Location;

class Calendar
{
    public $firstDayNumeric;

    protected $location;
    protected $adType;
    protected $nodeId;

    protected $cartAd;
    protected $ad;

    protected $year;
    protected $month;
    protected $monthDays;
    protected $monthFull;
    protected $monthShort;

    protected $showPrevLink;

    protected $language;

    public function getYear()
    {
        return $this->year;
    }

    public function getMonth()
    {
        return $this->month;
    }

    public function __construct(Location $location, $adType, $viewMonth, $viewYear, $nodeId, $cartAd, $ad = null)
    {
        $this->location = $location;
        $this->adType = $adType; // no purpose atm, but avaiable for future use
        $this->nodeId = $nodeId;

        $this->cartAd = $cartAd;
        $this->ad = $ad; // only used in admincp calendar display

        $this->language = \XF::language();

        $dateTime = new \DateTime('@' . \XF::$time);
        $dateTime->setTimezone($this->language->getTimeZone());
        $dateTime->setTime(0, 0, 0);
        $dateTime->modify('first day of this month');

        $firstDayTimestamp = $dateTime->getTimeStamp();

        if ($viewMonth && $viewYear)
        {
            // try setting to month/year specified
            try
            {
                $customDateTime = new \DateTime(sprintf('%d-%d', $viewYear, $viewMonth), $this->language->getTimeZone());
                $customDateTime->setTime(0, 0, 0);
                // month must be this or in the future (not past)
                if ($customDateTime->getTimeStamp() >= $firstDayTimestamp)
                {
                    $dateTime = $customDateTime;
                }
            }
            catch (\Exception $e) {}
        }

        $dateFormats = 'Y|n|t';

        list($this->year, $this->month, $this->monthDays) = explode('|', $dateTime->format($dateFormats));

        // F = Full Month Name, M = Short Month Name, w = Day of week number,

        $this->monthFull = $this->language->date($dateTime, 'F');
        $this->monthShort = $this->language->date($dateTime, 'M');

        $this->firstDayNumeric = intval($this->language->date($dateTime, 'w'));

        $this->showPrevLink = ($dateTime->getTimeStamp() != $firstDayTimestamp);
    }

    public function build()
    {
        $days = [];

        $locationRepo = $this->getLocationRepo();

        $adDays = $locationRepo->getAdDaysForCalendar(
            $this->location,
            $this->cartAd ? $this->cartAd->cart_ad_id : null,
            $this->cartAd ? $this->cartAd->existing_ad_id : ($this->ad ? $this->ad->ad_id : null),
            $this->year,
            $this->month,
            'all',
            $this->nodeId
        );

        $currentDayNumeric = $this->firstDayNumeric;

        for ($i = 1; $i <= $this->monthDays; $i++)
        {
            if ($currentDayNumeric == 7)
            {
                // it's 0-6, so reset it when it hits 7
                $currentDayNumeric = 0;
            }

            $fullDate = sprintf('%d-%02d-%02d', $this->year, $this->month, $i);
            $unavailable = !$locationRepo->checkValidDate($fullDate);

            // ad days
            $forumRotationsOpen = $adDays['openDays']['forum'][$fullDate];
            $nonForumRotationsOpen = $adDays['openDays']['non_forum'][$fullDate];

            $forumDayIsAdded = isset($adDays['addedCartDays']['forum'][$fullDate]);
            $nonForumDayIsAdded = isset($adDays['addedCartDays']['non_forum'][$fullDate]);

            $forumDayIsPurchased = isset($adDays['purchasedAdDays']['forum'][$fullDate]);
            $nonForumDayIsPurchased = isset($adDays['purchasedAdDays']['non_forum'][$fullDate]);

            $days[$i] = [
                // entire day data
                'calendar_day_number'      => $i,
                'week_day_number'          => $currentDayNumeric,
                'full_date'                => $fullDate,
                'unavailable'              => $unavailable,

                // invidual forum/non_forum data
                'forum_rotations_open'     => $forumRotationsOpen,
                'non_forum_rotations_open' => $nonForumRotationsOpen,
                'forum_added'              => $forumDayIsAdded,
                'non_forum_added'          => $nonForumDayIsAdded,
                'forum_purchased'          => $forumDayIsPurchased,
                'non_forum_purchased'      => $nonForumDayIsPurchased,
                'forum_available'          => (!$unavailable && $forumRotationsOpen && !$forumDayIsAdded && !$forumDayIsPurchased),
                'non_forum_available'      => (!$unavailable && $nonForumRotationsOpen && !$nonForumDayIsAdded && !$nonForumDayIsPurchased),
            ];

            $lastDayNumeric = $currentDayNumeric;

            $currentDayNumeric++;
        }

        $daysOfWeek = [
            0 => 'sunday',
            1 => 'monday',
            2 => 'tuesday',
            3 => 'wednesday',
            4 => 'thursday',
            5 => 'friday',
            6 => 'saturday',
        ];

        $options = \XF::options();

        $startOfWeekDay = $options->af_paidads_cal_startOfWeek;

        if ($startOfWeekDay == 'monday')
        {
            unset($daysOfWeek[0]);
            $daysOfWeek[0] = 'sunday';
        }

        $useShortDayNames = $options->af_paidads_cal_shortDayNames;

        foreach ($daysOfWeek as &$dayPhrase)
        {
            // day_ is the phrase prefix used
            $dayPhrase = 'day_' . $dayPhrase;

            if ($useShortDayNames)
            {
                $dayPhrase .= '_short';
            }

            $dayPhrase = $this->language->phrase($dayPhrase);
        }

        $output = [
            'days'                 => $days,
            'daysOfWeek'           => $daysOfWeek,
            'currentDayNumeric'    => $currentDayNumeric,
            'firstDayNumeric'      => $this->firstDayNumeric,
            'lastDayNumeric'       => $lastDayNumeric,
            'startOfWeekDayNumber' => array_keys($daysOfWeek)[0],
            'endOfWeekDayNumber'   => array_keys($daysOfWeek)[6],
            'startOfWeekIsSunday'  => ($startOfWeekDay == 'sunday'),

            'year' => $this->year,
            'month' => $this->month,
            'monthDays' => $this->monthDays,
            'monthFull' => $this->monthFull,
            'monthShort' => $this->monthShort,
            'showPrevLink' => $this->showPrevLink,

            'allDaysAdded'     => $adDays['allDaysAdded'],
            'allDaysPurchased' => $adDays['allDaysPurchased'],
        ];

        return $output;
    }

    protected function getLocationRepo()
    {
        return \XF::repository('AddonFlare\PaidAds:Location');
    }
}