/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

// Hyvä starts Alpine deferred, after this script runs, so register
// when Alpine initialises, before it walks the page.
window.addEventListener('alpine:init', () => {
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
}, {once: true});
