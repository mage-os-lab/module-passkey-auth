define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'MageOS_PasskeyAuth/js/passkey-core',
    'jquery-ui-modules/widget'
], function ($, customerData, passkeyCore) {
    'use strict';

    $.widget('mageOS.enrollmentPrompt', {
        _create: function () {
            this.element.hide();
            this._bindEvents();
            this._subscribeToSection();
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
                && !passkeyCore.isEnrollmentSnoozed()
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
            passkeyCore.recordEnrollmentDismissal();
            this.element.fadeOut(300);
        }
    });

    return $.mageOS.enrollmentPrompt;
});
