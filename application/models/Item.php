<?php 
/**
 * @version $Id$
 * @copyright Center for History and New Media, 2007-2008
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package Omeka
 **/

require_once 'Collection.php';
require_once 'ItemType.php';
require_once 'User.php';
require_once 'File.php';
require_once 'Tag.php';
require_once 'Taggable.php';
require_once 'Taggings.php';
require_once 'Element.php';
require_once 'Relatable.php';
require_once 'ItemTable.php';
require_once 'ItemPermissions.php';    
require_once 'ElementText.php';
require_once 'PublicFeatured.php';
/**
 * @package Omeka
 * @subpackage Models
 * @author CHNM
 * @copyright Center for History and New Media, 2007-2008
 **/
class Item extends Omeka_Record
{        
    public $item_type_id;
    public $collection_id;
    public $featured = 0;
    public $public = 0;    
    public $added;
    public $modified;
    
        
    protected $_related = array('Collection'=>'getCollection', 
                                'TypeMetadata'=>'getTypeMetadata', 
                                'Type'=>'getItemType',
                                'Tags'=>'getTags',
                                'Files'=>'getFiles',
                                'Elements'=>'getElements',
                                'ItemTypeElements'=>'getItemTypeElements',
                                'ElementTexts'=>'getElementText');
    
    /**
     * @var array Set of non-persistent File objects to attach to the item.
     * @see Item::addFile()  
     */
    private $_files = array();
    
    protected function construct()
    {
        $this->_mixins[] = new Taggable($this);
        $this->_mixins[] = new Relatable($this);
        $this->_mixins[] = new ActsAsElementText($this);
        $this->_mixins[] = new PublicFeatured($this);
    }
    
    // Accessor methods
        
    /**
     * @return null|Collection
     **/
    public function getCollection()
    {
        $lk_id = (int) $this->collection_id;
        return $this->getTable('Collection')->find($lk_id);            
    }
    
    /**
     * Retrieve the ItemType record associated with this Item.
     * 
     * @return ItemType|null
     **/
    public function getItemType()
    {
        if ($this->item_type_id) {
            $itemType = $this->getTable('ItemType')->find($this->item_type_id);
            return $itemType;
        }
    }
    
    /**
     * Retrieve the set of File records associated with this Item.
     * 
     * @return array
     **/
    public function getFiles()
    {
        return $this->getTable('File')->findByItem($this->id);
    }
    
    /**
     * @return array Set of ElementText records.
     **/
    public function getElementText()
    {
        return $this->getElementTextRecords();
    }
    
    /**
     * Retrieve a set of elements associated with the item type of the item.
     *
     * Each one of the Element records that is retrieved should contain all the 
     * element text values associated with it.
     *
     * @uses ElementTable::findByItemType()
     * @return array Element records that are associated with the item type of
     * the item.  This array will be empty if the item does not have an 
     * associated type.
     **/    
    public function getItemTypeElements()
    {    
        /* My hope is that this will retrieve a set of elements, where each
        element contains an array of all the values for that element */
        $elements = $this->getTable('Element')->findByItemType($this->item_type_id);
        
        return $elements;
    }
    
    /**
     * Retrieve the User record that represents the creator of this Item.
     * 
     * @return User
     **/
    public function getUserWhoCreated()
    {
        $creator = $this->getRelatedEntities('added');
        
        if (is_array($creator)) {
            $creator = current($creator);
        }
        
        return $creator->User;
    }
        
    // End accessor methods
    
    // ActiveRecord callbacks
    
    /**
     * Stop the form submission if we are using the non-JS form to change the 
     * Item Type or add files.
     *
     * Also, do not allow people to change the public/featured status of an item
     * unless they have 'makePublic' or 'makeFeatured' permissions.
     *
     * @return void
     **/
    protected function beforeSaveForm(&$post)
    {
        $this->beforeSaveElements($post);
        
        if (!empty($post['change_type'])) {
            return false;
        }
        if (!empty($post['add_more_files'])) {
            return false;
        }
        if (!$this->userHasPermission('makePublic')) {
            unset($post['public']);
        }
        if (!$this->userHasPermission('makeFeatured')) {
            unset($post['featured']);
        }
        
        try {
            $this->_uploadFiles();
        } catch (Omeka_File_Ingest_InvalidException $e) {
            $this->addError('File Upload', $e->getMessage());
        }
    }
    
