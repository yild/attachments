<?php

/**
 * Attachments component attachment controller
 *
 * @package Attachments
 * @subpackage Attachments_Component
 *
 * @copyright Copyright (C) 2007-2025 Jonathan M. Cameron, All Rights Reserved
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 * @link https://github.com/jmcameron/attachments
 * @author Jonathan M. Cameron
 */

namespace JMCameron\Component\Attachments\Administrator\Controller;

use JMCameron\Component\Attachments\Administrator\Field\AccessLevelsField;
use JMCameron\Component\Attachments\Site\Helper\AttachmentsDefines;
use JMCameron\Component\Attachments\Site\Helper\AttachmentsHelper;
use JMCameron\Component\Attachments\Site\Helper\AttachmentsJavascript;
use JMCameron\Plugin\AttachmentsPluginFramework\AttachmentsPluginManager;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Input\Input;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * Attachment Controller
 *
 * @package Attachments
 */
class AttachmentController extends FormController
{
    /**
     * Constructor.
     *
     * @param   array An optional associative array of configuration settings.
     *
     * @return  FormController
     */
    public function __construct(
        $config = array(),
        MVCFactoryInterface $factory = null,
        ?CMSApplication $app = null,
        ?Input $input = null,
        FormFactoryInterface $formFactory = null
    ) {
        parent::__construct($config, $factory, $app, $input, $formFactory);

        $this->registerTask('applyNew', 'saveNew');
        $this->registerTask('save2New', 'saveNew');
    }


    /**
     * Method to check whether an ID is in the edit list.
     *
     * @param   string  $context    The context for the session storage.
     * @param   int     $id         The ID of the record to add to the edit list.
     *
     * @return  boolean True if the ID is in the edit list.
     */
    protected function checkEditId($context, $id)
    {
        // ??? Do not think this function is used currently
        return true;
    }


    /**
     * Add - Display the form to create a new attachment
     *
     */
    public function add()
    {
        // Fail gracefully if the Attachments plugin framework plugin is disabled
        if (!PluginHelper::isEnabled('attachments', 'framework')) {
            $this->app->enqueueMessage(Text::_('ATTACH_WARNING_ATTACHMENTS_PLUGIN_FRAMEWORK_DISABLED'));

            return false;
        }

        // Access check.
        $app = $this->app;
        $user = $app->getIdentity();
        if ($user === null || !$user->authorise('core.create', 'com_attachments')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR') . ' (ERR 121)', 403);
        }

        // Access check.
        if (!$this->allowAdd()) {
            // Set the internal error and also the redirect error.
            $this->setMessage(Text::_('JLIB_APPLICATION_ERROR_CREATE_RECORD_NOT_PERMITTED'), 'error');
            $this->setRedirect(Route::_('index.php?option=' . $this->option .
                                         '&view=' . $this->view_list . $this->getRedirectToListAppend(), false));
            return false;
        }

        $parentEntity = 'default';
        $parentType = $this->input->getString('parent_type', '');

        if (strpos($parentType, '.')) {
            $parentInfo = explode('.', $parentType);
            $parentType = $parentInfo[0];
            $parentEntity = $parentInfo[1];
        }

        if (($parentType == '') || ($parentType == 'com_categories')) {
            $parentType = 'com_content';
        }

        // Use a component template for the iframe view (from the article editor)
        $from = $this->input->getWord('from', '');
        if ($from == 'closeme') {
            $this->input->set('tmpl', 'component');
        }

        // Redirect to the edit screen.
        $this->setRedirect(
            Route::_(
                'index.php?option=' . $this->option
                . '&view=' . $this->view_item
                . $this->getRedirectToItemAppend()
                . '&parent_type=' . $parentType . '.' . $parentEntity
                . '&parent_id=' . $this->input->getInt('parent_id')
                . '&from=' . $this->input->getString('from')
                . '&editor=' . $this->input->getString('editor'), false)
        );
    }

