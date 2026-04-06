'use strict';

window.addEventListener('alpine:init', () => {
    Alpine.data('passkeyEnrollment', () => ({
        visible: false,

        receiveCustomerData(event) {
            const data = event.detail.data;

            if (data.passkey
                && data.passkey.show_enrollment_prompt
                && !sessionStorage.getItem('passkey_enrollment_dismissed')
            ) {
                this.visible = true;
            }
        },

        dismiss() {
            sessionStorage.setItem('passkey_enrollment_dismissed', '1');
            this.visible = false;
        }
    }));
}, {once: true});
