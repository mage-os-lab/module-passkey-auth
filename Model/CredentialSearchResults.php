<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use MageOS\PasskeyAuth\Api\Data\CredentialSearchResultsInterface;
use Magento\Framework\Api\SearchResults;

class CredentialSearchResults extends SearchResults implements CredentialSearchResultsInterface
{
}
