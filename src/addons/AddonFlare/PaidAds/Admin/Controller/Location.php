<?php

namespace AddonFlare\PaidAds\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\FormAction;
use XF\Mvc\ParameterBag;

class Location extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('advertising');
    }

    public function actionIndex()
    {
        $locationRepo = $this->getLocationRepo();

        $options = $this->em()->find('XF:Option', 'af_paidads_disallowedTemplates');

        $locationsFinder = $locationRepo->findLocationsForList();
        $locations = $locationsFinder->fetch()->groupBy('position_id');

        $advertisingRepo = $this->getAdvertisingRepo();
        $positionsFinder = $advertisingRepo->findAdvertisingPositionsForList();
        $positions = $positionsFinder->fetch();

        $activeAds = $this->app()->db()->fetchPairs('
            SELECT ad.location_id, COUNT(ad.ad_id) AS total
            FROM xf_af_paidads_ad ad
            INNER JOIN xf_af_paidads_location location ON (location.location_id = ad.location_id)
            WHERE status = ?
            GROUP BY ad.location_id
        ', ['active']);

        $viewParams = [
            'options'   => [$options],
            'locations' => $locations,
            'positions' => $positions,
            'activeAds' => $activeAds,
            'totalAds'  => $locationRepo->getTotalGroupedLocations($locations)
        ];

        return $this->view('', 'af_paidads_location_list', $viewParams);
    }

    public function locationAddEdit(\AddonFlare\PaidAds\Entity\Location $location)
    {
        $advertisingRepo = $this->getAdvertisingRepo();
        $advertisingPositions = $advertisingRepo
            ->findAdvertisingPositionsForList(true)
            ->fetch()
            ->pluckNamed('title', 'position_id');

        /** @var \XF\Repository\UserGroup $userGroupRepo */
        $userGroupRepo = $this->repository('XF:UserGroup');
        $userGroups = $userGroupRepo->getUserGroupTitlePairs();


        /** @var \XF\Repository\Node $nodeRepo */
        $nodeRepo = $this->repository('XF:Node');
        $nodeTree = $nodeRepo->createNodeTree($nodeRepo->getFullNodeList());

        // only list nodes that are forums or contain forums
        $nodeTree = $nodeTree->filter(null, function($id, $node, $depth, $children, $tree)
        {
            return ($children || $node->node_type_id == 'Forum');
        });

        $serverMaxFileSize = \XF::app()->uploadMaxFilesize / 1024;

        $viewParams = [
            'location'             => $location,
            'advertisingPositions' => $advertisingPositions,
            'userGroups'           => $userGroups,
            'nodeTree'             => $nodeTree,
            'serverMaxFileSize'    => $serverMaxFileSize,
        ];

        return $this->view('', 'af_paidads_location_edit', $viewParams);
    }

    public function actionEdit(ParameterBag $params)
    {
        $location = $this->assertLocationExists($params->location_id);

        $displayCriteria = $location->display_criteria;

        $displayCriteria['thread_ids']  = $this->implodeWithComma($displayCriteria['thread_ids']);
        $displayCriteria['post_counts'] = $this->implodeWithComma($displayCriteria['post_counts']);
        $displayCriteria['excluded_templates'] = $this->implodeWithNewLine($displayCriteria['excluded_templates']);

        $location->display_criteria = $displayCriteria;

        return $this->locationAddEdit($location);
    }

    public function actionAdd()
    {
        $this->setSectionContext('af_paidads_locationsAdd');

        $location = $this->em()->create('AddonFlare\PaidAds:Location');

        return $this->locationAddEdit($location);
    }

    public function actionSave(ParameterBag $params)
    {
        $this->assertPostOnly();

        if ($params->location_id)
        {
            $location = $this->assertLocationExists($params->location_id);
        }
        else
        {
            $location = $this->em()->create('AddonFlare\PaidAds:Location');
        }
        $this->locationSaveProcess($location)->run();
        $this->rebuildDailyCache();

        return $this->redirect($this->buildLink('paid-ads/locations') . $this->buildLinkHash($location->location_id));
    }

    public function actionDelete(ParameterBag $params)
    {
        $location = $this->assertLocationExists($params->location_id);

        if ($this->isPost())
        {
            $location->delete();
            return $this->redirect($this->buildLink('paid-ads/locations'));
        }
        else
        {
            $viewParams = [
                'location' => $location
            ];
            return $this->view('', 'af_paidads_location_delete', $viewParams);
        }
    }

    protected function locationSaveProcess(\AddonFlare\PaidAds\Entity\Location $location)
    {
        $form = $this->formAction();

        $input = $this->filter([
            'position_id'             => 'str',
            'display_order'           => 'uint',
            'active'                  => 'bool',
            'ad_type'                 => 'str',
            'ad_options'              => 'array',
            'display_criteria'        => 'array',
            'can_purchase'            => 'bool',
            'can_purchase_forum'      => 'bool',
            'can_purchase_non_forum'  => 'bool',
            'max_rotations_forum'     => 'uint',
            'max_rotations_non_forum' => 'uint',
            'purchase_user_group_ids' => 'array-uint',
            'purchase_options'        => 'array',
            'misc_options'            => 'array',
        ]);

        // take care of "all user groups" setting (if selected)
        if ($this->filter('purchase_user_group', 'str') == 'all')
        {
            $input['purchase_user_group_ids'] = [-1];
        }

        $extraInput = $this->filter([
            'title'       => 'str',
            'description' => 'str'
        ]);

        $form->validate(function(FormAction $form) use ($input, $extraInput)
        {
            if (!$extraInput['title'])
            {
                $form->logError(\XF::phrase('please_enter_valid_title'), 'title');
            }
        });

        // Clean: ad_options
        $adOptions = $this->filterArray($input['ad_options'], [
            'ad_width'         => 'uint',
            'ad_height'        => 'uint',
            'max_file_size'    => 'uint',
            'file_extensions'  => 'array-str',
            'alignment'        => 'str',
            'inline_css'       => 'str',
            'placeholder_type' => 'str',
            'placeholder_html' => 'str',
            'placeholder_url'  => 'str',
            'placeholder_show' => 'array-str',
        ]);

        // keep the existing placeholder_upload fields that aren't added with input like upload_date
        $extraElements = array_diff_key($location->ad_options, $adOptions);
        $input['ad_options'] = $adOptions + $extraElements;

        // process placeholder image upload (if any)
        $bannerService = $this->service('AddonFlare\PaidAds:Location\Banner', $location);

        $form->validate(function(FormAction $form) use ($adOptions, $bannerService)
        {
            if ($adOptions['placeholder_type'] == 'upload_image')
            {
                $upload = $this->request->getFile('placeholder_upload_image', false, false);
                if ($upload)
                {
                    if (!$bannerService->setImageFromUpload($upload))
                    {
                        $form->logError($bannerService->getError(), 'placeholder_upload_image');
                    }
                    else
                    {
                        $form->apply(function() use ($bannerService)
                        {
                            $bannerService->updateBanner();
                        });
                    }
                }
                else
                {
                    $form->logError(\XF::phrase('uploaded_file_failed_not_found'), 'placeholder_upload_image');
                }
            }
        });

        $form->validate(function(FormAction $form) use ($adOptions)
        {
            if (in_array($adOptions['placeholder_type'], ['existing_upload', 'upload_image']))
            {
                if (!$adOptions['placeholder_url'])
                {
                    $form->logError(\XF::phrase('af_paidads_invalid_placeholder_url'), 'placeholder_url');
                }
            }
        });

        $form->validate(function(FormAction $form) use (&$adOptions)
        {
            if (!$this->validateInlineCss($adOptions['inline_css'], $error))
            {
                $form->logError($error, 'inline_css');
            }
        });

        $form->complete(function() use ($adOptions, $bannerService)
        {
            if (!in_array($adOptions['placeholder_type'], ['existing_upload', 'upload_image']))
            {
                $bannerService->deleteBanner();
            }
        });

        // Clean: display_criteria
        $displayCriteria = $this->filterArray($input['display_criteria'], [
            'thread_ids'                => 'str',
            'post_counts'               => 'str',
            'user_groups'               => 'array-uint',
            'not_user_groups'           => 'array-uint',
            'excluded_node_ids'         => 'array-uint',
            'excluded_templates'        => 'str',
            'placeholder_probabilities' => 'array',
        ]);

        $displayCriteria['thread_ids']  = $this->explodeWithComma($displayCriteria['thread_ids']);
        $displayCriteria['post_counts'] = $this->explodeWithComma($displayCriteria['post_counts']);
        $displayCriteria['excluded_templates'] = $this->explodeWithSpace($displayCriteria['excluded_templates']);

        $createPlaceholderProbabilities = function($type) use ($displayCriteria)
        {
            $probabilities = [];

            if (
                !isset($displayCriteria['placeholder_probabilities'][$type]['active_rotations']) ||
                !isset($displayCriteria['placeholder_probabilities'][$type]['probability'])
            )
            {
                return $probabilities;
            }

            foreach ($displayCriteria['placeholder_probabilities'][$type]['active_rotations'] as $key => $activeRotation)
            {
                if (!isset($displayCriteria['placeholder_probabilities'][$type]['probability'][$key]))
                {
                    // we don't have a probability for this active rotation...
                    continue;
                }

                $probability = $displayCriteria['placeholder_probabilities'][$type]['probability'][$key];

                if (!is_numeric($activeRotation) || !is_numeric($probability))
                {
                    continue;
                }

                $activeRotation = intval($activeRotation);
                $probability = intval($probability);

                // some additional checks
                if ($activeRotation < 0) $activeRotation = 0;
                if ($probability < 0) $probability = 0;
                if ($probability > 100) $probability = 100;

                $probabilities[$activeRotation] = $probability;
            }

            // sort by active rotations, ascending
            ksort($probabilities);

            return $probabilities;
        };

        $displayCriteria['placeholder_probabilities'] = [
            'forum'     => $createPlaceholderProbabilities('forum'),
            'non_forum' => $createPlaceholderProbabilities('non_forum'),
        ];

        $input['display_criteria'] = $displayCriteria;

        // Clean: purchase_options
        $purchaseOptions = $this->filterArray($input['purchase_options'], [
            'min_days'                   => 'posint',
            'max_days'                   => 'posint',
            'terms'                      => 'str',
            'forum'                      => [
                                                'node_ids' => 'array-int', // allow -1 on purpose
                                                'include_child_nodes' => 'bool',
                                                'price_type'  => 'str',
                                                'fixed_price' => 'unum',
                                                'fixed_custom_forum_prices' => 'str',
                                                'dynamic_price_tiers' => 'str',
                                                'dynamic_custom_forum_price_tiers' => 'str',
                                            ],
            'non_forum'                  => [
                                                'fixed_price' => 'unum'
                                            ],
        ]);

        // none
        if (in_array(-1, $purchaseOptions['forum']['node_ids']))
        {
            $purchaseOptions['forum']['node_ids'] = [];
        }
        // all
        if (in_array(-2, $purchaseOptions['forum']['node_ids']))
        {
            $purchaseOptions['forum']['node_ids'] = [-2];
        }

        $createPriceTierConditions = function(&$ranges) use ($form, $purchaseOptions)
        {
            $tiers = $this->explodeWithNewLine($purchaseOptions['forum']['dynamic_price_tiers']);

            $conditions = $ranges = [];

            $re = "/(?<range_start>[\\d\\.,]+).*?-.*?(?<range_end>[\\d\\.,]+).*?=.*?(?<price>[\\d\\.,]+)/";

            $lastPrice = null;

            foreach ($tiers as $key => $tier)
            {
                if (!preg_match($re, $tier, $match)) continue;

                $match = str_replace(',', '', $match);

                $match['range_start'] = intval($match['range_start']);
                $match['range_end']   = intval($match['range_end']);
                $match['price']       = floatval($match['price']);

                $lastPrice = $match['price'];

                if (!$match['price'])
                {
                    $form->logError(\XF::phrase('af_paidads_invalid_forum_dynamic_price_for_x', ['x' => $match[0]]), "forum_dynamic_price_tiers_$key");
                }

                $range = "\$postCount >= {$match['range_start']} AND \$postCount <= {$match['range_end']}";
                $ranges[] = $range;

                $conditions[] = "if ($range) { return {$match['price']}; }";
            }

            if ($conditions)
            {
                $conditions[] = "return {$lastPrice};";
            }

            $conditions = $this->implodeWithNewLine($conditions);

            return $conditions;
        };

        $purchaseOptions['forum']['dynamic_price_tiers_conditions'] = $createPriceTierConditions($priceTierRanges);

        $createCustomForumPriceTierConditions = function($priceTierRanges) use ($form, $purchaseOptions)
        {
            if (!$priceTierRanges) return '';

            $forums = $this->explodeWithNewLine($purchaseOptions['forum']['dynamic_custom_forum_price_tiers']);

            $priceTierRangesCount = count($priceTierRanges);

            $allForumConditions = [];

            foreach ($forums as $forum)
            {
                list($nodeId, $prices) = explode('=', $forum, 2);

                if (!$nodeId = intval($nodeId))
                {
                    continue;
                }

                $forumConditions = [];

                // remove commas
                $prices = str_replace(',', '', $prices);

                $prices = $this->explodeWithSemiColon($prices, 'floatval');

                if (count($prices) != $priceTierRangesCount)
                {
                    $form->logError(\XF::phrase('af_paidads_tier_prices_for_node_x_mismatch', ['node_id' => $nodeId]), "forum_dynamic_custom_forum_price_tiers_$nodeId");
                    continue;
                }

                $lastPrice = null;

                foreach ($priceTierRanges as $key => $range)
                {
                    $price = $prices[$key];
                    $lastPrice = $price;
                    $forumConditions[] = "if ($range) { return $price; }";
                }

                if ($forumConditions)
                {
                    $forumConditions[] = "return {$lastPrice};";
                }

                $allForumConditions[$nodeId] = $this->implodeWithNewLine($forumConditions);
            }

            return $allForumConditions;
        };

        $purchaseOptions['forum']['dynamic_custom_forum_price_tiers_conditions'] = $createCustomForumPriceTierConditions($priceTierRanges);

        // var_dump($purchaseOptions['forum']); die;

        $form->validate(function(FormAction $form) use ($input, $purchaseOptions)
        {
            if ($input['can_purchase'])
            {
                if ($input['can_purchase_forum'])
                {
                    $forumOptions = $purchaseOptions['forum'];
                    if ($forumOptions['price_type'] == 'fixed')
                    {
                        if (!$forumOptions['fixed_price'])
                        {
                            $form->logError(\XF::phrase('af_paidads_invalid_forum_price_per_day'), 'forum_fixed_price');
                        }
                        if ($forumOptions['fixed_custom_forum_prices'] && !$forumOptions['fixed_custom_forum_prices_conditions'])
                        {
                            $form->logError(\XF::phrase('af_paidads_invalid_forum_fixed_prices_per_day'), 'forum_fixed_custom_forum_prices');
                        }
                    }
                    else if ($forumOptions['price_type'] == 'dynamic')
                    {
                        if (!$forumOptions['dynamic_price_tiers_conditions'])
                        {
                            $form->logError(\XF::phrase('af_paidads_invalid_forum_dynamic_price_tiers'), 'forum_dynamic_price_tiers');
                        }
                        if ($forumOptions['dynamic_custom_forum_price_tiers'] && !$forumOptions['dynamic_custom_forum_price_tiers_conditions'])
                        {
                            $form->logError(\XF::phrase('af_paidads_invalid_forum_dynamic_custom_prices'), 'forum_dynamic_custom_forum_price_tiers');
                        }
                    }
                    else
                    {
                        $form->logError(\XF::phrase('af_paidads_please_choose_price_type_forum'), 'forum_price_type');
                    }
                }
                if ($input['can_purchase_non_forum'])
                {
                    $nonForumOptions = $purchaseOptions['non_forum'];
                    if (!$nonForumOptions['fixed_price'])
                    {
                        $form->logError(\XF::phrase('af_paidads_invalid_non_forum_price_per_day'), 'non_forum_fixed_price');
                    }
                }
            }
        });

        // correctly format fixed prices since floatval removes the zeros and adds too many decimals
        if (!empty($purchaseOptions['forum']['fixed_price']))
        {
            $purchaseOptions['forum']['fixed_price'] = number_format($purchaseOptions['forum']['fixed_price'], 2, '.', '');
        }
        if (!empty($purchaseOptions['non_forum']['fixed_price']))
        {
            $purchaseOptions['non_forum']['fixed_price'] = number_format($purchaseOptions['non_forum']['fixed_price'], 2, '.', '');
        }

        $input['purchase_options'] = $purchaseOptions;

        // Clean: misc_options
        $miscOptions = $this->filterArray($input['misc_options'], [
            'click_track_threshold' => 'uint',
            'view_track_threshold'  => 'uint',
            'view_track_method'     => 'str',
        ]);

        $input['misc_options'] = $miscOptions;

        // var_dump($adOptions, $displayCriteria, $purchaseOptions); die;

        $form->basicEntitySave($location, $input);

        $form->apply(function() use ($extraInput, $location)
        {
            $title = $location->getMasterTitlePhrase();
            $title->phrase_text = $extraInput['title'];
            $title->save();

            $description = $location->getMasterDescriptionPhrase();
            $description->phrase_text = $extraInput['description'];
            $description->save();
        });

        return $form;
    }

    protected function explodeWithComma($str, $arrayMapFunc = 'intval')
    {
        return $this->explodeWith(',', $str, $arrayMapFunc);
    }

    protected function explodeWithSemiColon($str, $arrayMapFunc = 'intval')
    {
        return $this->explodeWith(';', $str, $arrayMapFunc);
    }

    protected function explodeWith($delimiter, $str, $arrayMapFunc = 'intval')
    {
        $arr = explode($delimiter, $str);

        $arr = array_filter($arr);

        if ($arrayMapFunc)
        {
            $arr = array_map($arrayMapFunc, $arr);
        }

        return $arr;
    }

    protected function implodeWithComma($arr)
    {
        return implode(',', $arr);
    }

    protected function explodeWithSpace($str)
    {
        return preg_split('/\s+/', trim($str), -1, PREG_SPLIT_NO_EMPTY);
    }

    protected function explodeWithNewLine($str)
    {
        return preg_split("/(\r\n|\n|\r)/", trim($str), -1, PREG_SPLIT_NO_EMPTY);
    }

    protected function implodeWithNewLine($arr)
    {
        return implode("\n", $arr);
    }

    public function actionToggle()
    {
        $plugin = $this->plugin('XF:Toggle');
        return $plugin->actionToggle('AddonFlare\PaidAds:Location');
    }

    protected function validateInlineCss(&$css, &$error)
    {
        $css = trim($css);
        if (!strlen($css))
        {
            return true;
        }

        $parser = new \Less_Parser();
        try
        {
            $parser->parse('.example { ' . $css . '}')->getCss();
        }
        catch (\Exception $e)
        {
            $error = \XF::phrase('af_paidads_invalid_inline_css');
            return false;
        }

        return true;
    }

    protected function assertLocationExists($id, $with = null, $phraseKey = null)
    {
        return $this->assertRecordExists('AddonFlare\PaidAds:Location', $id, $with, $phraseKey);
    }

    protected function getAdvertisingRepo()
    {
        return $this->repository('XF:Advertising');
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