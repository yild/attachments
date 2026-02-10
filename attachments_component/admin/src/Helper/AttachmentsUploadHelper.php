<?php

/**
 * Attachments component
 *
 * @package Attachments
 * @subpackage Attachments_Component
 *
 * @copyright Copyright (C) 2007-2025 Jonathan M. Cameron, All Rights Reserved
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 * @link https://github.com/jmcameron/attachments
 * @author Jonathan M. Cameron
 */

namespace JMCameron\Component\Attachments\Administrator\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;

// phpcs:enable PSR1.Files.SideEffects

use Exception;
use Joomla\CMS\Router\Route;
use RuntimeException;
use stdClass;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Environment\Browser;
use Joomla\CMS\Factory;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;
use Joomla\String\StringHelper;
use JMCameron\Component\Attachments\Site\Helper\AttachmentsDefines;
use JMCameron\Component\Attachments\Site\Helper\AttachmentsJavascript;
use JMCameron\Plugin\AttachmentsPluginFramework\AttachmentsPluginManager;
use JMCameron\Component\Attachments\Site\Helper\AttachmentsFileTypes;

/**
 * A class for attachments upload functions
 *
 * @package Attachments
 *
 * @since   4.2.0
 */
class AttachmentsUploadHelper
{

    /**
     * Check the directory corresponding to this path.  If it is empty, delete it.
     * (Assume anything with a trailing DS or '/' is a directory)
     *
     * @access  public
     *
     * @param  string  $filename  path of the file to have its containing directory cleaned.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public static function cleanDirectory(string $filename)
    {
        // Assume anything with a trailing DS or '/' is a directory
        if (($filename[strlen($filename) - 1] == DIRECTORY_SEPARATOR) || ($filename[strlen($filename) - 1] == '/')) {
            if (!is_dir($filename)) {
                return;
            }

            $dirname = $filename;
        } else {
            // This might be a file or directory

            if (is_dir($filename)) {
                $dirname = $filename;
            } else {
                // Get the directory name
                $filenameInfo = pathinfo($filename);
                $dirname = $filenameInfo['dirname'] . '/';
            }
        }

        // If the directory does not exist, quitely ignore the request
        if (!is_dir($dirname)) {
            return;
        }

        /*
         * If the directory is the top-level attachments directory, ignore the request
         * (This can occur when upgrading pre-2.0 attachments (with prefixes) since
         * they were all saved in the top-level directory.)
         */
        $uploadDir = JPATH_SITE . '/' . AttachmentsDefines::$ATTACHMENTS_SUBDIR;
        $direndChars = DIRECTORY_SEPARATOR . '/';

        if (realpath(rtrim($uploadDir, $direndChars)) == realpath(rtrim($dirname, $direndChars))) {
            return;
        }

        // See how many files exist in the directory
        $files = Folder::files($dirname);