    /**
     * Save an new attachment
     */
    public function saveNew()
    {
        // Check if plugins are enabled
        if (!PluginHelper::isEnabled('attachments', 'framework')) {
            $this->app->enqueueMessage(Text::_('ATTACH_WARNING_ATTACHMENTS_PLUGIN_FRAMEWORK_DISABLED'), 'error');

            return false;
        }

        // Check for request forgeries.
        Session::checkToken();

        $model = $this->getModel();
        $data = $this->input->post->get('jform', [], 'array');
        $currentUri = (string)Uri::getInstance();

        if (!$this->allowSave($data)) {
            $this->setMessage(Text::_('JLIB_APPLICATION_ERROR_SAVE_NOT_PERMITTED'), 'error');

            return false;
        }

        // Validate the posted data.
        $form = $model->getForm($data, false);

        if (!$form) {
            throw new Exception($model->getError(), 500);
        }

        $validData = $model->validate($form, $data);

        // Check for validation errors.
        if ($validData === false) {
            // Get the validation messages.
            $errors = $model->getErrors();

            // Push up to three validation messages out to the user.
            for ($i = 0, $n = count($errors); $i < $n && $i < 3; $i++) {
                if ($errors[$i] instanceof Exception) {
                    $this->app->enqueueMessage($errors[$i]->getMessage(), 'warning');
                } else {
                    $this->app->enqueueMessage($errors[$i], 'warning');
                }
            }

            // Save the data in the session.
            $this->app->setUserState('com_attachments.edit.attachment.data', $data);

            // Redirect back to the same screen.
            $this->setRedirect($currentUri);

            return false;
        }

        if (!$model->save($validData)) {
            // Save the data in the session.
            $this->app->setUserState('com_attachments.edit.attachment.data', $data);

            $this->setMessage(Text::sprintf('JLIB_APPLICATION_ERROR_SAVE_FAILED', $model->getError()), 'error');

            // Redirect back to the edit screen.
            $this->setRedirect($currentUri);

            return false;
        }

        $this->setMessage(Text::_('ATTACH_ATTACHMENT_SAVED'));

        $from = $data['from'];

        if ($from === 'closeme') {
            // Close the iframe and refresh the attachments list in the parent window
            $uri = Uri::getInstance();
            $baseUrl = $uri->base(true);
            $lang = $this->input->getCmd('lang', '');

            AttachmentsJavascriptHelper::closeModalAndRefreshAttachments(
                $baseUrl,
                $validData['parent_type'],
                $validData['parent_entity'],
                (int)$validData[$validData['parent_type_list']],
                $lang,
                $from,
                'save'
            );
        } else {
            $this->setRedirect(
                Route::_('index.php?option=com_attachments&view=attachments' . $this->getRedirectToListAppend(), false)
            );
        }

        // Clear the ancillary data from the session.
        $this->app->setUserState('com_attachments.edit.attachment.data', null);

        return true;
    }


    /**
     * Edit - display the form for the user to edit an attachment
     *
     * @param   string  $key     The name of the primary key of the URL variable (IGNORED)
     * @param   string  $urlVar  The name of the URL variable if different from the primary key. (IGNORED)
     */
    public function edit($key = null, $urlVar = null)
    {
        // Fail gracefully if the Attachments plugin framework plugin is disabled
        if (!PluginHelper::isEnabled('attachments', 'framework')) {
            echo '<h1>' . Text::_('ATTACH_WARNING_ATTACHMENTS_PLUGIN_FRAMEWORK_DISABLED') . '</h1>';
            return;
        }

        // Access check.
        $app = $this->app;
        $user = $app->getIdentity();
        if (
            $user === null or !($user->authorise('core.edit', 'com_attachments') or
               $user->authorise('core.edit.own', 'com_attachments'))
        ) {
            throw new \Exception(Text::_('ATTACH_ERROR_NO_PERMISSION_TO_EDIT') . ' (ERR 132)', 403);
        }

        // TODO remove this hack in next revision
        // default parent::edit() expects cid sent by POST request
        $this->input->post->set('cid', $this->input->get('cid', [], 'int'));

        return parent::edit();
    }


    /**
     * Save an attachment (from editing)
     */
    public function save($key = null, $urlVar = null)
    {
        // Check for request forgeries
        Session::checkToken() or die(Text::_('JINVALID_TOKEN'));

        // Access check.
        $app = $this->app;
        $user = $app->getIdentity();
        if (
            $user === null or !($user->authorise('core.edit', 'com_attachments') or
               $user->authorise('core.edit.own', 'com_attachments'))
        ) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR') . ' (ERR 134)', 403);
        }

