/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

define([
    'MageOS_PasskeyAuth/js/passkey-tfa-ceremony',
    'MageOS_PasskeyAuth/js/passkey-core',
    'mage/translate'
], function (Ceremony, passkeyCore, $t) {
    return Ceremony.extend({
        defaults: {
            template: 'MageOS_PasskeyAuth/tfa/passkey/configure',
            failureMessage: $t('Registration failed.')
        },

        register: function () {
            var self = this;

            this.runCeremony('registering', function (options) {
                return navigator.credentials.create(passkeyCore.prepareCreationOptions(options))
                    .then(function (credential) {
                        return passkeyCore.serializeAttestationResponse(credential);
                    });
            }).then(function (accepted) {
                if (accepted) {
                    self.currentStep('registered');
                    setTimeout(function () {
                        window.location.href = self.successUrl;
                    }, 1500);
                }
            });
        },

        retry: function () {
            this.currentStep('idle');
            this.errorMessage('');
        }
    });
});
