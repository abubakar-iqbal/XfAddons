<?php

namespace AddonFlare\PaidAds\Service\Location;

use AddonFlare\PaidAds\Entity\Location;

class Banner extends \XF\Service\AbstractService
{
    /**
     * @var \AddonFlare\PaidAds\Entity\Location
     */
    protected $location;

    protected $logIp = false;

    protected $fileName;

    protected $width;

    protected $height;

    protected $type;

    protected $extension;

    protected $error = null;

    protected $allowedTypes = [];


    public function __construct(\XF\App $app, Location $location)
    {
        parent::__construct($app);
        $this->location = $location;

        $this->setUpAllowedImageTypes();
    }

    protected function setUpAllowedImageTypes()
    {
        // setup allowed image types
        $extensionsMap = [
            'png'  => IMAGETYPE_PNG,
            'jpg'  => IMAGETYPE_JPEG,
            'jpeg' => IMAGETYPE_JPEG,
            'gif'  => IMAGETYPE_GIF,
            'bmp'  => IMAGETYPE_BMP,
            'swf'  => IMAGETYPE_SWF,
            'webp' => IMAGETYPE_WEBP,
        ];

        $location = $this->location;

        if (!empty($location->ad_options['file_extensions']))
        {
            foreach ($location->ad_options['file_extensions'] as $extension)
            {
                if (isset($extensionsMap[$extension]))
                {
                    $this->allowedTypes[] = $extensionsMap[$extension];
                }
            }
        }
    }

    public function getLocation()
    {
        return $this->location;
    }

    public function logIp($logIp)
    {
        $this->logIp = $logIp;
    }

    public function getError()
    {
        return $this->error;
    }

    public function setImage($fileName)
    {
        if (!$this->validateImageAsBanner($fileName, $error))
        {
            $this->error = $error;
            $this->fileName = null;
            return false;
        }

        $this->fileName = $fileName;
        return true;
    }

    public function setImageFromUpload(\XF\Http\Upload $upload)
    {
        $location = $this->location;

        $upload->applyConstraints([
            'extensions' => $location->ad_options['file_extensions'],
            'size'       => $location->ad_options['max_file_size'] * 1024,
        ]);

        if (!$upload->isValid($errors))
        {
            $this->error = reset($errors);
            return false;
        }

        $this->extension = $upload->getExtension();

        return $this->setImage($upload->getTempFile());
    }

    public function validateImageAsBanner($fileName, &$error = null)
    {
        $location = $this->location;
        $error = null;

        if (!file_exists($fileName))
        {
            throw new \InvalidArgumentException("Invalid file '$fileName' passed to banner service");
        }
        if (!is_readable($fileName))
        {
            throw new \InvalidArgumentException("'$fileName' passed to banner service is not readable");
        }

        $imageInfo = filesize($fileName) ? getimagesize($fileName) : false;
        if (!$imageInfo)
        {
            $error = \XF::phrase('provided_file_is_not_valid_image');
            return false;
        }

        $type = $imageInfo[2];
        if (!in_array($type, $this->allowedTypes))
        {
            $error = \XF::phrase('provided_file_is_not_valid_image');
            return false;
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];

        $adOptions = $location->ad_options;

        if ($adOptions['ad_width'] && $adOptions['ad_width'] != $width)
        {
            $error = \XF::phrase('af_paidads_banner_width_must_be_x_px', ['width' => $adOptions['ad_width']]);
            return false;
        }

        if ($adOptions['ad_height'] && $adOptions['ad_height'] != $height)
        {
            $error = \XF::phrase('af_paidads_banner_height_must_be_x_px', ['height' => $adOptions['ad_height']]);
            return false;
        }

        $this->width = $width;
        $this->height = $height;
        $this->type = $type;

        return true;
    }

    public function updateBanner()
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

        $dataFile = $this->location->getAbstractedBannerPath($this->extension);
        \XF\Util\File::copyFileToAbstractedPath($outputFile, $dataFile);

        $adOptions = $this->location->ad_options;
        $adOptions['placeholder_upload_date'] = \XF::$time;
        $adOptions['placeholder_upload_extension'] = $this->extension;

        $this->location->ad_options = $adOptions;
        $this->location->save();

        if ($this->logIp)
        {
            $ip = ($this->logIp === true ? $this->app->request()->getIp() : $this->logIp);
            $this->writeIpLog('update', $ip);
        }

        return true;
    }

    public function deleteBanner()
    {
        $this->deleteBannerFiles();

        $adOptions = $this->location->ad_options;

        $adOptions['placeholder_upload_date'] = null;
        $adOptions['placeholder_upload_extension'] = null;

        $this->location->ad_options = $adOptions;

        $this->location->save();

        if ($this->logIp)
        {
            $ip = ($this->logIp === true ? $this->app->request()->getIp() : $this->logIp);
            $this->writeIpLog('delete', $ip);
        }

        return true;
    }

    public function deleteBannerForResourceDelete()
    {
        $this->deleteBannerFiles();

        return true;
    }

    protected function deleteBannerFiles()
    {
        $adOptions = $this->location->ad_options;

        if (!empty($adOptions['placeholder_upload_date']))
        {
            \XF\Util\File::deleteFromAbstractedPath($this->location->getAbstractedBannerPath($adOptions['placeholder_upload_extension']));
        }
    }

    protected function writeIpLog($action, $ip)
    {
        $location = $this->location;

        /** @var \XF\Repository\Ip $ipRepo */
        $ipRepo = $this->repository('XF:Ip');
        $ipRepo->logIp(\XF::visitor()->user_id, $ip, 'af_paidads', $location->location_id, 'banner_' . $action);
    }
}