        /** @var \JMCameron\Component\Attachments\Administrator\Model\AttachmentModel $model */
        $model      = $this->getModel();
        $attachment = $model->getTable();

        // Make sure the article ID is valid
        $input = $app->getInput();
        $attachment_id = $input->getInt('id');
        if (!$attachment->load($attachment_id)) {
            $errmsg = Text::sprintf(
                'ATTACH_ERROR_CANNOT_UPDATE_ATTACHMENT_INVALID_ID_N',
                $attachment_id
            ) . ' (ERR 135)';
            throw new \Exception($errmsg, 500);
        }

        // Note the old uri type
        $old_uri_type = $attachment->uri_type;

        // Get the data from the form
        if (!$attachment->bind($input->post->getArray())) {
            $errmsg = $attachment->getError() . ' (ERR 136)';
            throw new \Exception($errmsg, 500);
        }

        // Get the parent handler for this attachment
        PluginHelper::importPlugin('attachments');
        PluginHelper::importPlugin('content');

        $apm = AttachmentsPluginManager::getAttachmentsPluginManager();
        if (!$apm->attachmentsPluginInstalled($attachment->parent_type)) {
            $errmsg = Text::sprintf('ATTACH_ERROR_INVALID_PARENT_TYPE_S', $attachment->parent_type) . ' (ERR 135B)';
            throw new \Exception($errmsg, 500);
        }
        $parent = $apm->getAttachmentsPlugin($attachment->parent_type);

        // See if the parent ID has been changed
        $parent_changed = false;
        $old_parent_id = $input->getString('old_parent_id');
        if ($old_parent_id == '') {
            $old_parent_id = null;
        } else {
            $old_parent_id = $input->getInt('old_parent_id');
        }

        // Handle new parents (in process of creation)
        if ($parent->newParent($attachment)) {
            $attachment->parent_id = null;
        }

        // Deal with updating an orphaned attachment
        if (($old_parent_id == null) && is_numeric($attachment->parent_id)) {
            $parent_changed = true;
        }

        // Check for normal parent changes
        if ($old_parent_id && ( $attachment->parent_id != $old_parent_id )) {
            $parent_changed = true;
        }

        // See if we are updating a file or URL
        $new_uri_type = $input->getWord('update');
        if ($new_uri_type && !in_array($new_uri_type, AttachmentsDefines::$LEGAL_URI_TYPES)) {
            // Make sure only legal values are entered
            $new_uri_type = '';
        }

        // See if the parent type has changed
        $new_parent_type = $input->getCmd('new_parent_type');
        $new_parent_entity = $input->getCmd('new_parent_entity');
        $old_parent_type = $input->getCmd('old_parent_type');
        $old_parent_entity = $input->getCmd('old_parent_entity');
        if (
            ($new_parent_type &&
              (($new_parent_type != $old_parent_type) ||
               ($new_parent_entity != $old_parent_entity)))
        ) {
            $parent_changed = true;
        }

        // If the parent has changed, make sure they have selected the new parent
        if ($parent_changed && ( (int)$attachment->parent_id == -1 )) {
            $errmsg = Text::sprintf('ATTACH_ERROR_MUST_SELECT_PARENT');
            echo "<script type=\"text/javascript\"> alert('$errmsg'); window.history.go(-1); </script>\n";
            exit();
        }

        // If the parent has changed, switch the parent, rename files if necessary
        if ($parent_changed) {
            if (($new_uri_type == 'url') && ($old_uri_type == 'file')) {
                // If we are changing parents and converting from file to URL, delete the old file

                // Load the attachment so we can get its filename_sys
                $db = Factory::getContainer()->get('DatabaseDriver');
                $query = $db->getQuery(true);
                $query->select('filename_sys, id')->from('#__attachments')->where('id=' . (int)$attachment->id);
                $db->setQuery($query, 0, 1);
                $filename_sys = $db->loadResult();
                File::delete($filename_sys);
                AttachmentsHelper::cleanDirectory($filename_sys);
            } else {
                // Otherwise switch the file/url to the new parent
                if ($old_parent_id == null) {
                    $old_parent_id = 0;
                    // NOTE: When attaching a file to an article during creation,
                    //       the article_id (parent_id) is initially null until
                    //       the article is saved (at that point the
                    //       parent_id/article_id updated).  If the attachment is
                    //       added and creating the article is canceled, the
                    //       attachment exists but is orphaned since it does not
                    //       have a parent.  It's article_id is null, but it is
                    //       saved in directory as if its article_id is 0:
                    //       article/0/file.txt.  Therefore, if the parent has
                    //       changed, we pretend the old_parent_id=0 for file
                    //       renaming/moving.
                }

                $error_msg = AttachmentsHelper::switchParent(
                    $attachment,
                    $old_parent_id,
                    $attachment->parent_id,
                    $new_parent_type,
                    $new_parent_entity
                );
                if ($error_msg != '') {
                    $errmsg = Text::_($error_msg) . ' (ERR 137)';
                    $link = 'index.php?option=com_attachments';
                    $this->setRedirect($link, $errmsg, 'error');
                    return;
                }
            }
        }

