'use strict';

function passkeyLogin(config) {
    return {
        available: false,
        loading: false,
        message: '',
        messageType: '',

        init() {
            this.available = passkeyCore.isAvailable();
        },

        getEmail() {
            const field = document.querySelector('input#email, input[name="login[username]"]');
            return field ? field.value : '';
        },

        async login() {
            this.clearMessage();
            this.loading = true;

            try {
                const options = await this.fetchOptions(this.getEmail());
                const result = await this.performAssertion(options);
                await this.verifyAssertion(result.challengeToken, result.credential);
                window.location.reload();
            } catch (error) {
                this.showMessage(error.message || 'Passkey sign-in failed.', 'error');
                this.loading = false;
            }
        },

        async fetchOptions(email) {
            const response = await fetch(config.optionsUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({email: email}),
                credentials: 'same-origin'
            });
            const data = await response.json();

            if (data.errors) {
                throw new Error(data.message || 'Unable to sign in with passkey. Please use your password.');
            }

            return data;
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
                    throw new Error('Passkey sign-in was cancelled.');
                }
                throw new Error('Unable to sign in with passkey. Please use your password.');
            }
        },

        async verifyAssertion(challengeToken, credential) {
            const response = await fetch(config.verifyUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    challengeToken: challengeToken,
                    credential: credential
                }),
                credentials: 'same-origin'
            });
            const data = await response.json();

            if (data.errors) {
                throw new Error(data.message || 'Passkey verification failed. Please try again.');
            }

            return data;
        },

        showMessage(text, type) {
            this.message = text;
            this.messageType = type;
        },

        clearMessage() {
            this.message = '';
            this.messageType = '';
        }
    };
}
