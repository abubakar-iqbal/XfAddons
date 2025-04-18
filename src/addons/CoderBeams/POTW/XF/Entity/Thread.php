<?php
namespace CoderBeams\POTW\XF\Entity;

class Thread extends XFCP_Thread
{
    protected function setupApiResultData(
		\XF\Api\Result\EntityResult $result, $verbosity = self::VERBOSITY_NORMAL, array $options = []
	)
	{
		parent::setupApiResultData($result,$verbosity,$options);
		$avatarUrls = [];
		foreach (array_keys($this->app()->container('avatarSizeMap')) AS $avatarSize)
		{
			$avatarUrls[$avatarSize] = $this->LastPoster->getAvatarUrl($avatarSize, null, true);
		}
		$result->last_poster_avatar_urls = $avatarUrls;
    }

}