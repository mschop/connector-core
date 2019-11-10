<?php
/**
 * @copyright 2010-2014 JTL-Software GmbH
 * @package Jtl\Connector\Core\Type
 */

namespace Jtl\Connector\Core\Type;

use Jtl\Connector\Core\Type\PropertyInfo;

/**
 * @access public
 * @package Jtl\Connector\Core\Type
 */
class ProductMediaFileAttrI18n extends AbstractDataType
{
    protected function loadProperties()
    {
        return [
            new PropertyInfo('name', 'string', '', false, false, false),
            new PropertyInfo('value', 'string', '', false, false, false),
            new PropertyInfo('languageISO', 'string', '', false, false, false)
        ];
    }

    public function isMain()
    {
        return false;
    }
}
