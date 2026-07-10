window.addEventListener('alpine:init', () => {
    Alpine.data('passkeyLogin', () => ({
        available: false,
        loading: false,
        message: '',
        messageType: '',
        conditionalAbort: null,
        conditionalRestartsLeft: 3,

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
            this.startConditional();
        },

        getEmail() {
            const field = document.querySelector('input#email, input[name="login[username]"]');
            return field ? field.value : '';
        },

        markEmailFields() {
            document.querySelectorAll('input#email, input[name="login[username]"]').forEach((field) => {
                const current = field.getAttribute('autocomplete') || 'username';
                if (!current.includes('webauthn')) {
                    field.setAttribute('autocomplete', current + ' webauthn');
                }
            });
        },

        async startConditional() {
            const supported = this.available
                && typeof window.AbortController !== 'undefined'
                && await passkeyCore.isConditionalMediationAvailable();

            if (!supported) {
                return;
            }

            this.markEmailFields();
            this.runConditional();
        },

        abortConditional() {
            if (this.conditionalAbort) {
                this.conditionalAbort.abort();
                this.conditionalAbort = null;
            }
        },

        async runConditional() {
            this.abortConditional();
            this.conditionalAbort = new AbortController();

            try {
                const options = await this.fetchOptions('');
                const request = passkeyCore.prepareRequestOptions(options);
                request.mediation = 'conditional';
                request.signal = this.conditionalAbort.signal;

                const credential = await navigator.credentials.get(request);
                if (!credential) {
                    return;
                }

                await this.verifyAssertion(
                    options.challengeToken,
                    passkeyCore.serializeAssertionResponse(credential)
                );
                window.location.reload();
            } catch (error) {
                // Aborting the autofill request is the expected hand-off path.
                if (error && error.name === 'AbortError') {
                    return;
                }

                // The user picked a passkey but verification failed (most
                // often an expired challenge on a long-idle tab): surface it
                // and re-arm so the autofill entry keeps working.
                if (this.conditionalRestartsLeft > 0) {
                    this.conditionalRestartsLeft--;
                    this.message = 'Passkey sign-in didn\'t complete. Please try again.';
                    this.messageType = 'error';
                    this.runConditional();
                }
            }
        },

        async login() {
            this.message = '';
            this.messageType = '';
            this.loading = true;

            // Only one WebAuthn request may be active: hand off from the
            // pending autofill (conditional) request to the modal ceremony.
            this.abortConditional();

            try {
                const options = await this.fetchOptions(this.getEmail());
                const result = await this.performAssertion(options);
                await this.verifyAssertion(result.challengeToken, result.credential);
                window.location.reload();
            } catch (error) {
                this.message = error.message || 'Passkey sign-in failed.';
                this.messageType = 'error';
                this.loading = false;
                this.runConditional();
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
