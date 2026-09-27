/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

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
        // Re-issue the autofill challenge before the server's 5-minute TTL runs out
        CONDITIONAL_REFRESH_MS = 4 * 60000,
        conditional = null,
        webauthnFieldSelectors = null,
        translations = null;

    /**
     * Phrases rendered by the Hyvä template hyva/scripts.phtml. Luma
     * translates with mage/translate instead, so it has none.
     */
    function readTranslations() {
        var el = document.getElementById('mageos-passkey-i18n');

        try {
            return el ? JSON.parse(el.textContent) || {} : {};
        } catch (e) {
            return {};
        }
    }

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
         * Translate a message on Hyvä, where mage/translate isn't loaded.
         * Falls back to the English text. %1, %2... are replaced by the
         * extra arguments.
         */
        t: function (text) {
            var args = arguments,
                phrase;

            if (translations === null) {
                translations = readTranslations();
            }
            phrase = typeof translations[text] === 'string' ? translations[text] : text;

            return phrase.replace(/%(\d+)/g, function (match, index) {
                return args[index] !== undefined ? String(args[index]) : match;
            });
        },

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
         * the reply carries errors; the error's status is the HTTP status.
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
                return response.json().then(function (data) {
                    var error;

                    if (data.errors) {
                        error = new Error(data.message || fallbackMessage || '');
                        error.status = response.status;
                        throw error;
                    }

                    return data;
                });
            });
        },

        /**
         * Whether a failed sign-in's server message should be shown as is:
         * 429, too many failed attempts (the customer must wait), or 403, the
         * passkey was verified but the account can't sign in (locked, not
         * confirmed). Other failures get a generic message.
         */
        hasCustomerMessage: function (error) {
            return !!error && (error.status === 429 || error.status === 403);
        },

        /**
         * After a successful sign-in, go to the verify reply's redirect_url
         * (the page password sign-in would land on), or reload.
         */
        completeSignIn: function (result) {
            var url = result && typeof result.redirect_url === 'string' ? result.redirect_url : '',
                here = window.location.href.split('#')[0],
                target = null;

            if (url) {
                try {
                    target = new URL(url, here);
                } catch (e) {
                    target = null;
                }
            }

            // Same page (or only a new #fragment): assign() would not reload.
            if (target
                && (target.protocol === 'https:' || target.protocol === 'http:')
                && target.href.split('#')[0] !== here
            ) {
                window.location.assign(target.href);
            } else {
                window.location.reload();
            }
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
         * onSuccess receives the verify reply; it defaults to completeSignIn().
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
            if (conditional) {
                clearTimeout(conditional.refreshTimer);
                if (conditional.controller) {
                    conditional.controller.abort();
                    conditional.controller = null;
                }
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

                // The browser keeps the request open for as long as the page;
                // swap in a fresh challenge before the server expires this one.
                delete request.publicKey.timeout;
                conditional.refreshTimer = setTimeout(function () {
                    self.refreshConditional(controller);
                }, CONDITIONAL_REFRESH_MS);
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
            }).then(function (result) {
                if (typeof conditional.config.onSuccess === 'function') {
                    conditional.config.onSuccess(result);
                } else {
                    self.completeSignIn(result);
                }
            }).catch(function (err) {
                // Aborting is the expected path when the user signs in another
                // way or we hand off to a modal ceremony.
                // Aborted, or superseded by a newer request that must not be
                // cancelled by this one's late failure.
                if ((err && err.name === 'AbortError') || controller !== conditional.controller) {
                    return;
                }

                // Only report failures after the user picked a passkey; the
                // browser ending the request on its own is not something the
                // user did. Re-arm so the autofill entry keeps working, with a cap.
                if (picked && typeof conditional.config.onError === 'function') {
                    conditional.config.onError(err);
                }
                if (conditional.restartsLeft > 0) {
                    conditional.restartsLeft--;
                    self.restartConditional();
                }
            });
        },

        /**
         * Timer callback: re-arm with a fresh challenge. A hidden tab waits until
         * it is shown again rather than polling the server in the background.
         * Does not count against the failure restarts.
         */
        refreshConditional: function (controller) {
            var self = this;

            if (!conditional || controller !== conditional.controller) {
                return;
            }
            if (document.hidden) {
                document.addEventListener('visibilitychange', function onVisible() {
                    if (!document.hidden) {
                        document.removeEventListener('visibilitychange', onVisible);
                        self.refreshConditional(controller);
                    }
                });
                return;
            }
            this.restartConditional();
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
                browser = this.t('Browser'),
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

            return platform ? this.t('%1 on %2', browser, platform) : browser;
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