        // Update parent type/entity, if needed
        if ($new_parent_type && ($new_parent_type != $old_parent_type)) {
            $attachment->parent_type = $new_parent_type;
        }
        if ($new_parent_type && ($new_parent_entity != $old_parent_entity)) {
            $attachment->parent_entity = $new_parent_entity;
        }

        // Get the article/parent handler
        if ($new_parent_type) {
            $parent_type = $new_parent_type;
            $parent_entity = $new_parent_entity;
        } else {
            $parent_type = $input->getCmd('parent_type', 'com_content');
            $parent_entity = $input->getCmd('parent_entity', 'default');
        }
        $parent = $apm->getAttachmentsPlugin($parent_type);
        $parent_entity = $parent->getCanonicalEntityId($parent_entity);

        // Get the title of the article/parent
        $new_parent = $input->getBool('new_parent', false);
        $parent->new = $new_parent;
        if ($new_parent) {
            $attachment->parent_id = null;
            $parent->title = '';
        } else {
            $parent->title = $parent->getTitle($attachment->parent_id, $parent_entity);
        }

        // Check to make sure the user has permissions to edit the attachment
        if (!$parent->userMayEditAttachment($attachment)) {
            // ??? Add better error message
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR') . ' (ERR 139)', 403);
        }

        // Double-check to see if the URL changed
        $old_url = $input->getString('old_url');
        if (!$new_uri_type && $old_url && ($old_url != $attachment->url)) {
            $new_uri_type = 'url';
        }

        // If this is a URL, get settings
        $verify_url = false;
        $relative_url = false;
        if ($new_uri_type == 'url') {
            // See if we need to verify the URL (if applicable)
            if ($input->getWord('verify_url') == 'verify') {
                $verify_url = true;
            }
            // Allow relative URLs?
            if ($input->getWord('url_relative') == 'relative') {
                $relative_url = true;
            }
        }

        // Compute the update time
        $now = Factory::getDate();

        // Update create/modify info
        $attachment->modified_by = $user->get('id');
        $attachment->modified = $now->toSql();

        // Upload new file/url and create/update the attachment
        $msg = null;
        $msgType = 'message';
        if ($new_uri_type == 'file') {
            // Upload a new file
            $result = AttachmentsHelper::uploadFile($attachment, $parent, $attachment_id, 'update');
            if (is_object($result)) {
                $msg = $result->error_msg . ' (ERR 140)';
                $msgType = 'error';
            } else {
                $msg = $result;
            }
            // NOTE: store() is not needed if uploadFile() is called since it does it
        } elseif ($new_uri_type == 'url') {
            // Upload/add the new URL
            $result = AttachmentsHelper::addUrl(
                $attachment,
                $parent,
                $verify_url,
                $relative_url,
                $old_uri_type,
                $attachment_id
            );

            // NOTE: store() is not needed if addUrl() is called since it does it
            if (is_object($result)) {
                $msg = $result->error_msg . ' (ERR 141)';
                $msgType = 'error';
            } else {
                $msg = $result;
            }
        } else {
            // Extra handling for checkboxes for URLs
            if ($attachment->uri_type == 'url') {
                // Update the url_relative field
                $attachment->url_relative = $relative_url;
                $attachment->url_verify = $verify_url;
            }

            // Remove any extraneous fields
            if (isset($attachment->parent_entity_name)) {
                unset($attachment->parent_entity_name);
            }

            $app->triggerEvent('onContentBeforeSave', [
                'com_attachments.attachment',
                $attachment,
                false,
                $attachment->getProperties()
            ]);

            // Save the updated attachment info
            if (!$attachment->store()) {
                $errmsg = $attachment->getError() . ' (ERR 142)';
                throw new \Exception($errmsg, 500);
            }
        }

