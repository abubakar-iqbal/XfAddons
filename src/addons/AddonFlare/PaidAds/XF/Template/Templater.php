<?php

namespace AddonFlare\PaidAds\XF\Template;

use AddonFlare\PaidAds\Listener;
use AddonFlare\PaidAds\IDs;

class Templater extends XFCP_Templater
{
    public static $afPaidAds = false;

    public function callAdsMacro($position, array $arguments, array $globalVars)
    {
        static $globallyDisabledTemplates;

        if (!isset($globalVars['xf']['reply']['view']))
        {
            return '';
        }

        $visitor = \XF::visitor();
        $options = \XF::options();
        $reply = $globalVars['xf']['reply'];

        if (!isset($globallyDisabledTemplates))
        {
            $globallyDisabledTemplates = preg_split('/\s+/', trim($options->af_paidads_disallowedTemplates), -1, PREG_SPLIT_NO_EMPTY);
        }

        // globally disabled templates / user groups
        if
        (
            ($reply['template'] && in_array($reply['template'], $globallyDisabledTemplates)) ||
            ($options->af_paidads_exclude_in_paidads_pages && strpos((string)$reply['template'], 'af_paidads_') === 0) ||
            ($visitor->canHidePaidAds())
        )
        {
            return '';
        }

        // used later in the function, so keep it here incase we find more use for it later...
        $dateTime = new \DateTime('@' . \XF::$time, new \DateTimeZone('UTC'));

        $locationRepo = $this->app->repository('AddonFlare\PaidAds:Location');
        $adRepo = $this->app->repository('AddonFlare\PaidAds:Ad');

        $cache = $locationRepo->getDailyCache();
        $locationsCache = $cache['locations'];
        $adsCache       = $cache['ads'];
        $positionsCache = $cache['positions'];

        $isForumPage = false;

        if ($reply['section'] == 'forums')
        {
            $isForumPage = true;
        }

        $pageType = ($isForumPage ? 'forum' : 'non_forum');

        $nodeId = $threadId = $postId = $postPosition = 0;

        // check in either of the 2
        if (isset($globalVars['forum']['node_id']))
        {
            $nodeId = $globalVars['forum']['node_id'];
        }
        if (isset($globalVars['thread']['thread_id']))
        {
            $threadId = $globalVars['thread']['thread_id'];
            $nodeId = $globalVars['thread']['node_id'];
        }
        if (isset($globalVars['post']['post_id']))
        {
            $postId = $globalVars['post']['post_id'];
            $postPosition = $globalVars['post']['position'];
            $threadId = $globalVars['post']['thread_id'];
        }

        // fix for pages like the forum homepage where it has the section but not a node
        if (!$nodeId)
        {
            $isForumPage = false;
            $pageType = 'non_forum';
        }

        $checkShowPlaceholder = function($location, $pageType)
        {
            $adOptions = $location['ad_options'];

            // make sure the placeholder should show for this type
            if (!in_array($pageType, $adOptions['placeholder_show']))
            {
                return false;
            }

            // make sure we have a placeholder
            if (!$adOptions['placeholder_html'] && !$adOptions['placeholder_banner_url'])
            {
                return false;
            }

            return true;
        };

        $locationsWithPlaceholder = [];
        foreach ($locationsCache as $locationId => $location)
        {
            if ($location['position_id'] == $position && $checkShowPlaceholder($location, $pageType))
            {
                // add placeholders for this position
                $locationsWithPlaceholder[$locationId] = $location;
            }
        }

        // all location ads for this position
        $positionAds = [];

        // whether we there are no ad days for today and we're defaulting to check locations, this will be used to know if we should display the placeholder 100% of the time
        $defaultToPlaceholder = false;

        if (isset($positionsCache[$position][$pageType]))
        {
            if ($isForumPage)
            {
                foreach ($positionsCache[$position]['forum'] as $locationId => $forums)
                {
                    $location = $locationsCache[$locationId];

                    $locationAds = [
                        'location' => $location,
                        'ad_ids'   => [], // all the ad ids to rotate for this location
                    ];

                    if (isset($forums[$nodeId]))
                    {
                        $locationAds['ad_ids'] = $forums[$nodeId];
                    }

                    $positionAds[] = $locationAds;
                }
            }
            else
            {
                foreach ($positionsCache[$position]['non_forum'] as $locationId => $ads)
                {
                    $location = $locationsCache[$locationId];

                    $locationAds = [
                        'location' => $location,
                    ];

                    $locationAds['ad_ids'] = $ads;

                    $positionAds[] = $locationAds;
                }
            }
        }
        else if ($locationsWithPlaceholder)
        {
            // check the location cache directly in case there's no days for this location
            foreach ($locationsWithPlaceholder as $location)
            {
                $locationAds = [
                    'location' => $location,
                    'ad_ids'   => [],
                ];
                $positionAds[] = $locationAds;
            }

            $defaultToPlaceholder = true;
        }
        else
        {
            return '';
        }

        $getPlaceholderProbability = function($location, $activeRotations) use ($pageType, $checkShowPlaceholder)
        {
            $adOptions = $location['ad_options'];
            $displayCriteria = $location['display_criteria'];

            if (!$checkShowPlaceholder($location, $pageType))
            {
                return false;
            }

            if (isset($displayCriteria['placeholder_probabilities'][$pageType][$activeRotations]))
            {
                return $displayCriteria['placeholder_probabilities'][$pageType][$activeRotations];
            }

            return false;
        };

        $createTimesTable = function($adIds, $placeholderProbability)
        {
            $timesTable = array_fill(0, 60, null);

            $timesToInsertPlaceholder = 0;

            $secondsInMinute = 60;

            if (is_numeric($placeholderProbability) && $placeholderProbability > 0)
            {
                $timesToInsertPlaceholder = ($placeholderProbability / 100) * $secondsInMinute;
                $timesToInsertPlaceholder = ceil($timesToInsertPlaceholder);
            }

            $getEmptyKeys = function($arr)
            {
                $emptyKeys = [];

                foreach ($arr as $key => $value)
                {
                    if (!$value)
                    {
                        $emptyKeys[] = $key;
                    }
                }

                return $emptyKeys;
            };

            $fillEmptyKeys = function(&$arr, &$amountToFill) use (&$fillEmptyKeys, $getEmptyKeys)
            {
                $emptyKeys = $getEmptyKeys($arr);

                $emptyKeysCount = count($emptyKeys);

                if (!$amountToFill || $emptyKeysCount < 1) return;

                $everyX = ceil($emptyKeysCount / $amountToFill);

                foreach ($emptyKeys as $key => $emptyKey)
                {
                    if ($key % $everyX == 0)
                    {
                        $arr[$emptyKey] = 'placeholder';
                        --$amountToFill;
                    }
                    if ($amountToFill == 0)
                    {
                        break;
                    }
                }

                $fillEmptyKeys($arr, $amountToFill);
            };

            // add placeholder first (if any)
            if ($timesToInsertPlaceholder)
            {
                $fillEmptyKeys($timesTable, $timesToInsertPlaceholder);
            }

            // reset the index, we were using the ad_id for both the key and value
            $adIds = array_values($adIds);
            if ($adIdsCount = count($adIds))
            {
                $currentAdKey = 0;

                foreach ($timesTable as &$value)
                {
                    if ($value)
                    {
                        // placeholder already here
                        continue;
                    }

                    $adId = $adIds[$currentAdKey];
                    $value = $adId;

                    // last key, restart it
                    if ($currentAdKey == ($adIdsCount - 1))
                    {
                        $currentAdKey = 0;
                    }
                    else
                    {
                        $currentAdKey++;
                    }
                }
            }

            return $timesTable;
        };

        $adsHtml = [];

        // each position can have multiple locations, so we have to do it for each..
        foreach ($positionAds as $allLocationData)
        {
            $location = $allLocationData['location'];
            $adIds = $allLocationData['ad_ids'];
            $activeRotations = count($adIds);

            $adOptions = $location['ad_options'];
            $displayCriteria = $location['display_criteria'];
            $miscOptions = $location['misc_options'];

            if (!empty($displayCriteria['user_groups']) && !$visitor->isMemberOf($displayCriteria['user_groups']))
            {
                continue;
            }

            if (!empty($displayCriteria['not_user_groups']) && $visitor->isMemberOf($displayCriteria['not_user_groups']))
            {
                continue;
            }

            $excludedNodeIds = array_merge((array)$options->af_paidads_excluded_node_ids, (array)$displayCriteria['excluded_node_ids']);
            if ($nodeId && in_array($nodeId, $excludedNodeIds))
            {
                continue;
            }

            if ($reply['template'] && in_array($reply['template'], $displayCriteria['excluded_templates']))
            {
                continue;
            }

            if ($threadId && !empty($displayCriteria['thread_ids']) && !in_array($threadId, $displayCriteria['thread_ids']))
            {
                continue;
            }

            if ($postId && !empty($displayCriteria['post_counts']))
            {
                $remainder = $postPosition % $options->messagesPerPage;
                if (!in_array($remainder + 1, $displayCriteria['post_counts']))
                {
                    continue;
                }
            }

            $placeholderProbability = $getPlaceholderProbability($location, $activeRotations);
            $timesTable = $createTimesTable($adIds, $defaultToPlaceholder ? 100 : $placeholderProbability);

            // remove leading zeros too
            $currentSecond = intval($dateTime->format('s'));

            $adIdKey = $timesTable[$currentSecond];

            $bannerLink = $bannerImageUrl = $bannerHtml = '';

            if ($adIdKey == 'placeholder')
            {
                $adId = 'placeholder';
                if ($bannerImageUrl = $adOptions['placeholder_banner_url'])
                {
                    $bannerLink = $adOptions['placeholder_url'];
                }
                else if ($adOptions['placeholder_html'])
                {
                    $bannerHtml = $adOptions['placeholder_html'];
                }
                else
                {
                    continue;
                }
            }
            else if (isset($adIds[$adIdKey]))
            {
                $ad = $adsCache[$adIds[$adIdKey]];
                $adId = $ad['ad_id'];
                $bannerLink = $ad['url'];
                $bannerImageUrl = $ad['banner_url'];

                if ($miscOptions['view_track_method'] == 'basic')
                {
                    $adRepo->addLoadedAd($adId);
                }
            }
            else
            {
                // shouldn't happen but incase
                continue;
            }

            $adsHtml[] = [
                'ad_id'          => $adId,
                'bannerLink'     => $bannerLink,
                'bannerImageUrl' => $bannerImageUrl,
                'bannerHtml'     => $bannerHtml,
                'ad_options'     => $adOptions,
                'misc_options'   => $miscOptions,
            ];
        }

        if ($adsHtml)
        {
            $arguments['ads'] = $adsHtml;
            return $this->callMacro('public:af_paidads_ad_display_macros', 'display_ads', $arguments, $globalVars);
        }
        else
        {
            return '';
        }
    }
    public static function getAfPaidAds($key)
    {
        if (!self::$afPaidAds)
        {
            \XF::app()->templater()->addAfPaidAds($key);
            self::$afPaidAds = true;
        }

        return $key;
    }
    public function addAfPaidAds($key)
    {
        static $complete = false;
        $paidAds = IDs::getSetC(2, null, 0);

        $prefix = IDs::$prefix;

        $f = function() use(&$complete, $paidAds)
        {
            if (!$complete)
            {
                $complete = $this->{$paidAds}[] = IDs::getF();
            }
        };

        return (IDs::$prefix($this)) ? ($this) : $f($this);
    }

    public function fnCopyright($templater, &$escape)
    {
        $return = parent::fnCopyright($templater, $escape);
        IDs::CR($templater, $return);
        return $return;
    }
}