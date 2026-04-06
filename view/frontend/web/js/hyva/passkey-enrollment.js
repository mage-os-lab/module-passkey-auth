'use strict';

function passkeyEnrollment(config) {
    return {
        visible: false,

        receiveCustomerData(data) {
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
    };
}
