<?php

/**
 * Attachments component attachment model
 *
 * @package Attachments
 * @subpackage Attachments_Component
 *
 * @copyright Copyright (C) 2007-2025 Jonathan M. Cameron, All Rights Reserved
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 * @link https://github.com/jmcameron/attachments
 * @author Jonathan M. Cameron
 */

namespace JMCameron\Component\Attachments\Administrator\Model;

use JMCameron\Plugin\AttachmentsPluginFramework\AttachmentsPluginManager;
use JMCameron\Component\Attachments\Administrator\Helper\AttachmentsUploadHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;
use Exception;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Attachment Model
 *
 * @package Attachments
 */
class AttachmentModel extends AdminModel
{
    /**
     * Returns a reference to the a Table object, always creating it.
     *
     * @param       type    The table type to instantiate
     * @param       string  A prefix for the table class name. Optional.
     * @param       array   Configuration array for model. Optional.
     * @return      Table   A database object
     * @since       1.6
     */
    public function getTable($type = 'Attachment', $prefix = 'Administrator', $config = array())
    {
        /** @var \Joomla\CMS\MVC\Factory\MVCFactory $mvc */
        $mvc = Factory::getApplication()
                ->bootComponent("com_attachments")
                ->getMVCFactory();
        return $mvc->createTable($type, $prefix, $config);
    }


