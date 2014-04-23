<?php
/**
 * @copyright 2010-2013 JTL-Software GmbH
 * @package jtl\Connector\Model
 * @subpackage Category
 */

namespace jtl\Connector\Model;

use \jtl\Core\Model\DataModel;

/**
 * Link customergroup with category. Set optional discount on category for customergroup. 
 *
 * @access public
 * @subpackage Category
 */
class CategoryCustomerGroup extends DataModel
{
    /**
     * @var Identity Reference to customerGroup
     */
    protected $_customerGroupId = null;
    
    /**
     * @var Identity Reference to category
     */
    protected $_categoryId = null;
    
    /**
     * @var double Optional discount on products in specified categoryId for  customerGroupId
     */
    protected $_discount = 0;
    
    /**
     * @var mixed:string
     */
    protected $_identities = array(
        'customerGroupId',
        'categoryId'
    );
    
    /**
     * CategoryCustomerGroup Setter
     *
     * @param string $name
     * @param string $value
     */
    public function __set($name, $value)
    {
        if (property_exists($this, $name)) {
            if ($value === null) {
                $this->$name = null;
                return;
            }
        
            switch ($name) {
                case "_customerGroupId":
                case "_categoryId":
                
                    $this->$name = Identity::convert($value);
                    break;
            
                case "_discount":
                
                    $this->$name = (double)$value;
                    break;
            
            }
        }
    }
    
    /**
     * @param Identity $customerGroupId Reference to customerGroup
     * @return \jtl\Connector\Model\CategoryCustomerGroup
     */
    public function setCustomerGroupId(Identity $customerGroupId)
    {
        $this->_customerGroupId = $customerGroupId;
        return $this;
    }
    
    /**
     * @return Identity Reference to customerGroup
     */
    public function getCustomerGroupId()
    {
        return $this->_customerGroupId;
    }
    /**
     * @param Identity $categoryId Reference to category
     * @return \jtl\Connector\Model\CategoryCustomerGroup
     */
    public function setCategoryId(Identity $categoryId)
    {
        $this->_categoryId = $categoryId;
        return $this;
    }
    
    /**
     * @return Identity Reference to category
     */
    public function getCategoryId()
    {
        return $this->_categoryId;
    }
    /**
     * @param double $discount Optional discount on products in specified categoryId for  customerGroupId
     * @return \jtl\Connector\Model\CategoryCustomerGroup
     */
    public function setDiscount($discount)
    {
        $this->_discount = (double)$discount;
        return $this;
    }
    
    /**
     * @return double Optional discount on products in specified categoryId for  customerGroupId
     */
    public function getDiscount()
    {
        return $this->_discount;
    }
}