<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\ResourceModel\Credential\Grid;

use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Grid collection for the admin passkey credential listing.
 * Joins customer identity so support staff can find credentials by email.
 */
class Collection extends SearchResult
{
    protected function _initSelect()
    {
        parent::_initSelect();

        // Only the grid's columns: skip the public_key blob and other binary fields.
        $this->getSelect()->reset(Select::COLUMNS)->columns([
            'entity_id',
            'customer_id',
            'friendly_name',
            'transports',
            'sign_count',
            'created_at',
            'last_used_at',
        ], 'main_table')->joinLeft(
            ['customer' => $this->getTable('customer_entity')],
            'customer.entity_id = main_table.customer_id',
            [
                'customer_email' => 'customer.email',
                'customer_firstname' => 'customer.firstname',
                'customer_lastname' => 'customer.lastname',
            ]
        );

        $this->addFilterToMap('customer_email', 'customer.email');
        $this->addFilterToMap('customer_firstname', 'customer.firstname');
        $this->addFilterToMap('customer_lastname', 'customer.lastname');
        $this->addFilterToMap('entity_id', 'main_table.entity_id');
        $this->addFilterToMap('created_at', 'main_table.created_at');

        return $this;
    }
}
