<?php
/**
 * @package Omeka
 */
require_once 'Zend/Controller/Action.php';
require_once 'Kea/Controller/Browse/Paginate.php';
require_once 'Kea/Controller/Browse/List.php';
abstract class Kea_Controller_Action extends Zend_Controller_Action
{
	/**
	 * @var protected methods array
	 */
	protected $_protected = array('delete');
	
	/**
	 * @var Kea_View
	 */
	protected $_view;
	
	/**
	 * Doctrine_Table associated with the controller (initialized optionally within the init() method)
	 *
	 * @var Doctrine_Table
	 **/
	protected $_table;
	
	/**
	 * Allows for builtin CRUD scaffolding in the controllers (must be initialized within the init() method)
	 *
	 * @var string
	 **/
	protected $_modelClass;
	
	/**
	 * Current options for browsing involve either Pagination or a single page list
	 *
	 * @var Kea_Controller_Browse_Interface
	 **/
	protected $_browse;

	/**
	 * Attaches a view object to the controller.
	 * The view also receives the current controller
	 * object so it can interact with the request / response objects.
	 */
	public function __construct(Zend_Controller_Request_Abstract $request, Zend_Controller_Response_Abstract $response, array $invokeArgs = array())
	{	
		// Zend_Controller_Action __construct finishes by running init()
		$init = parent::__construct($request, $response, $invokeArgs);
		
		$this->_view = new Kea_View($this);
		
		return $init;
	}
	
	/**
	 * Define this here to avoid Zend's silly requirements
	 */
	public function noRouteAction()
    {
        $this->_redirect('/');
    }


	/**
	 * CONVIENCE METHODS
	 */
	
	/**
	 * Retrieve the Doctrine table for queries
	 * 
	 */
	public function getTable($table = null)
	{
		return Doctrine_Manager::getInstance()->getTable($table);
	}

	/**
	 * Retrieve an option from the option table.
	 * This may end up being redundant.
	 * 
	 * @starred
	 * 
	 */
	public function getOption($name)
	{
		$optionTable = $this->getTable('option');
		$options = $optionTable->findByDql("name LIKE :name", array('name' => $name));
		if (count($options) == 1) {
			return ($options[0]);
		}
		return false;
	}
	
	public function getView()
	{
		return $this->_view;
	}
	
	/**
	 * Stolen directly from Rails.
	 * Again, this may be redundant, in that message delivery
	 * should allow for ajax and non ajax responses.
	 * Session passed messages obviously do not necessarily allow
	 * for this.
	 * 
	 * @starred
	 * 
	 */
	public function flash($msg=null)
	{
		require_once 'Zend/Session.php';
		$flash = new Zend_Session('flash');
		$flash->msg = $msg;
	}

	///// BASIC CRUD INTERFACE /////
	
	public function homeAction()
	{
		$this->indexAction();
	}
	
	
	public function indexAction()
	{
		$controllerName = strtolower(str_replace("Controller", "", get_class($this)));
        $this->_forward($controllerName, 'browse');
	}
	
	/**
	 * Browsing strategy defaults to a full listing, can be switched to pagination by instantiating Kea_Controller_Browse_Pagination in the init() method
	 *
	 * @return void
	 **/
	public function browseAction()
	{
		if(empty($this->_modelClass)) throw new Exception( 'Scaffolding class has not been specified' );
		
		if(empty($this->_browse)) $this->_browse = new Kea_Controller_Browse_List($this->_modelClass, $this);
		
		$this->_browse->browse();
	}
	
	public function showAction()
	{
		$varName = strtolower($this->_modelClass);
		
		//duplicated from above
		$pluralName = $varName.'s';
		$viewPage = $pluralName.DIRECTORY_SEPARATOR.'show.php';
		
		try{
			$$varName = $this->findById();
		}catch(Exception $e) {
			echo $e->getMessage();exit;
		}
		
		$this->render($viewPage, compact($varName));
	}
	
	public function addAction()
	{
		//Maybe this recurring bit should be abstracted out
		$varName = strtolower($this->_modelClass);
		$class = $this->_modelClass;
		$pluralName = $varName.'s';
		
		$$varName = new $class();
		
		if($this->commitForm($$varName))
		{
			$this->_redirect($pluralName.'/browse/');
		}else {
			$this->loadFormData();
			$this->render($pluralName.'/add.php', compact($varName));			
		}

	}
	
	public function editAction()
	{
		$varName = strtolower($this->_modelClass);
		$pluralName = $varName.'s';
		
		try{
			$$varName = $this->findById();
		}catch(Exception $e) {
			echo $e->getMessage();exit;
		}
		
		if($this->commitForm($$varName))
		{
			$this->_redirect($pluralName.'/show/'.$$varName->id);
		}else{
			$this->loadFormData();
			$this->render($pluralName.'/edit.php', compact($varName));
		}		
	}
	
	public function deleteAction()
	{
		$browseURL = strtolower($this->_modelClass).'s/browse/';
		
		$record = $this->findById();
		$record->delete();
		$this->_redirect($browseURL);
	}
	
	/**
	 * Processes and saves the form to the given record
	 *
	 * @param Kea_Record
	 * @return boolean True on success, false otherwise
	 **/
	protected function commitForm($record)
	{
		if(!empty($_POST))
		{
			$record->setFromForm($_POST);
			try {
				$record->save();
				return true;
			}
			catch(Doctrine_Validator_Exception $e) {
				return false;
			}	
		}
		return false;
	}
	
	/**
	 * Load extra data that would need to be displayed for forms, for example the item form would require all collections, plugins, etc.
	 *
	 * @return void
	 **/
	protected function loadFormData() {}
	
	///// END BASIC CRUD INTERFACE /////
	
	/**
	 * Most convenient usage would be something like: $this->render("show.php", compact("items", "total", "foo", "bar"));
	 *
	 * @param string The page, including .php extension
	 * @param array The variables to be included on that page, where key = name and value = contents.  see compact()
	 * @return void
	 * 
	 **/
	public function render($page, array $vars = array())
	{
		$this->_view->assign($vars);
		$this->getResponse()->appendBody($this->_view->render($page));
	}
	
	/**
	 * Find a particular record given its unique ID # and (optionally) its class name.  Essentially a convenience method
	 * $this->_table must be initialized in the init() method if the particular model is to be chosen automagically
	 * 
	 * @return Kea_Record
	 **/
	public function findById($id=null, $table=null)
	{
		$id = (!$id) ? $this->getRequest()->getParam('id') : $id;
		
		if(!$id) throw new Exception( get_class($this).': No ID passed to this request' );
		
		if(!$table) {
			$record = $this->_table->find($id);
		}else {
			$record = Doctrine_Manager::getInstance()->getTable($table)->find($id);
		}
		
		if(!$record) {
			throw new Exception( get_class($this).": No record with ID # $id exists" );
		}
		
		return $record;
	}
}
?>