<?php
namespace CoderBeams\POTW\Truonglv\NewsletterGenerator\Repository;

use CoderBeams\POTW\NewsletterGenerator\Generator\Potw;
use Truonglv\NewsletterGenerator\Generator\Thread;

class Item extends XFCP_Item
{
    public function getHandlerClasses(): array
    {        
        return [
            Thread::class,
            Potw::class,
        ];
    }
}