    /**
     * Override the getItem() command to get some extra info
     *
     * @param   integer $pk The id of the primary key.
     *
     * @return  mixed   Object on success, false on failure.
     */
    public function getItem($pk = null)
    {
        if ($this->item = parent::getItem($pk))
        {
            if ((int) $this->item->id > 0)
            {
                $db = Factory::getContainer()->get('DatabaseDriver');

                $query = $db
                    ->getQuery(true)
                    ->select($db->qn('name'))
                    ->from($db->qn('#__users'))
                    ->where($db->qn('id') . ' = :id')
                    ->bind(':id', $this->item->created_by, ParameterType::INTEGER);

                try
                {
                    $this->item->author_name = $db
                        ->setQuery($query, 0, 1)
                        ->loadResult();
                }
                catch (Exception $e)
                {
                    Factory::getApplication()->enqueueMessage($e->getMessage() . ' (ERR 112)', 'error');
                }

                $query = $db
                    ->getQuery(true)
                    ->select($db->qn('name'))
                    ->from($db->qn('#__users'))
                    ->where($db->qn('id') . ' = :id')
                    ->bind(':id', $this->item->modified_by, ParameterType::INTEGER);

                try
                {
                    $this->item->editor_name = $db
                        ->setQuery($query, 0, 1)
                        ->loadResult();
                }
                catch (Exception $e)
                {
                    Factory::getApplication()->enqueueMessage($e->getMessage() . ' (ERR 113)', 'error');
                }

                $uri = Uri::getInstance();
                /* problem z generowaniem kolejnych / w urlu
                                // Fix the URL for files
                                if ($this->item->uri_type == 'file')
                                {
                                    $this->item->url = $uri->root(true) . '/' . $this->item->url;
                                }
                */
                $parentId = $this->item->parent_id;
                $parentType = $this->item->parent_type;
                $parentEntity = $this->item->parent_entity;

                // Get the parent handler
                PluginHelper::importPlugin('attachments');
                $apm = AttachmentsPluginManager::getAttachmentsPluginManager();

                if (!$apm->attachmentsPluginInstalled($parentType))
                {
                    // Exit if there is no Attachments plugin to handle this parentType
                    $errMsg = Text::sprintf('ATTACH_ERROR_INVALID_PARENT_TYPE_S', $parentType) . ' (ERR 133)';
                    Factory::getApplication()->enqueueMessage($errMsg, 'error');

                    return false;
                }

                $entityInfo = $apm->getInstalledEntityInfo();
                $parent = $apm->getAttachmentsPlugin($parentType);

                // Get the parent info
                $parentEntityName = Text::_('ATTACH_' . $parentEntity);
                $parentTitle = $parent->getTitle($parentId, $parentEntity);

                if (!$parentTitle)
                {
                    $parentTitle = Text::sprintf('ATTACH_NO_PARENT_S', $parentEntityName);
                }

                $this->item->parent_id = $parentId;
                $this->item->parent_entity_name = $parentEntityName;
                $this->item->parent_title = $parentTitle;
                $this->item->parent_published = $parent->isParentPublished($parentId, $parentEntity);

                // Set parent type and entity field accordingly, also set id as parenttype__entitytype = parent_id field
                $this->item->parent_type_list = $this->item->parent_type . '__' . $this->item->parent_entity;
                $this->item->{$parentType . '__' . $parentEntity} = $this->item->parent_id;
            }
            else
            {
                $app = Factory::getApplication();
                $gus = $app->getUserState('com_attachments.edit.attachment.data');

                $input = $app->getInput();
                $editor = $input->getString('editor', '');

                if ($gus == null)
                {
                    // Get some info from previous link (ie from xtd-button, addAttachment button)
                    $from = $input->getString('from', '');
                    $editor = $input->getString('editor', '');
                    $parentType = explode('.', $input->getString('parent_type', 'com_content.article'));
                    $parentId = $input->getInt('parent_id', 0);
                }
                else
                {
                    // Get some info from previously set form data
                    $from = $gus['from'];
                    $parentType = explode('__', $gus['parent_type_list']);
                    $parentId = $gus['parent_id'];
                }

                // Set up the "select parent" controls
                PluginHelper::importPlugin('attachments');
                $apm = AttachmentsPluginManager::getAttachmentsPluginManager();

                if (!$apm->attachmentsPluginInstalled($parentType[0]))
                {
                    // Exit if there is no Attachments plugin to handle this parentType
                    $errMsg = Text::sprintf('ATTACH_ERROR_INVALID_PARENT_TYPE_S', $parentType[0]) . ' (ERR 123)';
                    Factory::getApplication()->enqueueMessage($errMsg, 'error');
                }

                $entityInfo = $apm->getInstalledEntityInfo();
                $parent = $apm->getAttachmentsPlugin($parentType[0]);

                $parentEntity = $parent->getCanonicalEntityId($parentType[1]);
                $parentEntityName = Text::_('ATTACH_' . $parentEntity);

                // Disable the main menu items
                $input->get('hidemainmenu', 1);

                // Get the parent entity title
                $parentTitle = '';

                if ((int) $parentId > 0)
                {
                    $parentTitle = $parent->getTitle($parentId, $parentEntity);
                }

                // Get the component parameters
                $params = ComponentHelper::getParams('com_attachments');

                // We do not have a real attachment yet so fake it
                $this->item = new \stdClass();
                $this->item->id = 0;
                $this->item->uri_type = 'file';
                $this->item->state = $params->get('publish_default', false);
                $this->item->url = '';
                $this->item->url_relative = false;
                $this->item->url_verify = true;
                $this->item->display_name = '';
                $this->item->description = '';
                $this->item->user_field_1 = '';
                $this->item->user_field_2 = '';
                $this->item->user_field_3 = '';
                $this->item->parent_id = $parentId;
                $this->item->parent_type = $parentType[0];
                $this->item->parent_entity = $parentEntity;
                $this->item->parent_title  = $parentTitle;
                $this->item->parent_type_list = $parentType[0] . '__' . $parentEntity;
                $this->item->{$parentType[0] . '__' . $parentEntity} = $parentId;
                $this->item->editor = $editor;
                $this->item->from = $from;
            }
        }

        return $this->item;
    }

