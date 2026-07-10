define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'MageOS_PasskeyAuth/js/passkey-core',
    'jquery/ui'
], function ($, customerData, passkeyCore) {
    'use strict';

    var DISMISS_KEY = 'passkey_enrollment_dismissed_at',
        DISMISS_COUNT_KEY = 'passkey_enrollment_dismiss_count',
        COOLDOWN_DAYS = 30,
        MAX_DISMISSALS = 3;

    $.widget('mageOS.enrollmentPrompt', {
        _create: function () {
            this.element.hide();
            this._bindEvents();
            this._subscribeToSection();
        },

        _isSnoozed: function () {
            var dismissedAt, count;

            try {
                dismissedAt = parseInt(localStorage.getItem(DISMISS_KEY), 10);
                count = parseInt(localStorage.getItem(DISMISS_COUNT_KEY), 10) || 0;
            } catch (e) {
                return false;
            }

            if (count >= MAX_DISMISSALS) {
                return true;
            }

            return !!dismissedAt
                && (Date.now() - dismissedAt) < COOLDOWN_DAYS * 86400000;
        },

        _subscribeToSection: function () {
            var self = this;
            var passkeySection = customerData.get('passkey');

            passkeySection.subscribe(function (data) {
                self._handleSectionUpdate(data);
            });

            // Check initial data
            this._handleSectionUpdate(passkeySection());
        },

        _handleSectionUpdate: function (data) {
            if (data && data.show_enrollment_prompt
                && passkeyCore.isAvailable()
                && !this._isSnoozed()
            ) {
                this.element.show();
            } else {
                this.element.hide();
            }
        },

        _bindEvents: function () {
            this.element.find('#passkey-enrollment-dismiss').on('click', this._onDismiss.bind(this));
        },

        _onDismiss: function () {
            try {
                localStorage.setItem(DISMISS_KEY, String(Date.now()));
                localStorage.setItem(
                    DISMISS_COUNT_KEY,
                    String((parseInt(localStorage.getItem(DISMISS_COUNT_KEY), 10) || 0) + 1)
                );
            } catch (e) {
                // Storage unavailable (private mode) — dismiss for this page only.
            }
            this.element.fadeOut(300);
        }
    });

    return $.mageOS.enrollmentPrompt;
});
