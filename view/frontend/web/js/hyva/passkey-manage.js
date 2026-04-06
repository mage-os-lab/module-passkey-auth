'use strict';

window.addEventListener('alpine:init', () => {

    Alpine.data('passkeyManage', () => ({
        message: '',
        messageType: '',

        get hasMessage() { return this.message !== ''; },
        get messageClasses() {
            if (this.messageType === 'error') return 'bg-red-100 text-red-700';
            if (this.messageType === 'success') return 'bg-green-100 text-green-700';
            return 'bg-blue-100 text-blue-700';
        },

        init() {
            this.registrationOptionsUrl = this.$el.dataset.registrationOptionsUrl;
            this.registrationVerifyUrl = this.$el.dataset.registrationVerifyUrl;
        },

        handleMessage(event) {
            this.message = event.detail.text;
            this.messageType = event.detail.type;
        },

        async register() {
            if (!passkeyCore.isAvailable()) {
                this.message = window.isSecureContext
                    ? 'Your browser does not support passkeys.'
                    : 'Passkeys require a secure (HTTPS) connection.';
                this.messageType = 'error';
                return;
            }

            const friendlyName = prompt('Give this passkey a name (optional):') || null;
            this.message = '';
            this.messageType = '';

            try {
                const options = await this.postJson(this.registrationOptionsUrl, {});
                const challengeToken = options.challengeToken;
                const creationOptions = passkeyCore.prepareCreationOptions(options);
                const credential = await navigator.credentials.create(creationOptions);
                const serialized = passkeyCore.serializeAttestationResponse(credential);

                const result = await this.postJson(this.registrationVerifyUrl, {
                    challengeToken: challengeToken,
                    credential: serialized,
                    friendlyName: friendlyName
                });

                if (result.errors) {
                    this.message = result.message;
                    this.messageType = 'error';
                } else {
                    this.message = 'Passkey registered successfully.';
                    this.messageType = 'success';
                    setTimeout(function () { window.location.reload(); }, 1000);
                }
            } catch (err) {
                if (err.name === 'NotAllowedError') {
                    this.message = 'Passkey registration was cancelled.';
                } else {
                    this.message = err.message || 'Registration failed.';
                }
                this.messageType = 'error';
            }
        },

        async postJson(url, body) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(body),
                credentials: 'same-origin'
            });
            return response.json();
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
                    headers: {'Content-Type': 'application/json'},
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
            if (!confirm('Are you sure you want to delete this passkey?')) {
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
                    this.$el.style.transition = 'opacity 0.3s';
                    this.$el.style.opacity = '0';
                    setTimeout(() => this.$el.remove(), 300);
                    this.$dispatch('passkey-message', {text: 'Passkey deleted.', type: 'success'});
                }
            } catch (e) {
                this.$dispatch('passkey-message', {text: 'Failed to delete passkey.', type: 'error'});
            }
        }
    }));

}, {once: true});
