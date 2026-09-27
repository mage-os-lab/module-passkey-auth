(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else {
        root.passkeyCore = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    var ENROLLMENT_DISMISSED_AT_KEY = 'passkey_enrollment_dismissed_at',
        ENROLLMENT_DISMISS_COUNT_KEY = 'passkey_enrollment_dismiss_count',
        ENROLLMENT_COOLDOWN_MS = 30 * 86400000,
        ENROLLMENT_MAX_DISMISSALS = 3,
        conditional = null,
        webauthnFieldSelectors = null;

    /**
     * Add the "webauthn" autofill token. It must follow an autofill field
     * name, so "off"/"on" (as on Luma's login form when autocomplete is
     * disabled) are replaced rather than prefixed.
     */
    function markWebauthnField(el) {
        var tokens = (el.getAttribute('autocomplete') || '').split(/\s+/).filter(function (token) {
                return token && token !== 'off' && token !== 'on' && token !== 'webauthn';
            }),
            value;

        if (!tokens.length) {
            tokens.push('username');
        }
        value = tokens.concat('webauthn').join(' ');

        if (el.getAttribute('autocomplete') !== value) {
            el.setAttribute('autocomplete', value);
        }
    }

    return {
        /**
         * Default selectors for the sign-in email field.
         */
        EMAIL_SELECTORS: 'input#email, input[name="login[username]"]',

        /**
         * Check if WebAuthn API is present (requires secure context).
         */
        isAvailable: function () {
            return window.isSecureContext
                && typeof window.PublicKeyCredential !== 'undefined';
        },

        /**
         * Check if the browser can offer passkeys through the autofill
         * dropdown (WebAuthn conditional mediation). Resolves to a boolean.
         */
        isConditionalMediationAvailable: function () {
            if (!this.isAvailable()
                || typeof window.PublicKeyCredential.isConditionalMediationAvailable !== 'function'
            ) {
                return Promise.resolve(false);
            }

            return window.PublicKeyCredential.isConditionalMediationAvailable()
                .catch(function () {
                    return false;
                });
        },

        /**
         * POST a JSON body to a storefront endpoint and resolve with the JSON
         * reply. Rejects with the server's message (or fallbackMessage) when
         * the reply carries errors.
         */
        postJson: function (url, body, fallbackMessage) {
            return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(body),
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                if (data.errors) {
                    throw new Error(data.message || fallbackMessage || '');
                }

                return data;
            });
        },

        /**
         * Advertise passkey support to the browser's autofill on the email
         * fields. Fields rendered later (e.g. the checkout authentication
         * popup) are marked on first focus.
         */
        markWebauthnFields: function (selectors) {
            Array.prototype.forEach.call(document.querySelectorAll(selectors), markWebauthnField);

            if (webauthnFieldSelectors === null) {
                document.addEventListener('focusin', function (event) {
                    if (event.target.matches && event.target.matches(webauthnFieldSelectors)) {
                        markWebauthnField(event.target);
                    }
                });
            }
            webauthnFieldSelectors = selectors;
        },

        /**
         * Arm passkey autofill (WebAuthn conditional mediation): a pending
         * navigator.credentials.get() lets the browser offer saved passkeys in
         * the email field's autofill dropdown. No-op when unsupported.
         *
         * config: {optionsUrl, verifyUrl, selectors?, onError?, onSuccess?}
         * onSuccess defaults to reloading the page.
         */
        startConditional: function (config) {
            var self = this;

            if (!config.optionsUrl || !config.verifyUrl) {
                return Promise.resolve();
            }

            return this.isConditionalMediationAvailable().then(function (available) {
                if (!available) {
                    return;
                }
                conditional = {config: config, restartsLeft: 3, controller: null};
                self.markWebauthnFields(config.selectors || self.EMAIL_SELECTORS);
                self.restartConditional();
            });
        },

        /**
         * Cancel the pending autofill request. Required before a modal
         * ceremony: browsers allow only one active WebAuthn request.
         */
        abortConditional: function () {
            if (conditional && conditional.controller) {
                conditional.controller.abort();
                conditional.controller = null;
            }
        },

        /**
         * Re-arm autofill, e.g. after a modal ceremony failed. No-op unless
         * startConditional() armed it.
         */
        restartConditional: function () {
            var self = this,
                picked = false,
                controller;

            if (!conditional) {
                return;
            }

            this.abortConditional();
            controller = conditional.controller = new AbortController();

            this.postJson(conditional.config.optionsUrl, {}).then(function (options) {
                var request = self.prepareRequestOptions(options);

                // A pending autofill request should last as long as the page.
                delete request.publicKey.timeout;
                request.mediation = 'conditional';
                request.signal = controller.signal;

                return navigator.credentials.get(request).then(function (credential) {
                    if (!credential) {
                        throw new Error('cancelled');
                    }
                    picked = true;

                    return self.postJson(conditional.config.verifyUrl, {
                        challengeToken: options.challengeToken,
                        credential: self.serializeAssertionResponse(credential)
                    });
                });
            }).then(function () {
                if (typeof conditional.config.onSuccess === 'function') {
                    conditional.config.onSuccess();
                } else {
                    window.location.reload();
                }
            }).catch(function (err) {
                // Aborting is the expected path when the user signs in another
                // way or we hand off to a modal ceremony.
                if (err && err.name === 'AbortError') {
                    return;
                }

                // Re-arm so the autofill entry keeps working, with a cap. Only
                // report failures after the user picked a passkey (most often an
                // expired challenge on a long-idle tab); the browser ending the
                // request on its own is not something the user did.
                if (conditional.restartsLeft > 0) {
                    conditional.restartsLeft--;
                    if (picked && typeof conditional.config.onError === 'function') {
                        conditional.config.onError(err);
                    }
                    self.restartConditional();
                }
            });
        },

        /**
         * Whether the enrollment prompt is snoozed: dismissed within the last
         * 30 days, or dismissed too often to show again.
         */
        isEnrollmentSnoozed: function () {
            var dismissedAt, count;

            try {
                dismissedAt = parseInt(localStorage.getItem(ENROLLMENT_DISMISSED_AT_KEY), 10);
                count = parseInt(localStorage.getItem(ENROLLMENT_DISMISS_COUNT_KEY), 10) || 0;
            } catch (e) {
                return false;
            }

            if (count >= ENROLLMENT_MAX_DISMISSALS) {
                return true;
            }

            return !!dismissedAt && (Date.now() - dismissedAt) < ENROLLMENT_COOLDOWN_MS;
        },

        recordEnrollmentDismissal: function () {
            try {
                localStorage.setItem(ENROLLMENT_DISMISSED_AT_KEY, String(Date.now()));
                localStorage.setItem(
                    ENROLLMENT_DISMISS_COUNT_KEY,
                    String((parseInt(localStorage.getItem(ENROLLMENT_DISMISS_COUNT_KEY), 10) || 0) + 1)
                );
            } catch (e) {
                // Storage unavailable (private mode) — dismiss for this page only.
            }
        },

        /**
         * Suggest a default friendly name for a new passkey based on the
         * current browser/platform, e.g. "Chrome on Windows".
         */
        suggestName: function () {
            var ua = navigator.userAgent,
                browser = 'Browser',
                platform = '';

            if (/edg\//i.test(ua)) {
                browser = 'Edge';
            } else if (/opr\//i.test(ua)) {
                browser = 'Opera';
            } else if (/samsungbrowser/i.test(ua)) {
                browser = 'Samsung Internet';
            } else if (/chrome|crios/i.test(ua)) {
                browser = 'Chrome';
            } else if (/firefox|fxios/i.test(ua)) {
                browser = 'Firefox';
            } else if (/safari/i.test(ua)) {
                browser = 'Safari';
            }

            if (/windows/i.test(ua)) {
                platform = 'Windows';
            } else if (/iphone|ipad|ipod/i.test(ua)) {
                platform = 'iOS';
            } else if (/android/i.test(ua)) {
                platform = 'Android';
            } else if (/macintosh|mac os/i.test(ua)) {
                platform = 'macOS';
            } else if (/linux/i.test(ua)) {
                platform = 'Linux';
            }

            return platform ? browser + ' on ' + platform : browser;
        },

        /**
         * Convert a base64url-encoded string to an ArrayBuffer.
         */
        base64urlToBuffer: function (base64url) {
            var base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
            var padLen = (4 - base64.length % 4) % 4;
            base64 += '='.repeat(padLen);
            var binary = atob(base64);
            var bytes = new Uint8Array(binary.length);

            for (var i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }

            return bytes.buffer;
        },

        /**
         * Convert an ArrayBuffer to a base64url-encoded string.
         */
        bufferToBase64url: function (buffer) {
            var bytes = new Uint8Array(buffer);
            var binary = '';

            for (var i = 0; i < bytes.length; i++) {
                binary += String.fromCharCode(bytes[i]);
            }

            return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
        },

        /**
         * Prepare creation options received from server for navigator.credentials.create().
         */
        prepareCreationOptions: function (options) {
            var self = this;
            var publicKey = {
                challenge: this.base64urlToBuffer(options.challenge),
                rp: options.rp,
                user: Object.assign({}, options.user, {
                    id: this.base64urlToBuffer(options.user.id)
                }),
                pubKeyCredParams: options.pubKeyCredParams,
                authenticatorSelection: options.authenticatorSelection,
                attestation: options.attestation,
                timeout: options.timeout
            };

            if (options.excludeCredentials && options.excludeCredentials.length) {
                publicKey.excludeCredentials = options.excludeCredentials.map(function (cred) {
                    return Object.assign({}, cred, {
                        id: self.base64urlToBuffer(cred.id)
                    });
                });
            }

            return { publicKey: publicKey };
        },

        /**
         * Prepare request options received from server for navigator.credentials.get().
         */
        prepareRequestOptions: function (options) {
            var self = this;
            var publicKey = {
                challenge: this.base64urlToBuffer(options.challenge),
                rpId: options.rpId,
                userVerification: options.userVerification,
                timeout: options.timeout
            };

            if (options.allowCredentials && options.allowCredentials.length) {
                publicKey.allowCredentials = options.allowCredentials.map(function (cred) {
                    return Object.assign({}, cred, {
                        id: self.base64urlToBuffer(cred.id)
                    });
                });
            }

            return { publicKey: publicKey };
        },

        /**
         * Serialize an attestation (creation) response for sending to server.
         */
        serializeAttestationResponse: function (credential) {
            var response = credential.response;

            return {
                id: credential.id,
                rawId: this.bufferToBase64url(credential.rawId),
                type: credential.type,
                response: {
                    clientDataJSON: this.bufferToBase64url(response.clientDataJSON),
                    attestationObject: this.bufferToBase64url(response.attestationObject),
                    transports: response.getTransports ? response.getTransports() : []
                }
            };
        },

        /**
         * Serialize an assertion (authentication) response for sending to server.
         */
        serializeAssertionResponse: function (credential) {
            var response = credential.response;

            return {
                id: credential.id,
                rawId: this.bufferToBase64url(credential.rawId),
                type: credential.type,
                response: {
                    clientDataJSON: this.bufferToBase64url(response.clientDataJSON),
                    authenticatorData: this.bufferToBase64url(response.authenticatorData),
                    signature: this.bufferToBase64url(response.signature),
                    userHandle: response.userHandle
                        ? this.bufferToBase64url(response.userHandle)
                        : null
                }
            };
        }
    };
}));
