define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'jquery/ui'
], function ($, customerData) {
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
                && !sessionStorage.getItem('passkey_enrollment_dismissed')
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
            sessionStorage.setItem('passkey_enrollment_dismissed', '1');
            this.element.fadeOut(300);
        }
    });

    return $.mageOS.enrollmentPrompt;
});
