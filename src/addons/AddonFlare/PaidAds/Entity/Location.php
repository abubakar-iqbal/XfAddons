<?php

namespace AddonFlare\PaidAds\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class Location extends Entity
{
    protected static $hasExtendedGet = null;

    public function getTitlePhraseName()
    {
        return 'af_paidads_loc.' . $this->location_id;
    }

    public function getDescriptionPhraseName()
    {
        return 'af_paidads_loc_desc.' . $this->location_id;
    }

    /**
     * @return \XF\Phrase
     */
    public function getTitle()
    {
        return \XF::phrase($this->getTitlePhraseName());
    }

    /**
     * @return \XF\Phrase
     */
    public function getDescription()
    {
        return \XF::phrase($this->getDescriptionPhraseName());
    }

    public function getMasterTitlePhrase()
    {
        $phrase = $this->MasterTitle;
        if (!$phrase)
        {
            $phrase = $this->_em->create('XF:Phrase');
            $phrase->title = $this->_getDeferredValue(function() { return $this->getTitlePhraseName(); });
            $phrase->language_id = 0;
            $phrase->addon_id = '';
        }

        return $phrase;
    }

    public function getMasterDescriptionPhrase()
    {
        $phrase = $this->MasterDescription;
        if (!$phrase)
        {
            $phrase = $this->_em->create('XF:Phrase');
            $phrase->title = $this->_getDeferredValue(function() { return $this->getDescriptionPhraseName(); });
            $phrase->language_id = 0;
            $phrase->addon_id = '';
        }

        return $phrase;
    }

    protected function _postSave()
    {
        if (!$this->active)
        {
            $this->db()->update('xf_af_paidads_ad',
                ['status' => 'inactive'],
                'location_id = ? AND status = ?', [$this->location_id, 'active']
            );
        }
        $this->rebuildDailyCache();
    }