    /**
     * Modify the user's tags for this item based on form input.
     * 
     * Checks the 'tags' field from the post and applies all the differences in
     * the list of tags for the current user.
     * 
     * @uses Taggable::applyTagString()
     * @param ArrayObject
     * @return void
     **/
    protected function _modifyTagsByForm($post)
    {
        // Change the tags (remove some, add some)
        if (array_key_exists('my-tags-to-add', $post)) {
            $user = Omeka_Context::getInstance()->getCurrentUser();
            if ($user) {
                $this->addTags($post['my-tags-to-add'], $user);
                $this->deleteTags($post['my-tags-to-delete'], $user);                
                $this->deleteTags($post['other-tags-to-delete'], $user, $this->userHasPermission('untagOthers'));
            }
        }        
    }
        
    /**
     * Save all metadata for the item that has been received through the form.
     *
     * All of these have to run after the Item has been saved, because all 
     * require that the Item is already persistent in the database.
     * 
     * @return void
     **/
    protected function afterSaveForm($post)
    {        
        // Delete files that have been designated by passing an array of IDs 
        // through the form.
        if ($post['delete_files']) {
            $this->_deleteFiles($post['delete_files']);
        }
        
        $this->_modifyTagsByForm($post);
    }
    
    /**
     * Things to do in the afterSave() hook:
     * 
     * Save all files that had been associated with the item.
     * 
     * @return void
     **/
    protected function afterSave()
    {
        $this->saveFiles();
    }
    
    /**
     * Creates and returns a Zend_Search_Lucene_Document for the Omeka_Record
     *
     * @param Zend_Search_Lucene_Document $doc The Zend_Search_Lucene_Document from the subclass of Omeka_Record.
     * @return Zend_Search_Lucene_Document
     **/
    public function createLuceneDocument($doc=null) 
    {        
        if (!$doc) {
            $doc = new Zend_Search_Lucene_Document(); 
        }
        
        // adds the fields for added or modified
        Omeka_Search::addLuceneField($doc, 'UnIndexed', Omeka_Search::FIELD_NAME_DATE_ADDED, $this->added);            
        Omeka_Search::addLuceneField($doc, 'UnIndexed', Omeka_Search::FIELD_NAME_DATE_MODIFIED, $this->modified);
        
        // adds the fields for public and private       
        Omeka_Search::addLuceneField($doc, 'Keyword', Omeka_Search::FIELD_NAME_IS_PUBLIC, $this->public);            
        Omeka_Search::addLuceneField($doc, 'Keyword', Omeka_Search::FIELD_NAME_IS_FEATURED, $this->featured);
        
        // add the fields for the non-empty element texts, where each field is joint key of the set name and element name (in that order).        
        foreach($this->getAllElementsBySet() as $elementSet => $elements) {
            foreach($elements as $element) {
                $elementTextsToAdd = array();
                foreach($this->getTextsByElement($element) as $elementText) {
                    if (trim($elementText->text) != '') {
                        $elementTextsToAdd[] = $elementText->text;    
                    }
                }
                if (count($elementTextsToAdd) > 0) {
                    Omeka_Search::addLuceneField($doc, 'UnStored', array($elementSet, $element->name), $elementTextsToAdd);
                }
            }
        }

        //add the tags under the 'tag' field
        $tags = $this->getTags();
        $tagNames = array();
        foreach($tags as $tag) {
            $tagNames[] = $tag->name;
        }
        if (count($tagNames) > 0) {
            Omeka_Search::addLuceneField($doc, 'UnStored', array('Item','tags'), $tagNames);            
        }
                
        return parent::createLuceneDocument($doc);
    }
        
    /**
     * All of the custom code for deleting an item.
     *
     * @return void
     **/
    protected function _delete()
    {    
        $this->_deleteFiles();
        $this->deleteElementTexts();
    }
    
