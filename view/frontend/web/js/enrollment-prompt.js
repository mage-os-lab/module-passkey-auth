define([
    'jquery',
    'jquery/ui'
], function ($) {
    'use strict';

    $.widget('mageOS.enrollmentPrompt', {
        _create: function () {
            if (sessionStorage.getItem('passkey_enrollment_dismissed')) {
                this.element.hide();
                return;
            }

            this.element.find('#passkey-enrollment-dismiss').on('click', this._onDismiss.bind(this));
        },

        _onDismiss: function () {
            sessionStorage.setItem('passkey_enrollment_dismissed', '1');
            this.element.fadeOut(300);
        }
    });

    return $.mageOS.enrollmentPrompt;
});
