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
        },

        getEmail() {
            const field = document.querySelector('input#email, input[name="login[username]"]');
            return field ? field.value : '';
        },

        async login() {
            this.message = '';
            this.messageType = '';
            this.loading = true;

            try {
                const options = await this.fetchOptions(this.getEmail());
                const result = await this.performAssertion(options);
                await this.verifyAssertion(result.challengeToken, result.credential);
                window.location.reload();
            } catch (error) {
                this.message = error.message || 'Passkey sign-in failed.';
                this.messageType = 'error';
                this.loading = false;
            }
        },

        async fetchOptions(email) {
            const response = await fetch(this.optionsUrl, {
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
            const response = await fetch(this.verifyUrl, {
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
        }
    }));
}, {once: true});
