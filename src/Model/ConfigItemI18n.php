<?php
/**
 * @copyright 2010-2015 JTL-Software GmbH
 * @package jtl\Connector\Model
 * @subpackage Product
 */

namespace jtl\Connector\Model;

use InvalidArgumentException;
use JMS\Serializer\Annotation as Serializer;
use jtl\Connector\Model\Common\I18n;

/**
 * Localized config item name and description.
 *
 * @access public
 * @package jtl\Connector\Model
 * @subpackage Product
 * @Serializer\AccessType("public_method")
 */
class ConfigItemI18n extends I18n
{
    /**
     * @var Identity Reference to configItem
     * @Serializer\Type("jtl\Connector\Model\Identity")
     * @Serializer\SerializedName("configItemId")
     * @Serializer\Accessor(getter="getConfigItemId",setter="setConfigItemId")
     */
    protected $configItemId = null;
    
    /**
     * @var string Description (html). Will be ignored, if inheritProductName==true
     * @Serializer\Type("string")
     * @Serializer\SerializedName("description")
     * @Serializer\Accessor(getter="getDescription",setter="setDescription")
     */
    protected $description = '';

    /**
     * @var string Config item name. Will be ignored if inheritProductName==true
     * @Serializer\Type("string")
     * @Serializer\SerializedName("name")
     * @Serializer\Accessor(getter="getName",setter="setName")
     */
    protected $name = '';
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->configItemId = new Identity();
    }
    
    /**
     * @param Identity $configItemId Reference to configItem
     * @return ConfigItemI18n
     * @throws InvalidArgumentException if the provided argument is not of type 'Identity'.
     */
    public function setConfigItemId(Identity $configItemId): ConfigItemI18n
    {
        $this->configItemId = $configItemId;
        
        return $this;
    }
    
    /**
     * @return Identity Reference to configItem
     */
    public function getConfigItemId(): Identity
    {
        return $this->configItemId;
    }
    
    /**
     * @param string $description Description (html). Will be ignored, if inheritProductName==true
     * @return ConfigItemI18n
     */
    public function setDescription(string $description): ConfigItemI18n
    {
        $this->description = $description;
        
        return $this;
    }
    
    /**
     * @return string Description (html). Will be ignored, if inheritProductName==true
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @param string $name Config item name. Will be ignored if inheritProductName==true
     * @return ConfigItemI18n
     */
    public function setName(string $name): ConfigItemI18n
    {
        $this->name = $name;
        
        return $this;
    }
    
    /**
     * @return string Config item name. Will be ignored if inheritProductName==true
     */
    public function getName(): string
    {
        return $this->name;
    }
}
