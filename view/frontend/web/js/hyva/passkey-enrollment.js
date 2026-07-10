window.addEventListener('alpine:init', () => {
    const DISMISS_KEY = 'passkey_enrollment_dismissed_at';
    const DISMISS_COUNT_KEY = 'passkey_enrollment_dismiss_count';
    const COOLDOWN_DAYS = 30;
    const MAX_DISMISSALS = 3;

    Alpine.data('passkeyEnrollment', () => ({
        visible: false,

        isSnoozed() {
            let dismissedAt, count;

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

        receiveCustomerData(event) {
            const data = event.detail.data;

            if (data.passkey
                && data.passkey.show_enrollment_prompt
                && passkeyCore.isAvailable()
                && !this.isSnoozed()
            ) {
                this.visible = true;
            }
        },

        dismiss() {
            try {
                localStorage.setItem(DISMISS_KEY, String(Date.now()));
                localStorage.setItem(
                    DISMISS_COUNT_KEY,
                    String((parseInt(localStorage.getItem(DISMISS_COUNT_KEY), 10) || 0) + 1)
                );
            } catch (e) {
                // Storage unavailable (private mode) — dismiss for this page only.
            }
            this.visible = false;
        }
    }));
}, {once: true});
