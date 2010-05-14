<?php
/**
 * @version $Id$
 * @copyright Center for History and New Media, 2007-2010
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package Omeka
 **/

/**
 * 
 *
 * @package Omeka
 * @copyright Center for History and New Media, 2007-2010
 **/
class Omeka_Controller_Action_Helper_Mail extends Zend_Controller_Action_Helper_Abstract
{
    /**
     * @var Zend_View
     */
    private $_view;
    
    /**
     * @var string Subject of the email.
     */
    private $_subject;
    
    private $_subjectPrefix;
            
    public function __construct(Zend_View $view)
    {
        $this->_view = $view;
        $this->_mail = new Zend_Mail;
        $this->_mail->addHeader('X-Mailer', 'PHP/' . phpversion());
    }
    
    /**
     * Delegate to the Zend_Mail instance.
     */
    public function __call($method, $args)
    {
        if (method_exists($this->_mail, $method)) {
            return call_user_func_array(array($this->_mail, $method), $args);
        }
        throw new BadMethodCallException("Method named '$method' does not exist.");
    }
    
    /**
     * Set the prefix for the subject header.  Typically takes the form "[Site Name] ".
     */
    public function setSubjectPrefix($prefix)
    {
        $this->_subjectPrefix = $prefix;
    }
    
    /**
     * Set the subject of the email.
     */
    public function setSubject($subject)
    {
        $this->_subject = $subject;
    }
    
    /**
     * Render the given view and use it as the body of the email.
     * 
     * @param string $viewScript View script path.
     * @param boolean $html Whether or not the assigned view script will render
     * as HTML.  Defaults to false.
     */
    public function setBodyFromView($viewScript, $html = false)
    {
        $rendered = $this->_view->render($viewScript);
        $html ? $this->_mail->setBodyHtml($rendered) 
              : $this->_mail->setBodyText($rendered);
    }
    
    /**
     * Send the email.
     * 
     * @interal Delegates to Zend_Mail::send().  Is only necessary for additional
     * processing of the subject line prior to sending.
     * @param Zend_Mail_Transport_Abstract $transport Optional defaults to null.
     * @see Zend_Mail::send()
     */
    public function send($transport = null)
    {
        // Prepare the subject line.
        $this->_mail->setSubject($this->_subjectPrefix . $this->_subject);
        return $this->_mail->send($transport);
    }            
}