(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else {
        root.passkeyCore = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    return {
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