        $app->triggerEvent('onContentAfterSave', [
            'com_attachments.attachment',
            $attachment,
            false,
            $attachment->getProperties()
        ]);

        switch ($this->getTask()) {
            case 'apply':
                if (!$msg) {
                    $msg = Text::_('ATTACH_CHANGES_TO_ATTACHMENT_SAVED');
                }
                $link = 'index.php?option=com_attachments&task=attachment.edit&cid[]=' . (int)$attachment->id;
                break;

            case 'save':
            default:
                if (!$msg) {
                    $msg = Text::_('ATTACH_ATTACHMENT_UPDATED');
                }
                $link = 'index.php?option=com_attachments';
                break;
        }

        // If invoked from an iframe popup, close it and refresh the attachments list
        $from = $input->getWord('from');
        $known_froms = $parent->knownFroms();
        if (in_array($from, $known_froms)) {
            // If there has been a problem, alert the user and redisplay
            if ($msgType == 'error') {
                $errmsg = $msg;
                if (DIRECTORY_SEPARATOR == "\\") {
                    // Fix filename on Windows system so alert can display it
                    $errmsg = str_replace(DIRECTORY_SEPARATOR, "\\\\", $errmsg);
                }
                $errmsg = str_replace("'", "\'", $errmsg);
                $errmsg = str_replace("<br />", "\\n", $errmsg);
                echo "<script type=\"text/javascript\"> alert('$errmsg');  window.history.go(-1); </script>";
                exit();
            }

            // Can only refresh the old parent
            if ($parent_changed) {
                $parent_type = $old_parent_type;
                $parent_entity = $old_parent_entity;
                $parent_id = $old_parent_id;
            } else {
                $parent_id = (int)$attachment->parent_id;
            }

            // Close the iframe and refresh the attachments list in the parent window
            $uri = Uri::getInstance();
            $base_url = $uri->base(true);
            $lang = $input->getCmd('lang', '');
            AttachmentsJavascript::closeIframeRefreshAttachments(
                $base_url,
                $parent_type,
                $parent_entity,
                $parent_id,
                $lang,
                $from
            );
            exit();
        }

