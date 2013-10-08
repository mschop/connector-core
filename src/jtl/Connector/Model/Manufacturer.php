<?php
/**
 * @copyright 2010-2013 JTL-Software GmbH
 * @package jtl\Connector\Model
 */

namespace jtl\Connector\Model;

use \jtl\Core\Model\DataModel;

/**
 * Manufacturer Model
 * @access public
 */
class Manufacturer extends DataModel
{
    /**
     * @var string
     */
    protected $_id = '';
    
    /**
     * @var string
     */
    protected $_name = '';
    
    /**
     * @var string
     */
    protected $_www = '';
    
    /**
     * @var int
     */
    protected $_sort = 0;
    
    /**
     * @var string
     */
    protected $_url = '';
    
    /**
     * Manufacturer Setter
     *
     * @param string $name
     * @param string $value
     */
    public function __set($name, $value)
    {
        if ($value === null) {
            $this->$name = null;
            return;
        }
        
        switch ($name) {
            case "_id":
            case "_name":
            case "_www":
            case "_url":
            
                $this->$name = (string)$value;
                break;
        
            case "_sort":
            
                $this->$name = (int)$value;
                break;
        
        }
    }
    
    /**
     * (non-PHPdoc)
     * @see \jtl\Core\Model\DataModel::map()
     */ 
    public function map($toWawi = false, \stdClass $obj = null)
    {
    
    }
}
?>