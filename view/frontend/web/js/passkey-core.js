define([], function () {
    'use strict';

    return {
        /**
         * Check if WebAuthn is available in this browser.
         */
        isAvailable: function () {
            return typeof window.PublicKeyCredential !== 'undefined';
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
            var prepared = Object.assign({}, options);

            prepared.challenge = this.base64urlToBuffer(prepared.challenge);

            if (prepared.user && prepared.user.id) {
                prepared.user.id = this.base64urlToBuffer(prepared.user.id);
            }

            if (prepared.excludeCredentials) {
                var self = this;
                prepared.excludeCredentials = prepared.excludeCredentials.map(function (cred) {
                    return Object.assign({}, cred, {
                        id: self.base64urlToBuffer(cred.id)
                    });
                });
            }

            return { publicKey: prepared };
        },

        /**
         * Prepare request options received from server for navigator.credentials.get().
         */
        prepareRequestOptions: function (options) {
            var prepared = Object.assign({}, options);

            prepared.challenge = this.base64urlToBuffer(prepared.challenge);

            if (prepared.allowCredentials) {
                var self = this;
                prepared.allowCredentials = prepared.allowCredentials.map(function (cred) {
                    return Object.assign({}, cred, {
                        id: self.base64urlToBuffer(cred.id)
                    });
                });
            }

            return { publicKey: prepared };
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
});
