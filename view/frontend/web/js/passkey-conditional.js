/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

define([
    'MageOS_PasskeyAuth/js/passkey-core',
    'Magento_Customer/js/customer-data'
], function (passkeyCore, customerData) {
    /**
     * x-magento-init entry point that arms passkey autofill on pages with a
     * sign-in field outside the login page, e.g. checkout:
     *
     * { "*": { "MageOS_PasskeyAuth/js/passkey-conditional": { "optionsUrl": ..., "verifyUrl": ... } } }
     */
    return function (config) {
        passkeyCore.startConditional({
            optionsUrl: config.optionsUrl,
            verifyUrl: config.verifyUrl,
            selectors: config.selectors,
            // Stay on this page (e.g. checkout) rather than follow the
            // verify reply's redirect_url, which targets the login page flow.
            onSuccess: function () {
                customerData.invalidate(['*']);
                window.location.reload();
            }
        });
    };
});