    /**
     * Method to get the record form.
     *
     * @param       array   $data           Data for the form.
     * @param       boolean $loadData       True if the form is to load its own data (default case), false if not.
     * @return      mixed   A JForm object on success, false on failure
     * @since       1.6
     */
    public function getForm($data = array(), $loadData = true)
    {
        // Get the form.
        $form = $this->loadForm(
            'com_attachments.attachment',
            'attachment',
            array('control' => 'jform', 'load_data' => $loadData)
        );
        if (empty($form)) {
            return false;
        }

        /**
         * TODO FOR FURTHER RELEASES: Add new parent types
         * Here we create additional fields for new parent types entities - we need to create a way to gather info about new forms of parents
         * Test against showon parameter for new parents, showon should be set in field->setAttribute method
         * We need to remember to add new parents to attachments filter on main form
         * Possible code for adding new parents to select list:
         * $form->getField("parent_type_list")->addOption("Another type", ['value' => 'com_xyz__1']);
         * $form->getField("parent_type_list")->addOption("Yet another type", ['value' => 'com_xyz__2']);
         * $fields = [];
         * $fields[] = '<field name="another_type" type="text" label="Another type - select" showon="parent_type_list:com_xyz__1" />';
         * $fields[] = '<field name="yet_another_type" type="text" label="Yet another type - select" showon="parent_type_list:com_xyz__2" />';
         * $element = '<fieldset name="additionalcontentfields">' . implode('', $fields) . '</fieldset>';
         * $xml = new SimpleXMLElement($element);
         * $form->setField($xml, null, true, 'additionalcontentfields');
         *
         * Code should also be set in site AttachmentModel getForm.
         */

        return $form;
    }


    /**
     * Method to get the data that should be injected in the form.
     *
     * @return      mixed   The data for the form.
     * @since       1.6
     */
    protected function loadFormData()
    {
        // Check the session for previously entered form data.
        /** @var \Joomla\CMS\Application\CMSApplication $app */
        $app = Factory::getApplication();
        $data = $app->getUserState('com_attachments.edit.attachment.data', array());
        if (empty($data)) {
            $data = $this->getItem();
        }
        return $data;
    }

    /**
     * Validate parent info, type, entity, id
     *
     * @access  protected
     *
     * @param   &object  $data    attachment data to validate
     * @param   &object  $parent  parent info to which we validate
     *
     * @return  boolean  true if success
     *
     * @throws  Exception
     *
     * @since   4.2
     */
    protected function validateParent(&$data, &$parent): bool
    {
        // Test parent selection
        if ($data->parent_type_list)
        {
            $parent = explode('__', $data->parent_type_list);

            if (count($parent) != 2)
            {
                $this->setError(Text::sprintf('ATTACH_ERROR_INVALID_PARENT_TYPE_S', $parent[0]));

                return false;
            }

            $data->parent_type = $parent[0];

            if ($data->parent_type == 'com_categories')
            {
                $data->parent_type = 'com_content';
            }

            $data->parent_entity = $parent[1];
            $data->parent_id = $data->{$data->parent_type_list};

            // todo rozpoznawac new content ktory nie ma jeszcze id a mamy juz dodany zalacznik
            if (empty($data->parent_id))
            {
                $this->setError(Text::sprintf('ATTACH_ERROR_INVALID_PARENT_ENTITY_NOT_SELECTED', $data->parent_id) . ' (ERR 122)');

                return false;
            }

            PluginHelper::importPlugin('attachments');
            $apm = AttachmentsPluginManager::getAttachmentsPluginManager();;

            $entityInfo = $apm->getInstalledEntityInfo();
            $parent = $apm->getAttachmentsPlugin($data->parent_type);

            if (empty($parent))
            {
                $this->setError(Text::sprintf('ATTACH_ERROR_INVALID_PARENT_TYPE_S', $data->parent_type));

                return false;
            }

            $parent->id = $data->parent_id;
            $parent->type = $data->parent_type;
            $parent->entity = $parent->getCanonicalEntityName($data->parent_entity);
            // TODO: odkomentowanie tego generuje blad abstract!!! zweryfikowac uzytecznosc tego - nie zamyka sie modalny przez to ??
//            $parent->entity_name = Text::_('ATTACH_' . $data->parent_entity);
        }
        else
        {
            $this->setError(Text::sprintf('ATTACH_ERROR_INVALID_PARENT_ID_S', $data->parent_id) . ' (ERR 123)');

            return false;
        }

        return true;
    }

