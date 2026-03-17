define([
    'jquery',
    'MageOS_PasskeyAuth/js/passkey-core',
    'mage/translate',
    'jquery/ui'
], function ($, passkeyCore, $t) {
    'use strict';

    $.widget('mageOS.passkeyLogin', {
        options: {
            optionsUrl: '',
            verifyUrl: ''
        },

        _create: function () {
            if (!passkeyCore.isAvailable()) {
                return;
            }

            this.element.show();
            this.$button = this.element.find('#passkey-login-btn');
            this.$message = this.element.find('#passkey-login-message');
            this.$button.on('click', this._onLogin.bind(this));
        },

        _onLogin: function () {
            var self = this;
            var email = this._getEmailValue();

            this._clearMessage();
            this.$button.prop('disabled', true);

            this._fetchOptions(email)
                .then(function (options) {
                    return self._performAssertion(options);
                })
                .then(function (result) {
                    return self._verifyAssertion(result.challengeToken, result.credential);
                })
                .then(function () {
                    window.location.reload();
                })
                .catch(function (error) {
                    self._showMessage(error.message || $t('Passkey sign-in failed.'), 'error');
                    self.$button.prop('disabled', false);
                });
        },

        _getEmailValue: function () {
            var $emailField = $('input#email, input[name="login[username]"]');
            return $emailField.length ? $emailField.val() : '';
        },

        _fetchOptions: function (email) {
            return $.ajax({
                url: this.options.optionsUrl,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ email: email }),
                dataType: 'json'
            }).then(function (data) {
                if (data.errors) {
                    throw new Error(data.message || $t('Unable to sign in with passkey. Please use your password.'));
                }
                return data;
            });
        },

        _performAssertion: function (serverOptions) {
            var challengeToken = serverOptions.challengeToken;
            var requestOptions = passkeyCore.prepareRequestOptions(serverOptions);

            return navigator.credentials.get(requestOptions)
                .then(function (credential) {
                    return {
                        challengeToken: challengeToken,
                        credential: passkeyCore.serializeAssertionResponse(credential)
                    };
                })
                .catch(function (err) {
                    if (err.name === 'NotAllowedError') {
                        throw new Error($t('Passkey sign-in was cancelled.'));
                    }
                    throw new Error($t('Unable to sign in with passkey. Please use your password.'));
                });
        },

        _verifyAssertion: function (challengeToken, credential) {
            return $.ajax({
                url: this.options.verifyUrl,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    challengeToken: challengeToken,
                    credential: credential
                }),
                dataType: 'json'
            }).then(function (data) {
                if (data.errors) {
                    throw new Error(data.message || $t('Passkey verification failed. Please try again.'));
                }
                return data;
            });
        },

        _showMessage: function (text, type) {
            this.$message
                .removeClass('error success info')
                .addClass(type)
                .find('div').text(text);
            this.$message.show();
        },

        _clearMessage: function () {
            this.$message.hide().find('div').text('');
        }
    });

    return $.mageOS.passkeyLogin;
});
