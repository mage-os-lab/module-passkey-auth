define([
    'MageOS_PasskeyAuth/js/passkey-tfa-ceremony',
    'MageOS_PasskeyAuth/js/passkey-core'
], function (Ceremony, passkeyCore) {
    return Ceremony.extend({
        defaults: {
            template: 'MageOS_PasskeyAuth/tfa/passkey/configure',
            failureMessage: 'Registration failed.'
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
