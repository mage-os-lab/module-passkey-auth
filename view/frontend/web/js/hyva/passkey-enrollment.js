/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

// Hyvä loads Alpine as a deferred module; register now if it is already
// there, otherwise when it initialises.
(function (register) {
    if (window.Alpine) {
        register();
    } else {
        window.addEventListener('alpine:init', register, {once: true});
    }
}(() => {
    Alpine.data('passkeyEnrollment', () => ({
        visible: false,

        receiveCustomerData(event) {
            const data = event.detail.data;

            if (data.passkey
                && data.passkey.show_enrollment_prompt
                && passkeyCore.isAvailable()
                && !passkeyCore.isEnrollmentSnoozed()
            ) {
                this.visible = true;
            }
        },

        dismiss() {
            passkeyCore.recordEnrollmentDismissal();
            this.visible = false;
        }
    }));
}));
