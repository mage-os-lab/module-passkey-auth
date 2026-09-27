<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown by RateLimiter. Its message is safe to show: it says nothing about the account.
 */
class RateLimitExceededException extends LocalizedException
{
}