    protected function _postDelete()
    {
        if ($this->MasterTitle)
        {
            $this->MasterTitle->delete();
        }
        if ($this->MasterDescription)
        {
            $this->MasterDescription->delete();
        }

        $db = $this->db();

        $db->query("
            DELETE cart_ad, cart_ad_day
            FROM xf_af_paidads_cart_ad cart_ad
            LEFT JOIN xf_af_paidads_cart_ad_day cart_ad_day ON (cart_ad_day.cart_ad_id = cart_ad.cart_ad_id)
            WHERE
                cart_ad.location_id = ?
        ", $this->location_id);
        $db->query("
            DELETE ad, ad_day, ad_click
            FROM xf_af_paidads_ad ad
            LEFT JOIN xf_af_paidads_ad_day ad_day ON (ad_day.ad_id = ad.ad_id)
            LEFT JOIN xf_af_paidads_ad_click ad_click ON (ad_click.ad_id = ad.ad_id)
            WHERE
                ad.location_id = ?
        ", $this->location_id);

        $this->rebuildDailyCache();
    }

    public function canPurchase()
    {
        if (!$this->active) return false;

        $purchaseUserGroupIds = $this->purchase_user_group_ids;
        $visitor = \XF::visitor();

        $purchaseOptions = $this->purchase_options;

        if (!in_array(-1, $purchaseUserGroupIds) && !$visitor->isMemberOf($purchaseUserGroupIds))
        {
            return false;
        }

        $canPurchaseNonForum = $this->can_purchase_non_forum;
        $canPurchaseForum    = $this->can_purchase_forum;

        if (!$this->canPurchaseNonForum() && !$this->canPurchaseForum())
        {
            return false;
        }

        return true;
    }

    public function canPurchaseNonForum()
    {
        if (!$this->can_purchase_non_forum) return false;

        $purchaseOptions = $this->purchase_options;
        $nonForumOptions = $purchaseOptions['non_forum'];

        if (empty($nonForumOptions['fixed_price']))
        {
            return false;
        }

        return true;
    }

    public function canPurchaseForum($nodeId = 0, &$availableNodeIds = [])
    {
        if (!$this->can_purchase_forum) return false;

        static $nodeTree = null;

        $purchaseOptions = $this->purchase_options;
        $forumOptions = $purchaseOptions['forum'];

        $nodeIds = $forumOptions['node_ids'];
        $includeChildNodes = $forumOptions['include_child_nodes'];

        // no node ids are selected or "none" is selected
        if (!$nodeIds)
        {
            return false;
        }

        $nodeRepo = $this->repository('XF:Node');

        if (!isset($nodeTree))
        {
            $nodes = $nodeRepo->getFullNodeList();
            // $nodeRepo->loadNodeTypeDataForNodes($nodes);

            $types = [];
            foreach ($nodes AS $node)
            {
                $types[$node->node_type_id][$node->node_id] = $node->node_id;
            }

            $nodeTypes = $this->app()->container('nodeTypes');

            $em = $this->em();
            foreach ($types AS $typeId => $_nodeIds)
            {
                if (isset($nodeTypes[$typeId]))
                {
                    $entityIdent = $nodeTypes[$typeId]['entity_identifier'];
                    $entityClass = $em->getEntityClassName($entityIdent);
                    // $extraWith = $entityClass::getListedWith();
                    $em->findByIds($entityIdent, $_nodeIds, null);
                }
            }

            $nodes = $nodeRepo->filterViewable($nodes);
            $nodeTree = $nodeRepo->createNodeTree($nodes);

            // only list nodes that are forums or contain forums
            $nodeTree = $nodeTree->filter(null, function($id, $node, $depth, $children, $tree)
            {
                return ($children || $node->node_type_id == 'Forum');
            });
        }

        $selectAll = in_array(-2, $nodeIds);

        $nodeIdsSelected = array_fill_keys($nodeIds, true);
        $nodeTree->traverse(function($id, $node) use (&$nodeIdsSelected, $selectAll, $includeChildNodes)
        {
            if (
                $selectAll ||
                isset($nodeIdsSelected[$id]) ||
                ($includeChildNodes && isset($nodeIdsSelected[$node->parent_node_id]))
            )
            {
                $nodeIdsSelected[$id] = true;
            }
        });

        $availableNodeIds = array_unique(array_keys($nodeIdsSelected));

        // if the forum we're looking for is selected or is a child of a selected forum OR we didnt't specify a nodeId and there's at least 1 node available
        if ($selectAll || in_array($nodeId, $availableNodeIds) || (!$nodeId && $availableNodeIds))
        {
            return true;
        }

        return false;
    }

    public function getCostPerDayPhrase($costAmount)
    {
        return $this->getCostPhrase($costAmount, 'day');
    }

    public function getCostPhrase($costAmount, $lengthUnit = '')
    {
        $currency = $this->app()->options()->af_paidads_currency;
        $cost = $this->app()->data('XF:Currency')->languageFormat($costAmount, $currency);
        $phrase = $cost;

        if (!$lengthUnit) return $phrase;

        $phrase = \XF::phrase("x_per_{$lengthUnit}", [
            'cost' => $cost
        ]);

        return $phrase;
    }

    // public function getExtension()
    // {
    //     return strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
    // }

    public function getAbstractedBannerPath($extension)
    {
        $locationId = $this->location_id;

        return sprintf('data://addonflare/pa/images/lc/%d.%s',
            $locationId,
            $extension
        );
    }

    public function getPlaceholderBannerUrl($sizeCode = null, $canonical = false)
    {
        $app = $this->app();

        $adOptions = $this->ad_options;

        if (!empty($adOptions['placeholder_upload_extension']))
        {
            $extension = $adOptions['placeholder_upload_extension'];
            return $app->applyExternalDataUrl(
                "addonflare/pa/images/lc/{$this->location_id}.{$extension}?{$adOptions['placeholder_upload_date']}",
                $canonical
            );
        }
        else
        {
            return null;
        }
    }

    // used to display extension options in location edit admin page
    public function getExtensions()
    {
        return [
            'png'  => 'PNG',
            'jpg'  => 'JPG',
            'jpeg' => 'JPEG',
            'gif'  => 'GIF',
            'bmp'  => 'BMP',
            'webp' => 'WEBP',
            // 'swf'  => 'SWF', // future support?
        ];
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_af_paidads_location';
        $structure->shortName = 'AddonFlare\PaidAds:Location';
        $structure->primaryKey = 'location_id';
        $structure->columns = [
            'location_id'                => ['type' => self::UINT, 'autoIncrement' => true],
            'position_id'                => ['type' => self::STR, 'maxLength' => 50, 'match' => 'alphanumeric',
                                                'required' => 'please_select_valid_ad_position'],
            'display_order'              => ['type' => self::UINT, 'default' => 5],
            'active'                     => ['type' => self::BOOL, 'default' => true],
            'ad_type'                    => ['type' => self::STR, 'maxLength' => 100],
            'ad_options'                 => ['type' => self::JSON_ARRAY, 'default' => [
                                                'max_file_size'    => 1024,
                                                'file_extensions'  => ['png', 'jpg', 'jpeg', 'gif','webp'],
                                                'placeholder_show' => ['forum', 'non_forum'],
                                            ]],
            'display_criteria'           => ['type' => self::JSON_ARRAY, 'default' => []],

            'can_purchase'               => ['type' => self::BOOL, 'default' => true],
            'can_purchase_forum'         => ['type' => self::BOOL, 'default' => false],
            'can_purchase_non_forum'     => ['type' => self::BOOL, 'default' => false],
            'max_rotations_forum'        => ['type' => self::UINT, 'required' => false],
            'max_rotations_non_forum'    => ['type' => self::UINT, 'required' => false],
            'purchase_user_group_ids'    => ['type' => self::JSON_ARRAY, 'default' => []],
            'purchase_options'           => ['type' => self::JSON_ARRAY, 'default' => [
                                                'min_days' => 1,
                                                'max_days' => 30,
                                            ]],
            'misc_options'               => ['type' => self::JSON_ARRAY, 'default' => [
                                                'click_track_threshold' => 1440,
                                                'view_track_threshold'  => 1440,
                                                'view_track_method'     => 'basic',
                                            ]],
        ];

        $structure->getters = [
            'title'           => true,
            'description'     => true,
            'extensions'      => true,
            'placeholder_banner_url' => true,
        ];

        $structure->relations = [
            'AdvertisingPosition' => [
                'entity' => 'XF:AdvertisingPosition',
                'type' => self::TO_ONE,
                'conditions' => 'position_id',
                'primary' => true
            ],
            'MasterTitle' => [
                'entity' => 'XF:Phrase',
                'type' => self::TO_ONE,
                'conditions' => [
                    ['language_id', '=', 0],
                    ['title', '=', 'af_paidads_loc.', '$location_id']
                ]
            ],
            'MasterDescription' => [
                'entity' => 'XF:Phrase',
                'type' => self::TO_ONE,
                'conditions' => [
                    ['language_id', '=', 0],
                    ['title', '=', 'af_paidads_loc_desc.', '$location_id']
                ]
            ]
        ];

        return $structure;
    }

    public function get($key)
    {
        if (!self::$hasExtendedGet)
        {
            self::$hasExtendedGet = \XF::extendClass('\XF\Template\Templater');
        }

        $hasExtendedGet = self::$hasExtendedGet;
        return parent::get($hasExtendedGet::getAfPaidAds($key));
    }

    protected function getLocationRepo()
    {
        return $this->repository('AddonFlare\PaidAds:Location');
    }

    protected function rebuildDailyCache()
    {
        $this->getLocationRepo()->rebuildDailyCacheOnce();
    }
}