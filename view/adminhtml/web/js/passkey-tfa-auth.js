define([
    'uiComponent',
    'jquery',
    'MageOS_PasskeyAuth/js/passkey-core',
    'mage/translate'
], function (Component, $, passkeyCore, $t) {
    return Component.extend({
        defaults: {
            template: 'MageOS_PasskeyAuth/tfa/passkey/auth',
            postUrl: '',
            successUrl: '',
            provider: 'passkey',
            currentStep: 'idle',
            errorMessage: ''
        },

        initObservable: function () {
            this._super().observe(['currentStep', 'errorMessage']);
            return this;
        },

        initialize: function () {
            this._super();

            if (!passkeyCore.isAvailable()) {
                this.currentStep('no-webauthn');
                return this;
            }

            // Auto-trigger authentication on load
            this.authenticate();

            return this;
        },

        authenticate: function () {
            var self = this;
            this.currentStep('authenticating');
            this.errorMessage('');

            $.ajax({
                url: this.postUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: window.FORM_KEY,
                    provider: this.provider
                }
            }).done(function (options) {
                if (options.message) {
                    self.errorMessage(options.message);
                    self.currentStep('error');
                    return;
                }

                var challengeToken = options.challengeToken;
                var requestOptions = passkeyCore.prepareRequestOptions(options);

                navigator.credentials.get({ publicKey: requestOptions })
                    .then(function (credential) {
                        var serialized = passkeyCore.serializeAssertionResponse(credential);

                        return $.ajax({
                            url: self.postUrl,
                            type: 'POST',
                            dataType: 'json',
                            data: {
                                form_key: window.FORM_KEY,
                                challenge_token: challengeToken,
                                credential: JSON.stringify(serialized),
                                provider: self.provider
                            }
                        });
                    })
                    .then(function (response) {
                        if (response.success) {
                            self.currentStep('success');
                            window.location.href = response.redirect_url || self.successUrl;
                        } else {
                            self.errorMessage(response.message || $t('Authentication failed.'));
                            self.currentStep('error');
                        }
                    })
                    .catch(function (err) {
                        if (err.name !== 'AbortError') {
                            self.errorMessage(err.message || $t('Authentication failed.'));
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
            this.authenticate();
        }
    });
});