        $this->setRedirect($link, $msg, $msgType);
    }



    /**
     * Add the save/upload/update urls to the view
     *
     * @param &object &$view the view to add the urls to
     * @param string $save_type type of save ('file' or 'url')
     * @param int $parent_id id for the parent
     $ @param string $parent_type type of parent (eg, com_content)
     * @param int $attachment_id id for the attachment
     * @param string $from the from ($option) value
     */
    private function addViewUrls(
        &$view,
        $save_type,
        $parent_id,
        $parent_type,
        $attachment_id,
        $from
    ) {
        // Construct the url to save the form
        $url_base = "index.php?option=com_attachments";

        // $template = '&tmpl=component';
        $template = '';
        $add_task  = 'attachment.add';
        $edit_task = 'attachment.edit';
        // $idinfo = "&id=$attachment_id";
        $idinfo = "&cid[]=$attachment_id";
        $parentinfo = '';
        if ($parent_id) {
            $parentinfo = "&parent_id=$parent_id&parent_type=$parent_type";
        }

        $save_task = 'attachment.save';
        if ($save_type == 'upload') {
            $save_task = 'attachment.saveNew';
        }

        // Handle the main save URL
        $save_url = $url_base . "&task=" . $save_task . $template;
        if ($from == 'closeme') {
            // Keep track of what are supposed to do after saving
            $save_url .= "&from=closeme";
        }
        $view->save_url = Route::_($save_url);

        // Construct the URL to upload a URL instead of a file
        if ($save_type == 'upload') {
            $upload_file_url = $url_base . "&task=$add_task&uri=file" . $parentinfo . $template;
            $upload_url_url  = $url_base . "&task=$add_task&uri=url" . $parentinfo . $template;

            // Keep track of what are supposed to do after saving
            if ($from == 'closeme') {
                $upload_file_url .= "&from=closeme";
                $upload_url_url .= "&from=closeme";
            }

            // Add the URL
            $view->upload_file_url = Route::_($upload_file_url);
            $view->upload_url_url  = Route::_($upload_url_url);
        } elseif ($save_type == 'update') {
            $change_url = $url_base . "&task=$edit_task" . $idinfo;
            $change_file_url =  $change_url . "&amp;update=file" . $template;
            $change_url_url =  $change_url . "&amp;update=url" . $template;
            $normal_update_url =  $change_url . $template;

            // Keep track of what are supposed to do after saving
            if ($from == 'closeme') {
                $change_file_url .= "&from=closeme";
                $change_url_url .= "&from=closeme";
                $normal_update_url .= "&from=closeme";
            }

            // Add the URLs
            $view->change_file_url   = Route::_($change_file_url);
            $view->change_url_url    = Route::_($change_url_url);
            $view->normal_update_url = Route::_($normal_update_url);
        }
    }


    /**
     * Download an attachment
     */
    public function download()
    {
        // Get the attachment ID
        $id = Factory::getApplication()->getInput()->getInt('id');
        if (!is_numeric($id)) {
            $errmsg = Text::sprintf('ATTACH_ERROR_INVALID_ATTACHMENT_ID_N', $id) . ' (ERR 143)';
            throw new \Exception($errmsg, 500);
        }

        // NOTE: AttachmentsHelper::downloadAttachment($id) checks access permission
        AttachmentsHelper::downloadAttachment($id);
    }

    /**
     * Put up a dialog to double-check before deleting an attachment
     */
    public function deleteWarning()
    {
        // Access check.
        /** @var \Joomla\CMS\Application\CMSApplication $app */
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (
            $user === null or !( $user->authorise('core.delete', 'com_attachments') or
                $user->authorise('attachments.delete.own', 'com_attachments') )
        ) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR') . ' (ERR 144)', 403);
        }

        // Make sure we have a valid attachment ID
        $input = $app->getInput();
        $attachment_id = $input->getInt('id', null);
        if (is_numeric($attachment_id)) {
            $attachment_id = (int)$attachment_id;
        } else {
            $errmsg = Text::sprintf(
                'ATTACH_ERROR_CANNOT_DELETE_INVALID_ATTACHMENT_ID_N',
                $attachment_id
            ) . ' (ERR 145)';
            throw new \Exception($errmsg, 500);
        }

        // Load the attachment
        /** @var \JMCameron\Component\Attachments\Administrator\Model\AttachmentModel $model */
        $model      = $this->getModel();
        $attachment = $model->getTable();

        // Make sure the article ID is valid
        $attachment_id = $input->getInt('id');
        if (!$attachment->load($attachment_id)) {
            $errmsg = Text::sprintf('ATTACH_ERROR_CANNOT_DELETE_INVALID_ATTACHMENT_ID_N', $attachment_id) .
            ' (ERR 146)';
            throw new \Exception($errmsg, 500);
        }

        // Set up the view
        $document = $app->getDocument();
        $view = $this->getView(
            'Warning',
            $document->getType(),
            'Administrator',
            ['option' => $input->getCmd('option')]
        );
        $view->parent_id = $attachment_id;
        $view->from = $input->getWord('from');
        $view->tmpl = $input->getWord('tmpl');

        // Prepare for the query
        $view->warning_title = Text::_('ATTACH_WARNING');
        if ($attachment->uri_type == 'file') {
            $msg = "( {$attachment->filename} )";
        } else {
            $msg = "( {$attachment->url} )";
        }
        $view->warning_question = Text::_('ATTACH_REALLY_DELETE_ATTACHMENT') . '<br/>' . $msg;
        $view->action_button_label = Text::_('ATTACH_DELETE');

        $view->action_url = "index.php?option=com_attachments&amp;task=attachments.delete&amp;cid[]="
        . (int)$attachment_id;
        $view->action_url .= "&amp;from=" . $view->from;

        $view->display();
    }

    public function cancel($key = null)
    {
        $this->app->setUserState('com_attachments.edit.attachment.data', null);
        $this->setRedirect(Route::_('index.php?option=' . $this->option, false));
    }
}
