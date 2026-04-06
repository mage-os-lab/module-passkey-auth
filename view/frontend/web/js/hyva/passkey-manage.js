'use strict';

function passkeyManage(config) {
    return {
        message: '',
        messageType: '',
        editingId: null,
        editName: '',

        async register() {
            if (!passkeyCore.isAvailable()) {
                this.showMessage(
                    window.isSecureContext
                        ? 'Your browser does not support passkeys.'
                        : 'Passkeys require a secure (HTTPS) connection.',
                    'error'
                );
                return;
            }

            const friendlyName = prompt('Give this passkey a name (optional):') || null;
            this.clearMessage();

            try {
                const options = await this.postJson(config.registrationOptionsUrl, {});
                const challengeToken = options.challengeToken;
                const creationOptions = passkeyCore.prepareCreationOptions(options);
                const credential = await navigator.credentials.create(creationOptions);
                const serialized = passkeyCore.serializeAttestationResponse(credential);

                const result = await this.postJson(config.registrationVerifyUrl, {
                    challengeToken: challengeToken,
                    credential: serialized,
                    friendlyName: friendlyName
                });

                if (result.errors) {
                    this.showMessage(result.message, 'error');
                } else {
                    this.showMessage('Passkey registered successfully.', 'success');
                    setTimeout(function () { window.location.reload(); }, 1000);
                }
            } catch (err) {
                if (err.name === 'NotAllowedError') {
                    this.showMessage('Passkey registration was cancelled.', 'error');
                } else {
                    this.showMessage(err.message || 'Registration failed.', 'error');
                }
            }
        },

        async deletePasskey(entityId, row) {
            if (!confirm('Are you sure you want to delete this passkey?')) {
                return;
            }

            try {
                const result = await this.postForm(config.deleteUrl, {entity_id: entityId});

                if (result.errors) {
                    this.showMessage(result.message, 'error');
                } else {
                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity = '0';
                    setTimeout(function () { row.remove(); }, 300);
                    this.showMessage('Passkey deleted.', 'success');
                }
            } catch (e) {
                this.showMessage('Failed to delete passkey.', 'error');
            }
        },

        startRename(entityId, currentName) {
            this.editingId = entityId;
            this.editName = currentName;
            this.$nextTick(() => {
                const input = this.$refs['nameInput' + entityId];
                if (input) {
                    input.focus();
                    input.select();
                }
            });
        },

        cancelRename() {
            this.editingId = null;
            this.editName = '';
        },

        async saveRename(entityId, displayEl) {
            const newName = this.editName.trim();
            this.editingId = null;

            if (!newName) {
                return;
            }

            try {
                const result = await this.postJson(config.renameUrl, {
                    entity_id: entityId,
                    friendly_name: newName
                });

                if (result.errors) {
                    this.showMessage(result.message, 'error');
                } else {
                    displayEl.textContent = result.friendly_name || newName;
                }
            } catch (e) {
                this.showMessage('Failed to rename passkey.', 'error');
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
        },

        async postForm(url, params) {
            const formData = new URLSearchParams();
            formData.append('form_key', hyva.getFormKey());
            for (const [key, value] of Object.entries(params)) {
                formData.append(key, value);
            }

            const response = await fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString(),
                credentials: 'same-origin'
            });
            return response.json();
        },

        showMessage(text, type) {
            this.message = text;
            this.messageType = type;
        },

        clearMessage() {
            this.message = '';
            this.messageType = '';
        }
    };
}
