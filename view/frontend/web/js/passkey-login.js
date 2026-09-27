/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

define([
    'jquery',
    'MageOS_PasskeyAuth/js/passkey-core',
    'Magento_Customer/js/customer-data',
    'mage/translate',
    'jquery-ui-modules/widget'
], function ($, passkeyCore, customerData, $t) {
    'use strict';

    $.widget('mageOS.passkeyLogin', {
        options: {
            optionsUrl: '',
            verifyUrl: '',
            emailSelectors: passkeyCore.EMAIL_SELECTORS
        },

        _create: function () {
            if (!passkeyCore.isAvailable()) {
                return;
            }

            this.element.show();
            this.$button = this.element.find('#passkey-login-btn');
            this.$message = this.element.find('#passkey-login-message');
            this.$button.on('click', this._onLogin.bind(this));

            passkeyCore.startConditional({
                optionsUrl: this.options.optionsUrl,
                verifyUrl: this.options.verifyUrl,
                selectors: this.options.emailSelectors,
                onError: function () {
                    this._showMessage($t('Passkey sign-in didn\'t complete. Please try again.'), 'error');
                }.bind(this),
                onSuccess: this._onSignedIn.bind(this)
            });
        },

        /**
         * The ceremony runs over fetch(), which customer-data's ajaxComplete
         * hook never sees: invalidate all sections so the header, minicart
         * etc. reload for the signed-in customer.
         */
        _onSignedIn: function (result) {
            this._showMessage($t('Signed in. One moment…'), 'success');
            customerData.invalidate(['*']);
            passkeyCore.completeSignIn(result);
        },

        _onLogin: function () {
            var self = this;
            var email = this._getEmailValue();

            this._clearMessage();
            this._setBusy(true);

            // Only one WebAuthn request may be active: hand off from the
            // pending autofill (conditional) request to the modal ceremony.
            passkeyCore.abortConditional();

            passkeyCore.postJson(
                this.options.optionsUrl,
                { email: email },
                $t('Unable to sign in with passkey. Please use your password.')
            )
                .then(function (options) {
                    return self._performAssertion(options);
                })
                .then(function (result) {
                    return passkeyCore.postJson(
                        self.options.verifyUrl,
                        result,
                        $t('Passkey verification failed. Please try again.')
                    );
                })
                .then(function (result) {
                    self._onSignedIn(result);
                })
                .catch(function (error) {
                    self._showMessage(error.message || $t('Passkey sign-in failed.'), 'error');
                    self._setBusy(false);
                    passkeyCore.restartConditional();
                });
        },

        _setBusy: function (busy) {
            this.$button.prop('disabled', busy)
                .attr('aria-busy', busy ? 'true' : 'false')
                .toggleClass('passkey-busy', busy)
                .find('span')
                .text(busy ? $t('Waiting for your passkey…') : $t('Sign in with Passkey'));
        },

        _getEmailValue: function () {
            return $(this.options.emailSelectors).val() || '';
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
                        throw new Error($t('Passkey sign-in was cancelled, or no passkey for this account was found on this device.'));
                    }
                    throw new Error($t('Unable to sign in with passkey. Please use your password.'));
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
