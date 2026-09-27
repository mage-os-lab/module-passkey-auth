/**
 * Shared two-phase flow for the admin passkey 2FA screens:
 * POST for options -> browser WebAuthn call -> POST the serialized credential.
 */
define([
    'uiComponent',
    'jquery',
    'MageOS_PasskeyAuth/js/passkey-core',
    'mage/translate'
], function (Component, $, passkeyCore, $t) {
    return Component.extend({
        defaults: {
            postUrl: '',
            successUrl: '',
            failureMessage: 'Passkey verification failed.',
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
            }

            return this;
        },

        /**
         * @param {String} busyStep - step shown while the ceremony runs
         * @param {Function} browserCall - options => Promise of the serialized credential
         * @returns {Promise<Boolean>} true when the server accepted the credential
         */
        runCeremony: function (busyStep, browserCall) {
            var self = this;

            this.currentStep(busyStep);
            this.errorMessage('');

            return this.post({})
                .then(function (options) {
                    return browserCall(options).then(function (credential) {
                        return self.post({
                            challenge_token: options.challengeToken,
                            credential: JSON.stringify(credential)
                        });
                    });
                })
                .then(function () {
                    return true;
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') {
                        self.currentStep('idle');
                    } else {
                        self.errorMessage(err && err.message ? err.message : $t(self.failureMessage));
                        self.currentStep('error');
                    }
                    return false;
                });
        },

        /**
         * @returns {Promise<Object>} rejects with an Error carrying the server message when success is false
         */
        post: function (data) {
            var self = this;

            return Promise.resolve($.ajax({
                url: this.postUrl,
                type: 'POST',
                dataType: 'json',
                data: $.extend({form_key: window.FORM_KEY}, data)
            })).catch(function () {
                throw new Error($t('Server error. Please try again.'));
            }).then(function (response) {
                if (response.success === false) {
                    throw new Error(response.message || $t(self.failureMessage));
                }
                return response;
            });
        }
    });
});
