<?php

namespace AddonFlare\PaidAds\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class Ad extends Entity
{
    public function getAbstractedBannerPath($date = '', $extension = '')
    {
        $adId = $this->ad_id;
        $date = $date ?: $this->upload_date;
        $extension = $extension ?: $this->upload_extension;

        return sprintf('data://addonflare/pa/images/a/%d-%d.%s',
            $adId,
            $date,
            $extension
        );
    }

    public function getBannerUrl($sizeCode = null, $canonical = false)
    {
        $app = $this->app();

        if ($this->upload_date)
        {
            return $app->applyExternalDataUrl(
                "addonflare/pa/images/a/{$this->ad_id}-{$this->upload_date}.{$this->upload_extension}?{$this->upload_date}",
                $canonical
            );
        }
        else
        {
            return null;
        }
    }

    public function getPricesPerDay()
    {
        $location = $this->Location;
        $nodeId = $this->node_id;

        return $this->getLocationRepo()->getPricesPerDay($location, $nodeId);
    }

    public function getDaysRemainingForum()
    {
        $locationRepo = $this->getLocationRepo();
        $daysRemaining = 0;

        if (!empty($this->days_data['forum']))
        {
            foreach ($this->days_data['forum'] as $date => $value)
            {
                if ($locationRepo->checkValidDate($date))
                {
                    $daysRemaining++;
                }
            }
        }

        return $daysRemaining;
    }

    public function getDaysRemainingNonForum()
    {
        $locationRepo = $this->getLocationRepo();
        $daysRemaining = 0;

        if (!empty($this->days_data['non_forum']))
        {
            foreach ($this->days_data['non_forum'] as $date => $value)
            {
                if ($locationRepo->checkValidDate($date))
                {
                    $daysRemaining++;
                }
            }
        }

        return $daysRemaining;
    }

    public function getTitle()
    {
        return $this->Location->title;
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_af_paidads_ad';
        $structure->shortName = 'AddonFlare\PaidAds:Ad';
        $structure->primaryKey = 'ad_id';
        $structure->columns = [
            'ad_id'            => ['type' => self::UINT, 'autoIncrement' => true],
            'location_id'      => ['type' => self::UINT, 'required' => true],
            'user_id'          => ['type' => self::UINT, 'required' => true],
            'node_id'          => ['type' => self::UINT, 'default' => 0],
            'status'           => ['type' => self::STR,  'required' => true, 'maxLength' => 25],
            'create_date'      => ['type' => self::UINT,  'default' => \XF::$time],
            'total_days'       => ['type' => self::UINT, 'default' => 0],
            'days_data'        => ['type' => self::JSON_ARRAY, 'default' => []],
            'type'             => ['type' => self::STR,   'required' => true, 'maxLength' => 25],
            'url'              => ['type' => self::STR, 'maxLength' => 2083, 'required' => false, 'match' => 'url'],
            'upload_date'      => ['type' => self::UINT, 'default' => 0],
            'upload_extension' => ['type' => self::STR, 'maxLength' => 5, 'default' => ''],
            'total_clicks'     => ['type' => self::UINT, 'default' => 0],
            'total_views'      => ['type' => self::UINT, 'default' => 0],
        ];

        $structure->getters = [
            'prices_per_day' => true,
            'banner_url'     => true,
            'days_remaining_forum'     => true,
            'days_remaining_non_forum' => true,
            'title'                    => true,
        ];

        $structure->relations = [
            'Location' => [
                'entity' => 'AddonFlare\PaidAds:Location',
                'type' => self::TO_ONE,
                'conditions' => 'location_id',
                'primary' => true
            ],
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true
            ],
            'Forum' => [
                'entity' => 'XF:Forum',
                'type' => self::TO_ONE,
                'conditions' => 'node_id',
                'primary' => true,
                'with' => 'Node'
            ],
        ];

        return $structure;
    }

    protected function getLocationRepo()
    {
        return $this->repository('AddonFlare\PaidAds:Location');
    }
}