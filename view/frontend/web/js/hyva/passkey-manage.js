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

    Alpine.data('passkeyManage', () => ({
        message: '',
        messageType: '',
        busy: false,
        rowCount: 0,

        get hasMessage() { return this.message !== ''; },
        get hasRows() { return this.rowCount > 0; },
        get isEmpty() { return this.rowCount === 0; },
        get messageClasses() {
            if (this.messageType === 'error') return 'bg-red-100 text-red-700';
            if (this.messageType === 'success') return 'bg-green-100 text-green-700';
            return 'bg-blue-100 text-blue-700';
        },

        init() {
            this.registrationOptionsUrl = this.$el.dataset.registrationOptionsUrl;
            this.registrationVerifyUrl = this.$el.dataset.registrationVerifyUrl;
            this.rowCount = this.$el.querySelectorAll('[data-entity-id]').length;
        },

        handleMessage(event) {
            this.message = event.detail.text;
            this.messageType = event.detail.type;
        },

        rowDeleted() {
            this.rowCount = Math.max(0, this.rowCount - 1);
        },

        async register() {
            if (this.busy) {
                return;
            }
            if (!passkeyCore.isAvailable()) {
                this.message = window.isSecureContext
                    ? 'Your browser does not support passkeys.'
                    : 'Passkeys require a secure (HTTPS) connection.';
                this.messageType = 'error';
                return;
            }

            const answer = prompt(
                'Give this passkey a name so you can recognize it later:',
                passkeyCore.suggestName()
            );
            if (answer === null) {
                return;
            }
            const friendlyName = answer.trim() || null;
            if (friendlyName && (friendlyName.length > 255 || /[<>&]/.test(friendlyName))) {
                this.message = friendlyName.length > 255
                    ? 'Passkey name must be 255 characters or fewer.'
                    : 'Passkey names can\'t contain <, > or &.';
                this.messageType = 'error';
                return;
            }
            this.message = '';
            this.messageType = '';
            this.busy = true;

            try {
                const options = await passkeyCore.postJson(this.registrationOptionsUrl, {}, 'Registration failed.');
                const challengeToken = options.challengeToken;
                const creationOptions = passkeyCore.prepareCreationOptions(options);
                const credential = await navigator.credentials.create(creationOptions);
                const serialized = passkeyCore.serializeAttestationResponse(credential);

                await passkeyCore.postJson(this.registrationVerifyUrl, {
                    challengeToken: challengeToken,
                    credential: serialized,
                    friendlyName: friendlyName
                }, 'Registration failed.');

                this.message = 'Passkey registered successfully.';
                this.messageType = 'success';
                setTimeout(function () { window.location.reload(); }, 1000);
            } catch (err) {
                this.busy = false;
                if (err.name === 'NotAllowedError') {
                    this.message = 'Passkey registration was cancelled.';
                } else if (err.name === 'InvalidStateError') {
                    this.message = 'This device already has a passkey for your account. Try signing in with it instead.';
                } else {
                    this.message = err.message || 'Registration failed.';
                }
                this.messageType = 'error';
            }
        }
    }));

    Alpine.data('passkeyRow', () => ({
        editing: false,
        editName: '',

        get notEditing() { return !this.editing; },

        init() {
            this.entityId = parseInt(this.$el.dataset.entityId);
            this.friendlyName = this.$el.dataset.friendlyName || '';
            this.editName = this.friendlyName;
            this.deleteUrl = this.$el.closest('[data-delete-url]').dataset.deleteUrl;
            this.renameUrl = this.$el.closest('[data-rename-url]').dataset.renameUrl;
        },

        startRename() {
            this.editing = true;
            this.editName = this.friendlyName;
            this.$nextTick(() => {
                if (this.$refs.nameInput) {
                    this.$refs.nameInput.focus();
                    this.$refs.nameInput.select();
                }
            });
        },

        cancelRename() {
            this.editing = false;
        },

        updateEditName(event) {
            this.editName = event.target.value;
        },

        async saveRename() {
            const newName = this.editName.trim();
            this.editing = false;

            if (!newName) {
                return;
            }

            try {
                const response = await fetch(this.renameUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                    body: JSON.stringify({
                        entity_id: this.entityId,
                        friendly_name: newName
                    }),
                    credentials: 'same-origin'
                });
                const result = await response.json();

                if (result.errors) {
                    this.$dispatch('passkey-message', {text: result.message, type: 'error'});
                } else {
                    this.friendlyName = result.friendly_name || newName;
                    this.$refs.nameDisplay.textContent = this.friendlyName;
                }
            } catch (e) {
                this.$dispatch('passkey-message', {text: 'Failed to rename passkey.', type: 'error'});
            }
        },

        async deleteRow() {
            const name = this.friendlyName || 'this passkey';
            if (!confirm('Delete "' + name + '"? You will no longer be able to sign in with it.')) {
                return;
            }

            const formData = new URLSearchParams();
            formData.append('form_key', hyva.getFormKey());
            formData.append('entity_id', this.entityId);

            try {
                const response = await fetch(this.deleteUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: formData.toString(),
                    credentials: 'same-origin'
                });
                const result = await response.json();

                if (result.errors) {
                    this.$dispatch('passkey-message', {text: result.message, type: 'error'});
                } else {
                    // $el is the clicked button here; $root is the row.
                    const row = this.$root;

                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity = '0';
                    setTimeout(() => {
                        this.$dispatch('passkey-deleted');
                        row.remove();
                    }, 300);
                    this.$dispatch('passkey-message', {text: 'Passkey deleted.', type: 'success'});
                }
            } catch (e) {
                this.$dispatch('passkey-message', {text: 'Failed to delete passkey.', type: 'error'});
            }
        }
    }));

}));
