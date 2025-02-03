<?php

namespace AddonFlare\PaidAds\Service\Ad;

use AddonFlare\PaidAds\Entity\Ad;
use AddonFlare\PaidAds\Entity\Location;

class Banner extends \AddonFlare\PaidAds\Service\Location\Banner
{
    /**
     * @var \AddonFlare\PaidAds\Entity\Ad
     */
    protected $ad;

    protected $logIp = false;

    public function __construct(\XF\App $app, Location $location, Ad $ad)
    {
        parent::__construct($app, $location);
        $this->ad = $ad;
    }

    public function getAd()
    {
        return $this->ad;
    }

    public function updateBanner($saveAd = false)
    {
        if (!$this->fileName)
        {
            throw new \LogicException("No source file for banner set");
        }

        $outputFile = $this->fileName;

        if (!$outputFile)
        {
            throw new \RuntimeException("Failed to save image to temporary file; check internal_data/data permissions");
        }

        $dataFile = $this->ad->getAbstractedBannerPath(\XF::$time, $this->extension);
        \XF\Util\File::copyFileToAbstractedPath($outputFile, $dataFile);

        $this->ad->set('upload_date', \XF::$time, ['forceSet' => true]);
        $this->ad->set('upload_extension', $this->extension, ['forceSet' => true]);

        // useful if we're not using FormAction's save
        if ($saveAd)
        {
            $this->ad->save();
        }

        if ($this->logIp)
        {
            $ip = ($this->logIp === true ? $this->app->request()->getIp() : $this->logIp);
            $this->writeIpLog('update', $ip);
        }

        return true;
    }

    public function deleteBanner($saveAd = false)
    {
        $this->deleteBannerFiles();

        $this->ad->upload_date = 0;
        $this->ad->upload_extension = '';

        if ($saveAd)
        {
            $this->ad->save();
        }

        if ($this->logIp)
        {
            $ip = ($this->logIp === true ? $this->app->request()->getIp() : $this->logIp);
            $this->writeIpLog('delete', $ip);
        }

        return true;
    }

    protected function deleteBannerFiles()
    {
        if ($this->ad->upload_date)
        {
            \XF\Util\File::deleteFromAbstractedPath($this->ad->getAbstractedBannerPath());
        }
    }
}