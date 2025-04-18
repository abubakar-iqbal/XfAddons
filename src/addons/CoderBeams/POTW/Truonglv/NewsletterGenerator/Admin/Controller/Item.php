<?php

namespace CoderBeams\POTW\Truonglv\NewsletterGenerator\Admin\Controller;

use XF\Util\File;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use Truonglv\NewsletterGenerator\Entity\Item as EntityItem;
class Item extends XFCP_Item
{


    protected function getItemEditForm(EntityItem $item): AbstractReply
    {
       if($item->handler_class=='CoderBeams\POTW\NewsletterGenerator\Generator\Potw')
       {
        return $this->view(
            'Truonglv\NewsletterGenerator:Item\Form',
            'cb_potw_newsletter_item_edit',
            [
                'item' => $item,
                'linkPrefix' => $this->getLinkPrefix(),
            ]
        );
       }
       else{
       return parent::getItemEditForm($item);
    }
    }
    public function actionPreview(ParameterBag $params)
    {
        $item = $this->assertItemExists(intval($params['item_id']));
        if($item->handler_class=='CoderBeams\POTW\NewsletterGenerator\Generator\Potw')
        {
            $html = $item->Handler->render();

            if (strlen($html) === 0) {
                return $this->error(\XF::phrase('newsletter_generator_item_could_not_render'));
            }
    
            if ($this->request()->exists('download')) {
                $tempFile = File::getTempFile();
                file_put_contents($tempFile, $html);
    
                $this->setResponseType('raw');
    
                return $this->view('Truonglv\NewsletterGenerator:Item\HTML', '', [
                    'item' => $item,
                    'filePath' => $tempFile,
                ]);
            }
    
            echo $html;
            die;
        }
        else{
            parent::actionPreview($params);
        }

    }
}