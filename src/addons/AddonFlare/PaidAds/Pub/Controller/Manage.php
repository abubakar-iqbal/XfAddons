<?php

namespace AddonFlare\PaidAds\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Mvc\FormAction;
use XF\Mvc\Reply\View;
use XF\Pub\Controller\AbstractController;

use AddonFlare\PaidAds\Calendar;

class Manage extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        if (!\XF::visitor()->canPurchasePaidAds())
        {
            throw $this->exception($this->noPermission());
        }
    }

    public function actionIndex()
    {
        $page = $this->filterPage();
        $perPage = 25;

        $adsFinder = $this->getAdRepo()->findAdsForList()
            ->where('user_id', \XF::visitor()->user_id)
            ->where('status', ['active', 'pending', 'rejected'])
            ->order('create_date', 'DESC')
            ->limitByPage($page, $perPage);

        $ads = $adsFinder->fetch();

        $cart = $this->getCartRepo()->findUserCart();

        $viewParams = [
            'ads'     => $ads,
            'cart'    => $cart,
            'page'    => $page,
            'perPage' => $perPage,
            'total'   => $adsFinder->total(),
        ];

        return $this->view('', 'af_paidads_manage', $viewParams);
    }

    public function actionStats(ParameterBag $params)
    {
        $ad = $this->assertAdExists($params->ad_id, true);

        $last24HrsClickCount = $this->finder('AddonFlare\PaidAds:AdClick')
            ->where('ad_id', $ad->ad_id)
            ->where('date', '>=', \XF::$time - 86400)
            ->total();

        $viewParams = [
            'ad' => $ad,
            'last24HrsClickCount' => $last24HrsClickCount,
        ];

        return $this->view('', 'af_paidads_ad_stats', $viewParams);
    }

    public function actionEdit(ParameterBag $params)
    {
        $ad = $this->assertAdExists($params->ad_id);

        if (!(!$ad->url || $ad->status == 'active'))
        {
            return $this->notFound();
        }

        $location = $ad->Location;

        if ($this->isPost())
        {
            $this->adsSaveProcess($ad)->run();
            $this->rebuildDailyCache();
            return $this->redirect($this->buildLink('paid-ads/manage'));
        }
        else
        {
            $viewParams = [
                'ad'         => $ad,
                'title'      => $location->title,
                'adOptions'  => $location->ad_options,
                'extensions' => $location->ad_options['file_extensions'],
                'multiple'   => false,
            ];

            return $this->view('', 'af_paidads_ad_edit', $viewParams);
        }
    }

    public function actionEditMultiple()
    {
        $adIds = $this->filter('ad_ids', 'array-uint');

        $ads = $this->finder('AddonFlare\PaidAds:Ad')
            ->with('Location', true)
            ->where('ad_id', $adIds)
            ->where('user_id', \XF::visitor()->user_id)
            ->whereOr(
                ['url', '=', ''],
                ['status', '=', 'active']
            )
            ->fetch();

        $titles = $maxFileSize = $dimensions = $extensions = [];

        $adWidth = $adHeight = 0;

        $isFirst = true;

        foreach ($ads as $ad_id => $ad)
        {
            $location = $ad->Location;
            $adOptions = $location->ad_options;

            $title = (string) $location->title;

            if (!isset($titles[$title]))
            {
                $titles[$title] = 0;
            }
            $titles[$title]++;

            $dimensions["{$adOptions['ad_width']}x{$adOptions['ad_height']}"] = true;

            $adWidth  = $adOptions['ad_width'];
            $adHeight = $adOptions['ad_height'];

            if ($isFirst)
            {
                $extensions = $adOptions['file_extensions'];
                $maxFileSize = $adOptions['max_file_size'];
            }
            else
            {
                $extensions = array_intersect($extensions, $adOptions['file_extensions']);
                $maxFileSize = min($maxFileSize, $adOptions['max_file_size']);
            }

            $isFirst = false;
        }

        $dimensionsCount = count($dimensions);

        if (!$dimensionsCount)
        {
            return $this->error(\XF::phrase('af_paidads_please_select_at_least_one_ad_submit'));
        }
        else if ($dimensionsCount > 1)
        {
            return $this->error(\XF::phrase('af_paidads_different_dimensions_x', ['dimensions' => implode(' , ', array_keys($dimensions))]));
        }

        if ($this->filter('submit', 'bool'))
        {
            $this->adsSaveProcess($ads)->run();
            $this->rebuildDailyCache();
            return $this->redirect($this->buildLink('paid-ads/manage'));
        }
        else
        {
            $titlesWithCount = [];
            foreach ($titles as $title => $titleCount)
            {
                $titlesWithCount[] = $titleCount > 1 ? "$title ({$titleCount})" : $title;
            }
            $viewParams = [
                'ad'         => [],
                'title'      => implode(', ', $titlesWithCount),
                'adOptions'  => [
                    'max_file_size' => $maxFileSize,
                    'ad_width'      => $adWidth,
                    'ad_height'     => $adHeight,
                ],
                'extensions' => $extensions,
                'multiple'   => true,
                'adIds'      => $adIds,
            ];

            return $this->view('', 'af_paidads_ad_edit', $viewParams);
        }
    }

    protected function adsSaveProcess($ads)
    {
        $form = $this->formAction();

        $input = $this->filter([
            'url' => 'str',
        ]);

        if (!($ads instanceof \XF\Mvc\Entity\ArrayCollection))
        {
            $ads = $this->em()->getBasicCollection([$ads]);
        }

        foreach ($ads as $ad)
        {
            $location = $ad->Location;

            $bannerService = $this->service('AddonFlare\PaidAds:Ad\Banner', $location, $ad);

            $upload = $this->request->getFile('upload', false, false);
            if ($upload)
            {
                if (!$bannerService->setImageFromUpload($upload))
                {
                    $form->logError($bannerService->getError(), 'upload');
                }
                else
                {
                    $form->apply(function() use ($bannerService)
                    {
                        $bannerService->updateBanner();
                    });
                }
            }
            else if (!$ad->banner_url)
            {
                $form->logError(\XF::phrase('uploaded_file_failed_not_found'), 'upload');
            }

            // determine auto approve / auto re-approve
            $visitor = \XF::visitor();
            $options = $this->options();

            $autoApprove = false;

            if (!$ad->getPreviousValue('upload_date'))
            {
                // first time
                if ($visitor->isMemberOf($options->af_paidads_ugs_auto_approve))
                {
                    $autoApprove = true;
                }
            }
            else
            {
                // re-submission
                if ($visitor->isMemberOf($options->af_paidads_ugs_auto_reapprove))
                {
                    $autoApprove = true;
                }
            }

            $form->basicEntitySave($ad, $input);

            $form->complete(function() use ($ad, $autoApprove)
            {
                if ($ad->getValue('url') != $ad->getPreviousValue('url') ||
                    $ad->getValue('upload_date') != $ad->getPreviousValue('upload_date'))
                {
                    $ad->status = $autoApprove ? 'active' : 'pending';
                    $ad->save();
                }
            });
        }

        return $form;
    }

    protected function assertAdExists($id, $activeOrPendingOnly = false, $with = null, $phraseKey = null)
    {
        $finder = $this->getAdRepo()->findUserAd($id, $activeOrPendingOnly);

        if ($with)
        {
            $finder->with($with);
        }

        $ad = $finder->fetchOne();

        if (!$ad)
        {
            if (!$phraseKey)
            {
                $phraseKey = 'requested_page_not_found';
            }

            throw $this->exception(
                $this->notFound(\XF::phrase($phraseKey))
            );
        }

        return $ad;
    }

    protected function rebuildDailyCache()
    {
        $this->getLocationRepo()->rebuildDailyCacheOnce();
    }

    protected function getLocationRepo()
    {
        return $this->repository('AddonFlare\PaidAds:Location');
    }

    protected function getAdRepo()
    {
        return $this->repository('AddonFlare\PaidAds:Ad');
    }

    protected function getCartRepo()
    {
        return $this->repository('AddonFlare\PaidAds:Cart');
    }
}