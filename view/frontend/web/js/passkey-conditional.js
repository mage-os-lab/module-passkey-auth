define([
    'jquery',
    'MageOS_PasskeyAuth/js/passkey-core',
    'mage/translate'
], function ($, passkeyCore, $t) {
    'use strict';

    var controller = null,
        restartsLeft = 3;

    /**
     * Conditional-mediation (passkey autofill) driver.
     *
     * Starts a pending navigator.credentials.get() with mediation:'conditional'
     * so the browser offers saved passkeys directly in the email field's
     * autofill dropdown. Callable via x-magento-init, e.g.:
     *
     * { "*": { "MageOS_PasskeyAuth/js/passkey-conditional": { "optionsUrl": ... } } }
     */
    function conditionalLogin(config) {
        conditionalLogin.start(config);
    }

    conditionalLogin.isSupported = function () {
        return passkeyCore.isAvailable()
            && typeof window.AbortController !== 'undefined'
            && typeof window.PublicKeyCredential.isConditionalMediationAvailable === 'function';
    };

    /**
     * Cancel the pending conditional request (required before starting a
     * modal ceremony — browsers allow only one active WebAuthn request).
     */
    conditionalLogin.abort = function () {
        if (controller) {
            controller.abort();
            controller = null;
        }
    };

    /**
     * Advertise passkey support to the browser's autofill on all candidate
     * email/username fields. Safe to call repeatedly; fields rendered later
     * (e.g. the checkout authentication popup) are marked on first focus.
     */
    conditionalLogin.markFields = function (selectors) {
        var mark = function (el) {
            var current = el.getAttribute('autocomplete') || 'username';

            if (current.indexOf('webauthn') === -1) {
                el.setAttribute('autocomplete', current + ' webauthn');
            }
        };

        $(selectors).each(function () {
            mark(this);
        });
        $(document).off('focusin.passkeyConditional')
            .on('focusin.passkeyConditional', selectors, function () {
                mark(this);
            });
    };

    conditionalLogin.start = function (config) {
        var self = this;

        config = $.extend({
            optionsUrl: '',
            verifyUrl: '',
            emailSelectors: 'input#email, input[name="login[username]"]',
            onSuccess: null,
            onError: null
        }, config);

        if (!this.isSupported() || !config.optionsUrl || !config.verifyUrl) {
            return;
        }

        window.PublicKeyCredential.isConditionalMediationAvailable()
            .then(function (available) {
                if (!available) {
                    return;
                }
                self.markFields(config.emailSelectors);
                self.run(config);
            })
            .catch(function () {});
    };

    conditionalLogin.run = function (config) {
        var self = this;

        this.abort();
        controller = new AbortController();

        $.ajax({
            url: config.optionsUrl,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({}),
            dataType: 'json',
            global: false
        }).then(function (options) {
            if (options.errors) {
                return $.Deferred().reject(new Error(options.message || 'options')).promise();
            }

            var request = passkeyCore.prepareRequestOptions(options);

            request.mediation = 'conditional';
            request.signal = controller.signal;

            return $.Deferred(function (deferred) {
                navigator.credentials.get(request).then(function (credential) {
                    deferred.resolve({
                        challengeToken: options.challengeToken,
                        credential: credential
                    });
                }, function (err) {
                    deferred.reject(err);
                });
            }).promise();
        }).then(function (result) {
            if (!result || !result.credential) {
                return $.Deferred().reject(new Error('cancelled')).promise();
            }

            return $.ajax({
                url: config.verifyUrl,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    challengeToken: result.challengeToken,
                    credential: passkeyCore.serializeAssertionResponse(result.credential)
                }),
                dataType: 'json'
            });
        }).then(function (data) {
            if (data.errors) {
                return $.Deferred().reject(new Error(data.message || 'verify')).promise();
            }

            if (typeof config.onSuccess === 'function') {
                config.onSuccess();
            } else {
                window.location.reload();
            }
        }).fail(function (err) {
            // An aborted conditional request is the expected path whenever the
            // user logs in another way or we hand off to a modal ceremony.
            if (err && err.name === 'AbortError') {
                return;
            }

            // The user picked a passkey but verification failed (most often an
            // expired challenge on a long-idle tab): surface it and re-arm so
            // the autofill entry keeps working, with a cap to avoid loops.
            if (restartsLeft > 0) {
                restartsLeft--;

                if (typeof config.onError === 'function') {
                    config.onError($t('Passkey sign-in didn\'t complete. Please try again.'));
                }
                self.run(config);
            }
        });
    };

    return conditionalLogin;
});
