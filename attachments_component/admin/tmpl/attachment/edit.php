<?php
/**
 * Attachments component
 *
 * @package Attachments
 * @subpackage Attachments_Component
 *
 * @copyright Copyright (C) 2007-2018 Jonathan M. Cameron, All Rights Reserved
 * @license http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 * @link http://joomlacode.org/gf/project/attachments/frs/
 * @author Jonathan M. Cameron
 */

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

// No direct access
defined('_JEXEC') or die('Restricted access');

// Load the tooltip behavior.
HTMLHelper::_('bootstrap.tooltip');

// Add the plugins stylesheet to style the list of attachments
/** @var \Joomla\CMS\Application\CMSApplication $app */
$app = Factory::getApplication();
$document = $app->getDocument();
$input = $app->input;
$uri = Uri::getInstance();

// In case of modal
$isModal = $input->get('layout') === 'modal';
$layout = $isModal ? 'modal' : 'edit';
$tmpl = $isModal || $input->get('tmpl') === 'component' ? '&tmpl=component' : '';
$editor = $input->get('editor');

$wa = $app->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')
    ->useScript('form.validate');
?>

    <form class="form-validate" enctype="multipart/form-data"
          name="adminForm" id="adminForm"
          action="<?php
          echo Route::_(
              'index.php?option=com_attachments&view=attachment&layout=' . $layout . $tmpl . '&id=' . (int)$this->item->id
          ); ?>" method="post">

        <div class="main-card">
            <?php
            echo HTMLHelper::_(
                'uitab.startTabSet',
                'myTab',
                ['active' => 'general', 'recall' => true, 'breakpoint' => 768]
            ); ?>
            <?php
            echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', Text::_('ATTACH_ADD_ATTACHMENT'));
            //    if ( $this->item->parent_title ) {
            //        echo "<h1>" . Text::sprintf('ATTACH_PARENT_S_COLON_S', $this->item->parent_entity_name, $this->item->parent_title) . "</h1>";
            //    }
            ?>
            <div class="row">
                <div class="col-lg-12">
                    <div>
                        <?php echo $this->form->renderField('parent_type_list'); ?>
                        <?php
                        //TODO dorobic weryfikacje editor == add_to_parent gdy nie potrzeba tego wyswietlać

                        // render all found fields with content types to which we can attach attachment
                        foreach($this->form->getFieldset('content_types') as $field) {
                            echo $field->renderField();
                        }
                        ?>
                        <?php
                        echo $this->form->renderField('uri_type'); ?>
                        <?php
                        if ($this->item->uri_type == 'file'):
                            if ($this->item->id != 0) {
                                echo $this->form->renderField('filename_current');
                            } ?>
                        <?php
                        endif; ?>

                        <?php
                        echo $this->form->renderField('filename'); ?>
                        <?php
                        echo $this->form->renderField('url'); ?>
                        <?php
                        echo $this->form->renderField('url_note'); ?>
                        <div class="row">
                            <div class="col-12 col-lg-6">
                                <?php
                                echo $this->form->renderField('url_valid'); ?>
                            </div>
                            <div class="col-12 col-lg-6">
                                <?php
                                echo $this->form->renderField('url_relative'); ?>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12 col-lg-9">
                                <?php
                                echo $this->form->renderField('display_name'); ?>
                            </div>
                            <div class="col-12 col-lg-3">
                                <?php
                                echo Text::_('ATTACH_OPTIONAL'); ?>
                            </div>
                        </div>
                        <?php
                        echo $this->form->renderField('description'); ?>
                        <?php
                        //if ( $this->may_publish ): ?>
                        <div class="row">
                            <div class="col-lg-6">
                                <div>
                                    <?php
                                    echo $this->form->renderField('state'); ?>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div>
                                    <?php
                                    //endif; ?>
                                    <?php
                                    echo $this->form->renderField('access'); ?>
                                </div>
                            </div>
                        </div>
                        <?php
                        if ($this->show_user_field_1): ?>
                            <?php
                            echo $this->form->renderField('user_field_1'); ?>
                        <?php
                        endif; ?>
                        <?php
                        if ($this->show_user_field_2): ?>
                            <?php
                            echo $this->form->renderField('user_field_2'); ?>
                        <?php
                        endif; ?>
                        <?php
                        if ($this->show_user_field_3): ?>
                            <?php
                            echo $this->form->renderField('user_field_3'); ?>
                        <?php
                        endif; ?>

                        <?php
                        if ($this->item->parent_id == 0): ?>
                            <input type="hidden" name="new_parent" value="1"/>
                        <?php
                        elseif ($this->item->parent_id): ?>
                            <input type="hidden" name="parent_id" value="<?php
                            echo $this->item->parent_id; ?>"/>
                        <?php
                        endif; ?>
                        <input type="hidden" name="save_type" value="upload"/>
                        <?php
                        /**
                        if ($editor == 'add_to_parent'): ?>
                        <?php
                        $parent_type = explode('.', $input->get('parent_type'),);
                        // todo przerobic ponizsze hidden na renderfield->hidden ?? jesli sie da
                        ?>
                        <input type="hidden" name="jform[parent_type]" value="<?php
                        echo $parent_type[0]; ?>"/>
                        <input type="hidden" name="jform[parent_type_list]" value="<?php
                        echo $parent_type[0] . '.' . $parent_type[1]; ?>"/>
                        <input type="hidden" name="jform[parent_id]" value="<?php
                        echo $input->get('parent_id'); ?>"/>

                        <?php
                        endif;
                         **/
                        ?>
                        <input type="hidden" name="task" value="attachment.saveNew"/>
                        <input type="hidden" name="from" value="<?php echo $this->from; ?>"/>
                        <?php
                        if ($this->item->from == 'closeme'): ?>
                            <div class="form_buttons" align="center">
                                <button type="button" class="btn btn-primary"
                                        onclick="Joomla.submitbutton('attachment.saveNew')"><?php
                                    echo Text::_('ATTACH_UPLOAD_VERB'); ?></button>
                                <span class="right">
                      <input class="btn btn-primary" type="button" value="<?php
                      echo Text::_('ATTACH_CANCEL'); ?>"
                             onClick="window.parent.bootstrap.Modal.getInstance(window.parent.document.querySelector('.joomla-modal.show')).hide();"/>
                   </span>
                            </div>
                        <?php
                        endif; ?>
                        <?php
                        echo HtmlHelper::_('form.token'); ?>
                    </div>
                </div>
            </div>
            <?php
            echo HTMLHelper::_('uitab.endTab'); ?>
            <?php
            echo HTMLHelper::_('uitab.endTabSet'); ?>
        </div>
    </form>
<?php

// Show the existing attachments
if (($this->item->uri_type == 'file') && $this->item->parent_id) {
    /** Get the Attachments controller class */
    /** @var \Joomla\CMS\MVC\Factory\MVCFactory $mvc */
    $mvc = Factory::getApplication()
        ->bootComponent("com_attachments")
        ->getMVCFactory();
    /** @var \JMCameron\Component\Attachments\Administrator\Controller\ListController $controller */
    $controller = $mvc->createController('List', 'Administrator', [], $app, $app->getInput());
//	$controller->displayString($this->item->parent_id, $this->item->parent_type, $this->item->parent_entity,
//							   'ATTACH_EXISTING_ATTACHMENTS', false, false, true, $this->from);
}