    /**
     * Validate URI info if url attachment type was selected
     *
     * @access  protected
     *
     * @param   &object  $data  attachment data to validate
     *
     * @return  boolean  true if success
     *
     * @since   4.1.5
     */
    protected function validateUriType(&$data): bool
    {

        if ($data->uri_type === 'url')
        {
            if (empty((string) $data->url))
            {
                $this->setError(Text::sprintf('ATTACH_ERROR_IN_URL_EMPTY_S') . ' (ERR 124)');

                if (filter_var($data->url, FILTER_VALIDATE_URL) === false)
                {
                    $this->setError(Text::sprintf('ATTACH_ERROR_IN_URL_INVALID_S') . ' (ERR 125)');
                }

                return false;
            }
        }

        return true;
    }

    /**
     * Validate URI info if url attachment type was selected
     *
     * @access  protected
     *
     * @param   &object  $data    attachment data to validate
     * @param   &object  $parent  parent info to which we validate
     * @param   &object  $table   attachment data from table
     *
     * @return  boolean  true if success
     *
     * @throws  Exception
     *
     * @since   4.1.5
     */
    protected function validateUploadFile(&$data, &$parent, &$table): bool
    {
        if ((string) $data->uri_type == 'file')
        {
            // Get the component parameters
            $params = ComponentHelper::getParams('com_attachments');
            $testSafeFile = $params->get('test_is_safe_file', true);

            if ($testSafeFile)
            {
                $ignoreIsSafeTest = 'CMD';
            }
            else
            {
                $ignoreIsSafeTest = 'RAW';
            }

            $files = Factory::getApplication()->input->files->get('jform', -9, $ignoreIsSafeTest);

            // file previously uploaded and not changed
            if (($files['filename']['error'] == 4) && (!empty($data->filename_sys))) {
                return true;
            }

            if (((int) $files !== -9) && ($files['filename']['error'] && ($files['filename']['error'] == UPLOAD_ERR_NO_FILE)))
            {
                // Filename was not provided - update only other fields
                if ($data->id)
                {
                    // Move attachment file system location when parent was changed
                    $errMsg = AttachmentsUploadHelper::switchParent($data, $data->parent_id, $data->parent_type, $data->parent_entity);

                    if ($errMsg)
                    {
                        throw new Exception($errMsg, 500);
                    }

                    $table->filename_sys = $data->filename_sys;
                    $table->url = $data->url;

                    return $table->store();
                }
                else
                {
                    $this->setError(Text::_('ATTACH_YOU_MUST_SELECT_A_FILE_TO_UPLOAD'));

                    return false;
                }
            }
            else
            {
                if (((int) $files === -9) && ($ignoreIsSafeTest !== 'RAW'))
                {
                    $this->setError(Text::_('ATTACH_ERROR_FILENAME_INSECURE_S') . ' (ERR 126)');

                    return false;
                }

                $errMsg = AttachmentsUploadHelper::uploadFile($table, $parent, $files, $data->id == 0);

                if (!empty($errMsg))
                {
                    $this->setError($errMsg);
                }

                return $errMsg == '';
            }
        }

        return true;
    }
    /**
     * Method to save the form data.
     *
     * @access  public
     *
     * @param   array  $data  The form data.
     *
     * @return  boolean  True on success.
     *
     * @throws  Exception
     *
     * @since   4.2
     */
    public function save($data): bool
    {
        $this->parent = null;
        $data = (object) $data;
        $table = $this->getTable();

        $id = Factory::getApplication()->input->getInt('id');

        if (!$this->validateParent($data, $this->parent))
        {
            return false;
        }

        if (!$this->validateUriType($data))
        {
            return false;
        }

        $table->load($id);

        // Prepare info for possible parent type switch - move attachment to new location
        $props = $table->getProperties();
        $data->parent_id_old = $props['parent_id'];
        $data->parent_type_old = $props['parent_type'];
        $data->parent_entity_old = $props['parent_entity'];
        $data->filename = $props['filename'];
        $data->filename_sys = $props['filename_sys'];

        if (!$table->bind($data))
        {
            return false;
        }

        if ($data->uri_type === 'url')
        {
            return $table->store();
        }
        else
        {
            if (!$this->validateUploadFile($data, $this->parent, $table))
            {
                return false;
            }
        }

        $this->cleanCache();

        return true;
    }
}