    /**
     * Delete files associated with the item.
     * 
     * If the IDs of specific files are passed in, this will delete only those
     * files (e.g. form submission).  Otherwise, it will delete all files 
     * associated with the item.
     * 
     * @uses FileTable::findByItem()
     * @param array $fileIds Optional
     * @return void
     **/
    protected function _deleteFiles(array $fileIds = array())
    {           
        $filesToDelete = $this->getTable('File')->findByItem($this->id, $fileIds);
        
        foreach ($filesToDelete as $fileRecord) {
            $fileRecord->delete();
        }
    }
    
    /**
     * Iterate through the $_FILES array for files that have been uploaded
     * to Omeka and attach each of those files to this Item.
     * 
     * @param string
     * @return void
     **/
    private function _uploadFiles()
    {
        // Tell it to always try the upload, but ignore any errors if any of
        // the files were not actually uploaded (left form fields empty).
        $files = insert_files_for_item($this, 'Upload', 'file', array('ignoreNoFile'=>true));
     }
    
    /**
     * Save all the files that have been associated with this item.
     * 
     * @return boolean
     **/
    public function saveFiles()
    {
        if (!$this->exists()) {
            throw new Omeka_Record_Exception("Files cannot be attached to an item that is "
                                . "not persistent in the database!");
        }
        
        foreach ($this->_files as $key => $file) {
            $file->item_id = $this->id;
            $file->forceSave();
            // Make sure we can't save it twice by mistake.
            unset($this->_files[$key]);
        }        
    }
    
    /**
     * Filter input from form submissions.  
     * 
     * @param array Dirty array.
     * @return array Clean array.
     **/
    protected function filterInput($input)
    {
        $options = array('inputNamespace'=>'Omeka_Filter');
        $filters = array(                         
                         // Foreign keys
                         'type_id'       => 'ForeignKey',
                         'collection_id' => 'ForeignKey',
                         
                         // Booleans
                         'public'   =>'Boolean',
                         'featured' =>'Boolean');  
        $filter = new Zend_Filter_Input($filters, null, $input, $options);
        return $filter->getUnescaped();
    }
    
    /**
     * Whether or not the Item has files associated with it.
     * 
     * @return boolean
     **/
    public function hasFiles()
    {
        $db = $this->getDb();
        $sql = "
        SELECT COUNT(f.id) 
        FROM $db->File f 
        WHERE f.item_id = ?";
        $count = (int) $db->fetchOne($sql, array((int) $this->id));
        return $count > 0;
    }
    
    /**
     * Retrieve the previous Item in the database.
     *
     * @uses ItemTable::findPrevious()
     * @return Item|false
     **/
    public function previous()
    {
        return $this->getDb()->getTable('Item')->findPrevious($this);
    }
    
    /**
     * Retrieve the next Item in the database.
     * 
     * @uses ItemTable::findNext()
     * @return Item|false
     **/
    public function next()
    {
        return $this->getDb()->getTable('Item')->findNext($this);
    }
            
    /**
     * Determine whether or not the Item has a File with a thumbnail image
     * (or any derivative image).
     * 
     * @return boolean
     **/
    public function hasThumbnail()
    {
        $db = $this->getDb();
        
        $sql = "
        SELECT COUNT(f.id) 
        FROM $db->File f 
        WHERE f.item_id = ? 
        AND f.has_derivative_image = 1";
        
        $count = $db->fetchOne($sql, array((int) $this->id));
            
        return $count > 0;
    }
    
    /**
     * Associate an unsaved (new) File record with this Item.
     * 
     * These File records will not be persisted in the database until the item
     * is saved or saveFiles() is invoked.
     * 
     * @see Item::saveFiles()
     * @param File $file
     * @return void
     **/
    public function addFile(File $file)
    {
        if ($file->exists()) {
            throw new Omeka_Record_Exception("Cannot add an existing file to an item!");
        }
        
        if (!$file->isValid()) {
            throw new Omeka_Record_Exception("File must be valid before it can be associated"
                                . " with an item!");
        }
        
        $this->_files[] = $file;
    }
}