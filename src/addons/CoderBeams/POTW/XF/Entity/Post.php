<?php
namespace CoderBeams\POTW\XF\Entity;

use XF\BbCode\RenderableContentInterface;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;
class Post extends XFCP_Post
{
	protected function setupApiResultData(
		\XF\Api\Result\EntityResult $result, $verbosity = self::VERBOSITY_NORMAL, array $options = []
	)
	{
		parent::setupApiResultData($result,$verbosity,$options);
        $result->thread_title=$this->Thread->title;
		$result->view_url =$this->app()->router('public')->buildLink('canonical:threads/post', $this->Thread, ['post_id' => $this->post_id]);
    }

}