        // If there are no files left (or only the index.html file is left), delete the directory
        if ((count($files) == 0) || ((count($files) == 1) && ($files[0] == 'index.html'))) {
            Folder::delete($dirname);
        }
    }

    /**
     * Switch attachment from one parent to another
     *
     * @access  public
     *
     * @param   &object      $attachment       the attachment object
     * @param  int  $newParentId  the id for the new parent
     * @param  string|void  $newParentType  the new parent type (eg, 'com_content')
     * @param  string|void  $newParentEntity  the new parent entity (eg, 'category')
     *
     * @return  string  empty if successful, else an error message
     *
     * @throws  Exception
     *
     * @since   1.0.0
     */
    public static function switchParent(
        &$attachment,
        int $newParentId,
        string $newParentType = null,
        string $newParentEntity = null
    ): string {
        // Switch the parent as specified, renaming the file as necessary

        if ($attachment->uri_type == 'url') {
            // Do not need to do any file operations if this is a URL
            return '';
        }

        // Parent wasn't change
        if (($attachment->parent_id_old == $newParentId)
            && ($attachment->parent_type_old == $newParentType)
            && ($attachment->parent_entity_old == $newParentEntity)) {
            return '';
        }

        // Get the article/parent handler
        if ($newParentType) {
            $parentType = $newParentType;
            $parentEntity = $newParentEntity;
        } else {
            $parentType = $attachment->parent_type;
            $parentEntity = $attachment->parent_entity;
        }

        if (!PluginHelper::importPlugin('attachments')) {
            // Exit if the framework does not exist (eg, during uninstallation)
            return '';
        }

        $apm = AttachmentsPluginManager::getAttachmentsPluginManager();
        if (!$apm->attachmentsPluginInstalled($parentType)) {
            return Text::sprintf('ATTACH_ERROR_UNKNOWN_PARENT_TYPE_S', $parentType) . ' (ERR 45)';
        }

        $parent = $apm->getAttachmentsPlugin($parentType);

        // Set up the entity name for display
        $parentEntity = $parent->getCanonicalEntityName($parentEntity);
        $parentEntityName = Text::_('ATTACH_' . $parentEntity);

        // Get the component parameters
        $params = ComponentHelper::getParams('com_attachments');

        // Define where the attachments move to
        $uploadUrl = AttachmentsDefines::$ATTACHMENTS_SUBDIR;
        $uploadDir = JPATH_SITE . '/' . $uploadUrl;

        // Figure out the new system filename
        $newPath = $parent->getAttachmentPath($parentEntity, $newParentId, 0);
        $newFullpath = $uploadDir . '/' . $newPath;

        // Make sure the new directory exists
        if (!Folder::create($newFullpath)) {
            return Text::sprintf('ATTACH_ERROR_UNABLE_TO_CREATE_DIR_S', $newFullpath) . ' (ERR 46)';
        }

        // Construct the new filename and URL
        $oldFilenameSys = $attachment->filename_sys;
        $newFilenameSys = $newFullpath . $attachment->filename;
        $newUrl = StringHelper::str_ireplace(
            DIRECTORY_SEPARATOR,
            '/',
            $uploadUrl . '/' . $newPath . $attachment->filename
        );

        // Rename the file
        if (is_file($newFilenameSys)) {
            return Text::sprintf(
                'ATTACH_ERROR_CANNOT_SWITCH_PARENT_S_NEW_FILE_S_ALREADY_EXISTS',
                $parentEntityName,
                $attachment->filename
            );
        }

        if (!File::move($oldFilenameSys, $newFilenameSys)) {
            $newFilename = $newPath . $attachment->filename;

            return Text::sprintf(
                'ATTACH_ERROR_CANNOT_SWITCH_PARENT_S_RENAMING_FILE_S_FAILED',
                $parentEntityName,
                $newFilename
            );
        }

        self::writeEmptyIndexHtml($newFullpath);

        // Save the changes to the attachment record immediately
        $attachment->parent_id = $newParentId;
        $attachment->parent_entity = $parentEntity;
        $attachment->parentEntityName = $parentEntityName;
        $attachment->filename_sys = $newFilenameSys;
        $attachment->url = $newUrl;

        // Clean up after ourselves
        self::cleanDirectory($oldFilenameSys);

        return '';
    }

    /**
     * Truncate the filename if it is longer than the maxlen
     * Do this by deleting necessary at the end of the base filename (before the extensions)
     *
     * @access  protected
     *
     * @param  string  $rawFilename  the input filename
     * @param  int  $maxlen  the maximum allowed length (0 means no limit)
     *
     * @return  string  the truncated filename
     *
     * @since   1.0.0
     */
    protected static function truncateFilename(string $rawFilename, int $maxlen): string
    {
        // Do not truncate if $maxlen is 0 or no truncation is needed
        if (($maxlen == 0) || (strlen($rawFilename) <= $maxlen)) {
            return $rawFilename;
        }

        $filenameInfo = pathinfo($rawFilename);
        $basename = $filenameInfo['basename'];
        $filename = $filenameInfo['filename'];

        $extension = '';

        if ($basename != $filename) {
            $extension = $filenameInfo['extension'];
        }

        if (strlen($extension) > 0) {
            $maxlen = max($maxlen - (strlen($extension) + 2), 1);

            return substr($filename, 0, $maxlen) . '~.' . $extension;
        } else {
            $maxlen = max($maxlen - 1, 1);

            return substr($filename, 0, $maxlen) . '~';
        }
    }

    /**
     * Make sure this a valid image file
     *
     * @access  public
     *
     * @param  string  $filepath  the full path to the image file
     *
     * @return  bool  true if it is a valid image file
     *
     * @since   1.0.0
     */
    public static function isValidImageFile(string $filepath): bool
    {
        return getimagesize($filepath) !== false;
    }

    /**
     * Determine if a file is an image file
     *
     * Adapted from com_media
     *
     * @access  public
     *
     * @param  string  $filename  the filename to check
     *
     * @return  bool  true if it is an image file
     *
     * @since   1.0.0
     */
    public static function isImageFile(string $filename): bool
    {
        // Partly based on PHP getimagesize documentation for PHP 7.0+
        static $imageTypes = 'xcf|odg|gif|jpg|jpeg|png|bmp|psd|tiff|swc|iff|jpc|jp2|jpx|jb2|xbm|wbmp|ico|webp';

        return preg_match("/\.(?:$imageTypes)$/i", $filename);
    }

    /**
     * Make sure a file is not a double-extension exploit
     *   See:  https://www.acunetix.com/websitesecurity/upload-forms-threat/
     *
     * @access  public
     *
     * @param  string  $filename  the filename
     *
     * @return  bool  true if it is an exploit file
     *
     * @since   1.0.0
     */
    public static function isDoubleExtensionExploit(string $filename): bool
    {
        return preg_match("/\.php\.[a-z0-9]+$/i", $filename);
    }

    /**
     * Write an empty 'index.html' file in the specified directory to prevent snooping
     *
     * @access  public
     *
     * @param  string  $dir  full path of the directory needing an 'index.html' file
     *
     * @return  bool  true if the file was successfully written
     *
     * @since   1.0.0
     */
    public static function writeEmptyIndexHtml(string $dir): bool
    {
        $indexFname = $dir . '/index.html';

        if (is_file($indexFname)) {
            return true;
        }

        $contents = "<html><body><br /><h2 align=\"center\">Access denied.</h2></body></html>";
        File::write($indexFname, $contents);

        return is_file($indexFname);
    }

    /**
     * Set up the upload directory
     *
     * @access  public
     *
     * @param  string  $uploadDir  the directory to be set up
     * @param  bool  $secure  true if the directory should be set up for secure mode (with the necessary .htaccess file)
     *
     * @return  bool  true if successful
     *
     * @throws  Exception
     *
     * @since   1.0.0
     */
    public static function setupUploadDirectory(string $uploadDir, bool $secure): bool
    {
        $subdirOk = false;

        // Do not allow the main site directory to be set up as the upload directory
        $direndChars = DIRECTORY_SEPARATOR . '/';

        if ((realpath(rtrim($uploadDir, $direndChars)) == realpath(JPATH_SITE))
            || (realpath(rtrim($uploadDir, $direndChars)) == realpath(JPATH_ADMINISTRATOR))) {
            $errMsg = Text::sprintf('ATTACH_ERROR_UNABLE_TO_SETUP_UPLOAD_DIR_S', $uploadDir) . ' (ERR 29)';
            Factory::getApplication()->enqueueMessage($errMsg, 'error');
        }

        // Create the subdirectory (if necessary)
        if (is_dir($uploadDir)) {
            $subdirOk = true;
        } else {
            if (Folder::create($uploadDir)) {
                // ??? Change to 2775 if files are owned by you but webserver runs as group
                // ??? (Should the permission be an option?)
                chmod($uploadDir, 0775);
                $subdirOk = true;
            }
        }

        if (!$subdirOk || !is_dir($uploadDir)) {
            $errMsg = Text::sprintf('ATTACH_ERROR_UNABLE_TO_SETUP_UPLOAD_DIR_S', $uploadDir) . ' (ERR 30)';
            Factory::getApplication()->enqueueMessage($errMsg, 'error');
        }

        // Add a simple index.html file to the upload directory to prevent browsing
        if (!self::writeEmptyIndexHtml($uploadDir)) {
            $errMsg = Text::sprintf('ATTACH_ERROR_ADDING_INDEX_HTML_IN_S', $uploadDir) . ' (ERR 31)';
            Factory::getApplication()->enqueueMessage($errMsg, 'error');
        }

        // If this is secure, create the .htindex file, if necessary
        $htaFname = $uploadDir . '/.htaccess';

        if ($secure) {
            $htaOk = false;

            $line = "order deny,allow\ndeny from all\n";
            File::write($htaFname, $line);

            if (is_file($htaFname)) {
                $htaOk = true;
            }

            if (!$htaOk) {
                $errMsg = Text::sprintf('ATTACH_ERROR_ADDING_HTACCESS_S', $uploadDir) . ' (ERR 32)';
                Factory::getApplication()->enqueueMessage($errMsg, 'error');
            }
        } else {
            if (is_file($htaFname)) {
                // If the htaccess file exists, delete it so normal access can occur
                File::delete($htaFname);
            }
        }

        return true;
    }

    /**
     * Upload the file
     *
     * @access  public
     *
     * @param   &object   $partialAttachment  the partially constructed attachment object
     * @param   &object   $parent        an Attachments plugin parent object with partial parent info including:
     *                                  $parent->new : True if the parent has not been created yet
     *                                      (like adding attachments to an article before it has been saved)
     *                                  $parent->title : Title/name of the parent object
     * @param  array  $files  uploaded files info array
     * @param  bool  $new  'upload' or 'update'
     *
     * @return  string  a message indicating succes or failure
     *
     * @throws  Exception
     *
     * @since   1.0.0
     *
     * NOTE: The caller should set up all the parent info in the record before calling this
     *         (see $parent->* below for necessary items)
     */
    public static function uploadFile(&$partialAttachment, &$parent, array $files, bool $new = false): string
    {
        $file = $files['filename'];

        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $db = Factory::getContainer()->get('DatabaseDriver');

        // Figure out if the user may publish this attachment
        $mayPublish = $parent->userMayChangeAttachmentState(
            (int)$partialAttachment->parent_id,
            $partialAttachment->parent_entity,
            (int)$partialAttachment->created_by
        );

        // Get the component parameters
        $params = ComponentHelper::getParams('com_attachments');

        // Make sure the attachments directory exists
        $uploadDir = JPATH_SITE . '/' . AttachmentsDefines::$ATTACHMENTS_SUBDIR;
        $secure = $params->get('secure', false);

        if (!self::setupUploadDirectory($uploadDir, $secure)) {
            $errMsg = Text::sprintf('ATTACH_ERROR_UNABLE_TO_SETUP_UPLOAD_DIR_S', $uploadDir) . ' (ERR 33)';
            Factory::getApplication()->enqueueMessage($errMsg, 'error');

            return $errMsg;
        }

        // If we are updating, note the name of the old filename
        $oldFilename = null;
        $oldFilenameSys = null;

        if ($partialAttachment->uri_type) {
            $oldFilename = $partialAttachment->filename;
            $oldFilenameSys = $partialAttachment->filename_sys;
        }

        /**
         * Get the new filename
         * (Note: The following replacement is necessary to allow single quotes in filenames to work correctly.)
         * Trim of any trailing period (to avoid exploits)
         */
        $filename = rtrim(str_ireplace("\'", "'", $file['name']), '.');
        $ftype = $file['type'];

        // Check the file size
        $maxUploadSize = (int)ini_get('upload_max_filesize');
        $maxAttachmentSize = (int)$params->get('max_attachment_size', 0);

        if ($maxAttachmentSize == 0) {
            $maxAttachmentSize = $maxUploadSize;
        }

        $maxSize = min($maxUploadSize, $maxAttachmentSize);
        $fileSize = filesize($file['tmp_name']) / 1048576.0;

        if ($fileSize > $maxSize) {
            return Text::sprintf(
                'ATTACH_ERROR_FILE_S_TOO_BIG_N_N_N',
                $filename,
                $fileSize,
                $maxAttachmentSize,
                $maxUploadSize
            );
        }

        // Get the maximum allowed filename length (for the filename display)
        $maxFilenameLength = (int)$params->get('max_filename_length', 0);

        if ($maxFilenameLength == 0) {
            $maxFilenameLength = AttachmentsDefines::$MAXIMUM_FILENAME_LENGTH;
        } else {
            $maxFilenameLength = min($maxFilenameLength, AttachmentsDefines::$MAXIMUM_FILENAME_LENGTH);
        }

        // Truncate the filename, if necessary and alert the user
        if (strlen($filename) > $maxFilenameLength) {
            $filename = self::truncateFilename($filename, $maxFilenameLength);
            $msg = Text::_('ATTACH_WARNING_FILENAME_TRUNCATED');

            if ($app->isClient('administrator')) {
                $lang = $app->getLanguage();

                if ($lang->isRTL()) {
                    $msg = "'$filename' " . $msg;
                } else {
                    $msg = $msg . " '$filename'";
                }

                $app->enqueueMessage($msg, 'warning');
            } else {
                $msg .= "\\n \'$filename\'";

                echo AttachmentsJavascript::alertMsg($msg);
            }
        }

        // Check the filename for bad characters
        $badChar = '';
        $badChars = false;
        $forbiddenChars = $params->get('forbidden_filename_characters', '#=?%&');

        for ($i = 0; $i < strlen($forbiddenChars); $i++) {
            $char = $forbiddenChars[$i];

            if (strpos($filename, $char) !== false) {
                $badChar = $char;
                $badChars = true;
                break;
            }
        }

        $badFilename = false;

        /*
         * This was tested before in AttachmentModel::validateUploadFile.
         * // Check for double-extension exploit and other security anomalies
         * $badFilename = !InputFilter::isSafeFile($files);
         */

        // Set up the entity name for display
        $parentEntity = $parent->getCanonicalEntityName($partialAttachment->parent_entity);
        $parentEntityName = Text::_('ATTACH_' . $parentEntity);

        // A little formatting
        $msgbreak = '<br />';

        $from = $app->input->getWord('from');

        // Make sure a file was successfully uploaded
        if ((($file['size'] == 0) && ($file['tmp_name'] == '')) || $badChars || $badFilename) {
            // Guess the type of error
            if ($badChars) {
                $errMsg = Text::sprintf('ATTACH_ERROR_BAD_CHARACTER_S_IN_FILENAME_S', $badChar, $filename);
            } elseif ($badFilename) {
                $format = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $errMsg = Text::_('ATTACH_ERROR_ILLEGAL_FILE_EXTENSION') . " .php.$format";
            } elseif ($filename == '') {
                $errMsg = Text::sprintf('ATTACH_ERROR_UPLOADING_FILE_S', $filename);
                $errMsg .= $msgbreak . ' (' . Text::_('ATTACH_YOU_MUST_SELECT_A_FILE_TO_UPLOAD') . ')';
            } else {
                $errMsg = Text::sprintf('ATTACH_ERROR_UPLOADING_FILE_S', $filename);
                $errMsg .= $msgbreak . '(' . Text::_('ATTACH_ERROR_MAY_BE_LARGER_THAN_LIMIT') . ' ';
                $errMsg .= get_cfg_var('upload_max_filesize') . ')';
            }

            if ($errMsg != '') {
                if ($app->isClient('administrator')) {
                    return $errMsg;
                }
            }
        }

        // Make sure the file type is okay (respect restrictions imposed by media manager)
        $cmparams = ComponentHelper::getParams('com_media');

        // Check to make sure the extension is allowed
        $restrictUploadsExtensions = explode(',', $cmparams->get('restrict_uploads_extensions'));
        $imageExtensions = explode(',', $cmparams->get('image_extensions'));
        $audioExtensions = explode(',', $cmparams->get('audio_extensions'));
        $videoExtensions = explode(',', $cmparams->get('video_extensions'));
        $docExtensions = explode(',', $cmparams->get('doc_extensions'));
        $ignoreExtensions = explode(',', $cmparams->get('ignore_extensions'));

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($extension, $restrictUploadsExtensions) && !in_array($extension, $ignoreExtensions)) {
            $errMsg = Text::sprintf('ATTACH_ERROR_UPLOADING_FILE_S', $filename);
            $errMsg .= $msgbreak . Text::_('ATTACH_ERROR_ILLEGAL_FILE_EXTENSION') . " $extension";

            if ($user->authorise('core.admin')) {
                $errMsg .= $msgbreak . Text::_('ATTACH_ERROR_CHANGE_IN_MEDIA_MANAGER');
            }

            return $errMsg;
        }

        // Check to make sure the mime type is okay
        if ($cmparams->get('restrict_uploads', true)) {
            if ($cmparams->get('check_mime', true)) {
                $allowedMime = explode(',', $cmparams->get('upload_mime'));
                $illegalMime = explode(',', $cmparams->get('upload_mime_illegal'));

                if (strlen($ftype) && !in_array($ftype, $allowedMime) && in_array($ftype, $illegalMime)) {
                    $errMsg = Text::sprintf('ATTACH_ERROR_UPLOADING_FILE_S', $filename);
                    $errMsg .= $msgbreak . Text::_('ATTACH_ERROR_ILLEGAL_FILE_MIME_TYPE') . " $ftype";

                    if ($user->authorise('core.admin')) {
                        $errMsg .= $msgbreak . Text::_('ATTACH_ERROR_CHANGE_IN_MEDIA_MANAGER');
                    }

                    return $errMsg;
                }
            }
        }

        // If it is an image file, make sure it is a valid image file (and not some kind of exploit)
        if (self::isImageFile($filename)) {
            if (!self::isValidImageFile($file['tmp_name'])) {
                if (!in_array($extension, $imageExtensions)) {
                    $errMsg = Text::sprintf('ATTACH_ERROR_UPLOADING_FILE_S', $filename);
                    $errMsg .= "<br />" . Text::_('ATTACH_ERROR_ILLEGAL_FILE_CORRUPTED_IMAGE_FILE_S');

                    return $errMsg;
                }
            }
        }

        // Handle PDF mime types
        if ($extension == 'pdf') {
            if (in_array($ftype, AttachmentsFileTypes::$attachments_pdf_mime_types)) {
                $ftype = 'application/pdf';
            }
        }

        // Define where the attachments go
        $uploadUrl = AttachmentsDefines::$ATTACHMENTS_SUBDIR;
        $uploadDir = JPATH_SITE . '/' . $uploadUrl;

        // Figure out the system filename
        $path = $parent->getAttachmentPath($partialAttachment->parent_entity, $partialAttachment->parent_id, 0);
        $fullpath = $uploadDir . '/' . $path;

        // Make sure the directory exists
        if (!is_file($fullpath)) {
            if (!Folder::create($fullpath)) {
                return Text::sprintf('ATTACH_ERROR_UNABLE_TO_SETUP_UPLOAD_DIR_S', $uploadDir) . ' (ERR 34)';
            }

            self::writeEmptyIndexHtml($fullpath);
        }

        // Get ready to save the file
        $filenameSys = $fullpath . $filename;

        $url = $uploadUrl . '/' . $path . $filename;

        $baseUrl = Uri::getInstance()->base();

        // If we are on windows, fix the filename and URL
        if (DIRECTORY_SEPARATOR != '/') {
            $filenameSys = str_replace('/', DIRECTORY_SEPARATOR, $filenameSys);
            $url = str_replace(DIRECTORY_SEPARATOR, '/', $url);
        }

        // Check on length of filenameSys
        if (strlen($filenameSys) > AttachmentsDefines::$MAXIMUM_FILENAME_SYS_LENGTH) {
            return Text::sprintf(
                    'ATTACH_ERROR_FILEPATH_TOO_LONG_N_N_S',
                    strlen($filenameSys),
                    AttachmentsDefines::$MAXIMUM_FILENAME_SYS_LENGTH,
                    $filename
                ) . '(ERR 35)';
        }

        // Make sure the system filename doesn't already exist
        $duplicateFilename = false;

        if ($new && is_file($filenameSys)) {
            // Cannot overwrite an existing file when creating a new attachment!
            $duplicateFilename = true;
        }

        if (!$new && is_file($filenameSys)) {
            // If updating, we may replace the existing file but may not overwrite any other existing file
            $query = $db
                ->getQuery(true)
                ->select($db->qn('id'))
                ->from($db->qn('#__attachments'))
                ->where([$db->qn('filename_sys') . ' = :filename_sys', $db->qn('id') . ' != :id'])
                ->bind(':filename_sys', $filenameSys, ParameterType::STRING)
                ->bind(':id', $partialAttachment->id, ParameterType::INTEGER);

            try {
                $duplicateFilename = $db
                        ->setQuery($query, 0, 1)
                        ->loadResult() > 0;
            } catch (RuntimeException $e) {
            }
        }

        // Handle duplicate filename error
        if ($duplicateFilename) {
            $errMsg = Text::sprintf('ATTACH_ERROR_FILE_S_ALREADY_ON_SERVER', $filename);

            if ($app->isClient('administrator')) {
                return $errMsg;
            }
        }

        // Create a display filename, if needed (for long filenames)
        if (($maxFilenameLength > 0)
            && (strlen($partialAttachment->display_name) == 0)
            && (strlen($filename) > $maxFilenameLength)) {
            $partialAttachment->display_name = self::truncateFilename($filename, $maxFilenameLength);
        }

        // Copy the info about the uploaded file into the new record
        $partialAttachment->uri_type = 'file';
        $partialAttachment->filename = $filename;
        $partialAttachment->filename_sys = $filenameSys;
        $partialAttachment->url = $url;
        $partialAttachment->file_type = $ftype;
        $partialAttachment->file_size = $file['size'];

        // If the user is not authorised to change the state (eg, publish/unpublish),
        // ignore the form data and make sure the publish state is set correctly.
        if (!$mayPublish) {
            if ($new) {
                // Use the default publish state (ignore form info)
                $params = ComponentHelper::getParams('com_attachments');
                $partialAttachment->state = $params->get('publish_default', false);
            } else {
                // Restore the old state (ignore form info)
                $query = $db
                    ->getQuery(true)
                    ->select($db->qn('state'))
                    ->from($db->qn('#__attachments'))
                    ->where($db->qn('id') . ' = :id')
                    ->bind(':id', $partialAttachment->id, ParameterType::INTEGER);

                try {
                    $oldState = $db
                        ->setQuery($query, 0, 1)
                        ->loadResult();
                } catch (RuntimeException $e) {
                    $errMsg = $db->stderr() . ' (ERR 36)';
                    Factory::getApplication()->enqueueMessage($errMsg, 'error');

                    return $errMsg;
                }

                $partialAttachment->state = $oldState;
            }
        }

        // Add the icon file type if not set by control
        if (!$partialAttachment->icon_filename) {
            $partialAttachment->icon_filename = AttachmentsFileTypes::iconFilename($filename, $ftype);
        }

        // Save the updated attachment
        if (!$partialAttachment->store()) {
            return Text::_('ATTACH_ERROR_SAVING_FILE_ATTACHMENT_RECORD') . $partialAttachment->getError() . ' (ERR 37)';
        }

        // Move the file
        $msg = "";

        $useStreams = false;
        $allowUnsafe = $params->get('test_is_safe_file');
        $uploadedOk = File::upload($file['tmp_name'], $filenameSys, $useStreams, $allowUnsafe);

        if ($uploadedOk) {
            $fileSize = (int)($partialAttachment->file_size / 1024.0);
            $fileSizeStr = Text::sprintf('ATTACH_S_KB', $fileSize);

            chmod($filenameSys, 0644);

            // ??? The following items need to be updated for RTL
            if (!$new) {
                $msg = Text::_('ATTACH_UPDATED_ATTACHMENT') . ' ' . $filename . ' (' . $fileSizeStr . ')!';
            } else {
                $msg = Text::_('ATTACH_UPLOADED_ATTACHMENT') . ' ' . $filename . ' (' . $fileSizeStr . ')!';
            }
        } else {
            $query = $db
                ->getQuery(true)
                ->delete($db->qn('#__attachments'))
                ->where($db->qn('id') . ' = :id')
                ->bind(':id', $partialAttachment->id, ParameterType::INTEGER);

            try {
                $db
                    ->setQuery($query)
                    ->execute();
            } catch (RuntimeException $e) {
                $errMsg = $db->stderr() . ' (ERR 38)';
                Factory::getApplication()->enqueueMessage($errMsg, 'error');

                return $errMsg;
            }

            $msg = Text::_('ATTACH_ERROR_MOVING_FILE') . " {$file['tmp_name']} -> $filenameSys)";
        }

        // If we are updating, we may need to delete the old file
        if (!$new) {
            if (($filenameSys != $oldFilenameSys) && is_file($oldFilenameSys)) {
                File::delete($oldFilenameSys);
                self::cleanDirectory($oldFilenameSys);
            }
        }

        return '';
    }

    /**
     * Download an attachment (in secure mode)
     *
     * @access  public
     *
     * @param  int  $id  the attachment id
     *
     * @return  void
     *
     * @throws  Exception
     *
     * @since   1.0.0
     */
    public static function downloadAttachment(int $id): void
    {
        $baseUrl = Uri::getInstance()->base();

        $app = Factory::getApplication();

        if ($app->isClient('administrator')) {
            $model = $app->bootComponent('com_attachments')->getMVCFactory()
                ->createModel('Attachment', 'Administrator', ['ignore_request' => true]);
        } else {
            $model = $app->bootComponent('com_attachments')->getMVCFactory()
                ->createModel('Attachment', 'Site', ['ignore_request' => true]);
        }

        $attachment = $model->getItem($id);

        if (!$attachment) {
            $errMsg = Text::sprintf('ATTACH_ERROR_INVALID_ATTACHMENT_ID_N', $id) . ' (ERR 41)';
            $app->enqueueMessage($errMsg, 'error');
        }

        $parentId = $attachment->parent_id;
        $parentType = $attachment->parent_type;
        $parentEntity = $attachment->parent_entity;

        // Get the article/parent handler
        PluginHelper::importPlugin('attachments');
        $apm = AttachmentsPluginManager::getAttachmentsPluginManager();

        if (!$apm->attachmentsPluginInstalled($parentType)) {
            $errMsg = Text::sprintf('ATTACH_ERROR_UNKNOWN_PARENT_TYPE_S', $parentType) . ' (ERR 42)';
            $app->enqueueMessage($errMsg, 'error');
        }

        $parent = $apm->getAttachmentsPlugin($parentType);

        // Get the component parameters
        $params = ComponentHelper::getParams('com_attachments');

        // Make sure that the user can access the attachment
        if (!$parent->userMayAccessAttachment($attachment)) {
            // If not logged in, warn them to log in
            $user = $app->getIdentity();

            if ($user->get('username') == '') {
                $guestLevels = $params->get('show_guest_access_levels', ['1']);

                if (in_array($attachment->access, $guestLevels)) {
                    // Construct the login request with return URL
                    $return = $app->getUserState('com_attachments.current_url', '');
                    $redirectTo = Route::_(
                        $baseUrl . 'index.php?option=com_attachments&task=attachment.requestLogin' . $return
                    );
                    $app->redirect($redirectTo);
                }
            }

            // Otherwise, just error out
            $errMsg = Text::_('ATTACH_ERROR_NO_PERMISSION_TO_DOWNLOAD') . ' (ERR 43)';
            $app->enqueueMessage($errMsg, 'error');
        }

        // Get the other info about the attachment
        $downloadMode = $params->get('download_mode', 'attachment');
        $contentType = $attachment->file_type;

        if ($attachment->uri_type == 'file') {
            $filename = $attachment->filename;
            $filenameSys = $attachment->filename_sys;

            // Make sure the file exists
            if (!is_file($filenameSys)) {
                $errMsg = Text::sprintf('ATTACH_ERROR_FILE_S_NOT_FOUND_ON_SERVER', $filename) . ' (ERR 44)';
                $app->enqueueMessage($errMsg, 'error');
            }

            $fileSize = filesize($filenameSys);

            // Construct the downloaded filename
            $filenameInfo = pathinfo($filename);
            $extension = "." . $filenameInfo['extension'];
            $basename = basename($filename, $extension);
            /**
             * Modify the following line insert a string into
             * the filename of the downloaded file, for example:
             * $modFilename = $basename . "(yoursite)" . $extension;
             */
            $modFilename = $basename . $extension;

            // No need to update counter when in backend.
            if (!$app->isClient('administrator')) {
                $model->incrementDownloadCount();
            }

            // Begin writing headers
            // Clear any previously written headers in the output buffer
            ob_clean();

            // Handle MSIE differently...
            $browser = Browser::getInstance();
            $browserType = $browser->getBrowser();
            $browserVersion = $browser->getMajor();

            // Handle older versions of MS Internet Explorer
            if (($browserType == 'msie') && ($browserVersion <= 8)) {
                // Ensure UTF8 characters in filename are encoded correctly in IE
                $modFilename = rawurlencode($modFilename);

                // Tweak the headers for MSIE
                header('Pragma: private');
                header('Cache-control: private, must-revalidate');
            } else {
                header('Cache-Control: private, max-age=0, must-revalidate, no-store');
            }

            header("Content-Length: " . $fileSize);

            // Force the download
            if ($downloadMode == 'attachment') {
                // Attachment
                header("Content-Disposition: attachment; filename=\"$modFilename\"");
            } else {
                // Inline
                header("Content-Disposition: inline; filename=\"$modFilename\"");
            }

            header('Content-Transfer-Encoding: binary');
            header("Content-Type: $contentType");

            // If x-sendfile is available, use it
            $usingSsl = strtolower(substr($baseUrl, 0, 5)) == 'https';

            if (!$usingSsl && function_exists('apache_get_modules') && in_array(
                    'mod_xsendfile',
                    apache_get_modules()
                )) {
                header("X-Sendfile: $filenameSys");
            } else {
                if ($fileSize <= 1048576) {
                    // If the file size is one MB or less, use readfile
                    // ??? header("Content-Length: ".$fileSize);
                    @readfile($filenameSys);
                } else {
                    // Send it in 8K chunks
                    set_time_limit(0);
                    $file = @fopen($filenameSys, "rb");

                    while (!feof($file) && (connection_status() == 0)) {
                        print(@fread($file, 8 * 1024));
                        ob_flush();
                        flush();
                    }
                }
            }

            exit;
        } else {
            if ($attachment->uri_type == 'url') {
                // Note the download
                $model->incrementDownloadCount();

                // Forward to the URL
                // Clear any previously written headers in the output buffer
                ob_clean();
                header("Location: $attachment->url");
            }
        }
    }

    /**
     * Parse the url into parts
     *
     * @access  private
     *
     * @param  string   &$rawUrl  the raw url to parse
     * @param  bool     $relativeUrl  allow relative URLs
     *
     * @return  object  an object (if successful) with the parts as attributes (or an error string in case of error)
     *
     * @since   1.0.0
     */
    private static function parseUrl(&$rawUrl, bool $relativeUrl): object
    {
        // Set up the return object
        $result = new stdClass();
        $result->error = false;
        $result->relative = $relativeUrl;

        // Handle relative URLs
        $url = $rawUrl;

        if ($relativeUrl) {
            $uri = Uri::getInstance()->base(true);
            $url = $uri . "/" . $rawUrl;
        }

        // Thanks to https://www.roscripts.com/PHP_regular_expressions_examples-136.html
        // For parts of the URL regular expression here
        if (preg_match(
            '^(?P<protocol>\b[A-Z]+\b://)?'
            . '(?P<domain>[-A-Z0-9\.]+)?'
            . ':?(?P<port>[0-9]*)'
            . '(?P<path>/[-A-Z0-9+&@#/%=~_|!:,.;]*)'
            . '?(?P<parameters>\?[-A-Z0-9+&@#/%=~_|!:,.;]*)?^i',
            $url,
            $match
        )) {
            // Get the protocol (if any)
            $protocol = '';

            if (isset($match['protocol']) && $match['protocol']) {
                $protocol = StringHelper::rtrim($match['protocol'], '/:');
            }

            // Get the domain (if any)
            $domain = '';

            if (isset($match['domain']) && $match['domain']) {
                $domain = $match['domain'];
            }

            // Figure out the port
            $port = null;

            if ($protocol == 'http') {
                $port = 80;
            } elseif ($protocol == 'https') {
                $port = 443;
            } elseif ($protocol == 'ftp') {
                $port = 21;
            } elseif ($protocol == '') {
                $port = 80;
            } else {
                // Unrecognized protocol
                $result->error = true;
                $result->errorCode = 'url_unknown_protocol';
                $result->errorMsg = Text::sprintf('ATTACH_ERROR_UNKNOWN_PROTCOL_S_IN_URL_S', $protocol, $rawUrl);

                return $result;
            }

            // Override the port if specified
            if (isset($match['port']) && $match['port']) {
                $port = (int)$match['port'];
            }

            // Default to HTTP if protocol/port is missing
            if (!$port) {
                $port = 80;
            }

            // Get the path and reconstruct the full path
            if (isset($match['path']) && $match['path']) {
                $path = $match['path'];
            } else {
                $path = '/';
            }

            // Get the parameters (if any)
            if (isset($match['parameters']) && $match['parameters']) {
                $parameters = $match['parameters'];
            } else {
                $parameters = '';
            }

            // Handle relative URLs (or missing info)
            if (!$relativeUrl) {
                // If it is not a relative URL, make sure we have a protocl and domain
                if ($protocol == '') {
                    $protocol = 'http';
                }

                if ($domain == '') {
                    // Reject bad url syntax
                    $result->error = true;
                    $result->errorCode = 'url_no_domain';
                    $result->errorMsg = Text::sprintf('ATTACH_ERROR_IN_URL_SYNTAX_S', $rawUrl);
                }
            }

            // Save the information
            $result->protocol = $protocol;
            $result->domain = $domain;
            $result->port = $port;
            $result->path = str_replace('//', '/', $path);
            $result->params = $parameters;
            $result->url = str_replace('//', '/', $path . $result->params);
        } else {
            // Reject bad url syntax
            $result->error = true;
            $result->errorCode = 'url_bad_syntax';
            $result->errorMsg = Text::sprintf('ATTACH_ERROR_IN_URL_SYNTAX_S', $rawUrl);
        }

        return $result;
    }

    /**
     * Get the info about this URL
     *
     * @access  public
     *
     * @param  string  $rawUrl  the raw url to parse
     * @param   &object  $attachment   the attachment object
     * @param  bool  $verify  whether the existance of the URL should be checked
     * @param  bool  $relativeUrl  allow relative URLs
     *
     * @return  bool|object  true if the URL is okay, or an error object if not
     *
     * @throws  Exception
     *
     * @since   1.0.0
     */
    public static function getUrlInfo(string $rawUrl, &$attachment, bool $verify, bool $relativeUrl)
    {
        /*
            Check the URL for existence
            Get 'size' (null if the there were errors accessing the link,
                or 0 if the URL loaded but had None/Null/0 for length
            Get 'file_type'
            Get 'filename' (for display)
        */
        $u = self::parseUrl($rawUrl, $relativeUrl);

        // Deal with parsing errors
        if ($u->error) {
            return $u;
        }

        // Set up defaults for what we want to know
        $filename = basename($u->path);
        $fileSize = 0;
        $mimeType = '';
        $found = false;

        // Set the defaults
        $attachment->filename = StringHelper::trim($filename);
        $attachment->file_size = $fileSize;
        $attachment->url_valid = false;

        // Get parameters
        $params = ComponentHelper::getParams('com_attachments');
        $overlay = $params->get('superimpose_url_link_icons', true);

        // Get the timeout
        $timeout = $params->get('link_check_timeout', 10);

        if (is_numeric($timeout)) {
            $timeout = (int)$timeout;
        } else {
            $timeout = 10;
        }

        // Check if fsockopen function is enabled
        if (!function_exists('fsockopen')) {
            return false;
        }

        // Check the URL to see if it is valid
        $errstr = null;
        $fp = false;

        $app = Factory::getApplication();

        if ($timeout > 0) {
            // Set up error handler in case it times out or some other error occurs

            // For PHP +7.2 create_function rise deprecated error
            if (version_compare(phpversion(), '7.2', 'ge')) {
                set_error_handler(
                    function ($severity, $message, $file, $line) {
                        throw new Exception("fsockopen error");
                    },
                    E_ALL
                );
            } else {
                set_error_handler(
                    create_function('$severity, $message, $file, $line', 'throw new \Exception("fsockopen error");'),
                    E_ALL
                );
            }

            // Https require diferent approach
            $protocol = "";

            if ($u->port == 443) {
                $protocol = "ssl://";
            }

            try {
                $fp = fsockopen($protocol . $u->domain, $u->port, $errno, $errstr, $timeout);
                restore_error_handler();
            } catch (Exception $e) {
                restore_error_handler();

                if ($verify) {
                    $u->error = true;
                    $u->errorCode = 'url_check_exception';
                    $u->errorMsg = $e->getMessage();
                }
            }

            if ($u->error) {
                $errorMsg = Text::sprintf('ATTACH_ERROR_CHECKING_URL_S', $rawUrl);

                if ($app->isClient('administrator')) {
                    $result = new stdClass();
                    $result->error = true;
                    $result->errorMsg = $errorMsg;

                    return $result;
                }

                $u->errorMsg = $errorMsg;

                return $u;
            }
        }

        // Check the URL to get the size, etc
        if ($fp) {
            $request
                = "HEAD $u->url HTTP/1.1\r\nHOST: $u->domain\r\n"
                . "Connection: close\r\n"
                . "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.6; rv:8.0.1) Gecko/20100101 Firefox/8.0.1\r\n"
                . "Content-Type: application/x-www-form-urlencoded\r\n\r\n";

            fputs($fp, $request);

            while (!feof($fp)) {
                $httpResponse = fgets($fp, 128);

                // Check to see if it was found
                if (preg_match("|^HTTP/1\.\d [0-9]+ ([^$]+)$|m", $httpResponse, $match)) {
                    if (trim($match[1]) == 'OK') {
                        $found = true;
                    }
                }

                // Check for length
                if (preg_match("/Content\-Length: (\d+)/i", $httpResponse, $match)) {
                    $fileSize = (int)$match[1];
                }

                // Check for content type
                if (preg_match("/Content\-Type: ([^;$]+)/i", $httpResponse, $match)) {
                    $mimeType = trim($match[1]);
                }
            }

            fclose($fp);

            // Return error if it was not found (timed out, etc.)
            if (!$found && $verify) {
                $u->error = true;
                $u->errorCode = 'url_not_found';
                $u->errorMsg = Text::sprintf('ATTACH_ERROR_COULD_NOT_ACCESS_URL_S', $rawUrl);

                return $u;
            }
        } else {
            if ($verify && $timeout > 0) {
                // Error connecting
                $u->error = true;
                $u->errorCode = 'url_error_connecting';
                $errorMsg = Text::sprintf('ATTACH_ERROR_CONNECTING_TO_URL_S', $rawUrl) . "<br /> (" . $errstr . ")";
                $u->errorMsg = $errorMsg;

                return $u;
            }

            if ($timeout == 0) {
                // Pretend it was found
                $found = true;

                if ($overlay) {
                    $mimeType = 'link/generic';
                } else {
                    $mimeType = 'link/unknown';
                }
            }
        }

        // Update the record
        $attachment->filename = StringHelper::trim($filename);
        $attachment->file_size = $fileSize;
        $attachment->url_valid = (int)$found;

        // Deal with the file type
        if (!$mimeType) {
            $mimeType = AttachmentsFileTypes::mimeType($filename);
        }

        if ($mimeType) {
            $attachment->file_type = StringHelper::trim($mimeType);
        } else {
            if ($overlay) {
                $mimeType = 'link/generic';
                $attachment->file_type = 'link/generic';
            } else {
                $mimeType = 'link/unknown';
                $attachment->file_type = 'link/unknown';
            }
        }

        // See if we can figure out the icon
        $iconFilename = AttachmentsFileTypes::iconFilename($filename, $mimeType);

        if ($iconFilename) {
            $attachment->icon_filename = $iconFilename;
        } else {
            if ($mimeType == 'link/unknown') {
                $attachment->icon_filename = 'link.gif';
            } elseif ($mimeType == 'link/broken') {
                $attachment->icon_filename = 'link_broken.gif';
            } else {
                $attachment->icon_filename = 'link.gif';
            }
        }

        return true;
    }

}
