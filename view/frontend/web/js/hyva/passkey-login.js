/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

// Hyvä starts Alpine deferred, after this script runs, so register
// when Alpine initialises, before it walks the page.
window.addEventListener('alpine:init', () => {
    Alpine.data('passkeyLogin', () => ({
        available: false,
        loading: false,
        message: '',
        messageType: '',

        get notLoading() { return !this.loading; },
        get hasMessage() { return this.message !== ''; },
        get messageClasses() {
            if (this.messageType === 'error') return 'bg-red-100 text-red-700';
            if (this.messageType === 'success') return 'bg-green-100 text-green-700';
            return 'bg-blue-100 text-blue-700';
        },

        init() {
            this.available = passkeyCore.isAvailable();
            this.optionsUrl = this.$el.dataset.optionsUrl;
            this.verifyUrl = this.$el.dataset.verifyUrl;

            if (this.available) {
                passkeyCore.startConditional({
                    optionsUrl: this.optionsUrl,
                    verifyUrl: this.verifyUrl,
                    onError: (error) => {
                        this.message = passkeyCore.hasCustomerMessage(error)
                            ? error.message
                            : passkeyCore.t('Passkey sign-in didn\'t complete. Please try again.');
                        this.messageType = 'error';
                    }
                });
            }
        },

        getEmail() {
            const field = document.querySelector(passkeyCore.EMAIL_SELECTORS);
            return field ? field.value : '';
        },

        async login() {
            this.message = '';
            this.messageType = '';
            this.loading = true;

            // Only one WebAuthn request may be active: hand off from the
            // pending autofill (conditional) request to the modal ceremony.
            passkeyCore.abortConditional();

            try {
                const options = await passkeyCore.postJson(
                    this.optionsUrl,
                    {email: this.getEmail()},
                    passkeyCore.t('Unable to sign in with passkey. Please use your password.')
                );
                const result = await this.performAssertion(options);
                const reply = await passkeyCore.postJson(
                    this.verifyUrl,
                    result,
                    passkeyCore.t('Passkey verification failed. Please try again.')
                );
                passkeyCore.completeSignIn(reply);
            } catch (error) {
                this.message = error.message || passkeyCore.t('Passkey sign-in failed.');
                this.messageType = 'error';
                this.loading = false;
                passkeyCore.restartConditional();
            }
        },

        async performAssertion(serverOptions) {
            const challengeToken = serverOptions.challengeToken;
            const requestOptions = passkeyCore.prepareRequestOptions(serverOptions);

            try {
                const credential = await navigator.credentials.get(requestOptions);
                return {
                    challengeToken: challengeToken,
                    credential: passkeyCore.serializeAssertionResponse(credential)
                };
            } catch (err) {
                if (err.name === 'NotAllowedError') {
                    throw new Error(passkeyCore.t('Passkey sign-in was cancelled, or no passkey for this account was found on this device.'));
                }
                throw new Error(passkeyCore.t('Unable to sign in with passkey. Please use your password.'));
            }
        }
    }));
}, {once: true});
