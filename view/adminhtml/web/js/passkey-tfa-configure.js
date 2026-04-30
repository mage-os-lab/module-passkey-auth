define([
    'uiComponent',
    'jquery',
    'MageOS_PasskeyAuth/js/passkey-core',
    'mage/translate'
], function (Component, $, passkeyCore, $t) {
    return Component.extend({
        defaults: {
            template: 'MageOS_PasskeyAuth/tfa/passkey/configure',
            postUrl: '',
            successUrl: '',
            provider: 'passkey',
            currentStep: 'idle',
            errorMessage: '',
            friendlyName: ''
        },

        initObservable: function () {
            this._super().observe(['currentStep', 'errorMessage', 'friendlyName']);
            return this;
        },

        initialize: function () {
            this._super();

            if (!passkeyCore.isAvailable()) {
                this.currentStep('no-webauthn');
            }

            return this;
        },

        register: function () {
            var self = this;
            this.currentStep('registering');
            this.errorMessage('');

            $.ajax({
                url: this.postUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: window.FORM_KEY
                }
            }).done(function (options) {
                if (options.message) {
                    self.errorMessage(options.message);
                    self.currentStep('error');
                    return;
                }

                var challengeToken = options.challengeToken;
                var createOptions = passkeyCore.prepareCreationOptions(options);

                navigator.credentials.create({ publicKey: createOptions })
                    .then(function (credential) {
                        var serialized = passkeyCore.serializeAttestationResponse(credential);

                        return $.ajax({
                            url: self.postUrl,
                            type: 'POST',
                            dataType: 'json',
                            data: {
                                form_key: window.FORM_KEY,
                                challenge_token: challengeToken,
                                credential: JSON.stringify(serialized),
                                friendly_name: self.friendlyName(),
                                provider: self.provider
                            }
                        });
                    })
                    .then(function (response) {
                        if (response.success) {
                            self.currentStep('registered');
                            setTimeout(function () {
                                window.location.href = self.successUrl;
                            }, 1500);
                        } else {
                            self.errorMessage(response.message || $t('Registration failed.'));
                            self.currentStep('error');
                        }
                    })
                    .catch(function (err) {
                        if (err.name !== 'AbortError') {
                            self.errorMessage(err.message || $t('Registration failed.'));
                            self.currentStep('error');
                        } else {
                            self.currentStep('idle');
                        }
                    });
            }).fail(function () {
                self.errorMessage($t('Server error. Please try again.'));
                self.currentStep('error');
            });
        },

        retry: function () {
            this.currentStep('idle');
            this.errorMessage('');
        }
    });
});
