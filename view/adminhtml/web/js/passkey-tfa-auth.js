/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

define([
    'MageOS_PasskeyAuth/js/passkey-tfa-ceremony',
    'MageOS_PasskeyAuth/js/passkey-core'
], function (Ceremony, passkeyCore) {
    return Ceremony.extend({
        defaults: {
            template: 'MageOS_PasskeyAuth/tfa/passkey/auth',
            failureMessage: 'Authentication failed.'
        },

        initialize: function () {
            this._super();

            if (this.currentStep() !== 'no-webauthn') {
                this.authenticate();
            }

            return this;
        },

        authenticate: function () {
            var self = this;

            this.runCeremony('authenticating', function (options) {
                return navigator.credentials.get(passkeyCore.prepareRequestOptions(options))
                    .then(function (credential) {
                        return passkeyCore.serializeAssertionResponse(credential);
                    });
            }).then(function (accepted) {
                if (accepted) {
                    self.currentStep('success');
                    window.location.href = self.successUrl;
                }
            });
        }
    });
});
