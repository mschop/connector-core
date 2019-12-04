<?php
namespace Jtl\Connector\Core\Event\Model;

use Jtl\Connector\Core\Model\QueryFilter;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Class ModelBeforeQueryFilterEvent
 * @package Jtl\Connector\Core\Event\Model
 */
class ModelBeforeQueryFilterEvent extends Event
{
    /**
     * @var QueryFilter
     */
    protected $queryFilter;

    /**
     * ModelBeforeQueryFilterEvent constructor.
     * @param QueryFilter $queryFilter
     */
    public function __construct(QueryFilter $queryFilter)
    {
        $this->queryFilter = $queryFilter;
    }

    /**
     * @return QueryFilter
     */
    public function getQueryFilter(): QueryFilter
    {
        return $this->queryFilter;
    }


}