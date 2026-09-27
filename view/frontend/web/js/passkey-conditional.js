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
            onSuccess: function () {
                customerData.invalidate(['*']);
                window.location.reload();
            }
        });
    };
});
