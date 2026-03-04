define([
    'jquery',
    'MageOS_PasskeyAuth/js/passkey-core',
    'mage/translate',
    'Magento_Ui/js/modal/confirm',
    'Magento_Ui/js/modal/prompt',
    'jquery/ui'
], function ($, passkeyCore, $t, confirm, prompt) {
    'use strict';

    $.widget('mageOS.passkeyManage', {
        options: {
            registrationOptionsUrl: '',
            registrationVerifyUrl: '',
            deleteUrl: '',
            renameUrl: ''
        },

        _create: function () {
            this.$message = this.element.find('#passkey-manage-message');
            this.element.find('#passkey-add-btn').on('click', this._onRegister.bind(this));
            this.element.on('click', '.action.delete', this._onDelete.bind(this));
            this.element.on('click', '.action.rename', this._onRename.bind(this));
        },

        _onRegister: function () {
            var self = this;

            if (!passkeyCore.isAvailable()) {
                this._showMessage($t('Your browser does not support passkeys.'), 'error');
                return;
            }

            prompt({
                title: $t('Register a Passkey'),
                content: $t('Give this passkey a name (optional):'),
                value: '',
                actions: {
                    confirm: function (friendlyName) {
                        self._doRegistration(friendlyName || null);
                    },
                    cancel: function () {
                        // User cancelled naming — proceed without name
                        self._doRegistration(null);
                    }
                }
            });
        },

        _doRegistration: function (friendlyName) {
            var self = this;

            this._clearMessage();

            $.ajax({
                url: this.options.registrationOptionsUrl,
                type: 'POST',
                contentType: 'application/json',
                data: '{}',
                dataType: 'json'
            }).then(function (options) {
                var challengeToken = options.challengeToken;
                var creationOptions = passkeyCore.prepareCreationOptions(options);

                return navigator.credentials.create(creationOptions).then(function (credential) {
                    var serialized = passkeyCore.serializeAttestationResponse(credential);

                    return $.ajax({
                        url: self.options.registrationVerifyUrl,
                        type: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            challengeToken: challengeToken,
                            credential: serialized,
                            friendlyName: friendlyName
                        }),
                        dataType: 'json'
                    });
                });
            }).then(function (result) {
                if (result.errors) {
                    self._showMessage(result.message, 'error');
                } else {
                    self._showMessage($t('Passkey registered successfully.'), 'success');
                    setTimeout(function () { window.location.reload(); }, 1000);
                }
            }).catch(function (err) {
                if (err.name === 'NotAllowedError') {
                    self._showMessage($t('Passkey registration was cancelled.'), 'error');
                } else {
                    self._showMessage(err.message || err.responseJSON?.message || $t('Registration failed.'), 'error');
                }
            });
        },

        _onDelete: function (e) {
            var self = this;
            var $row = $(e.currentTarget).closest('tr');
            var entityId = $row.data('entity-id');

            confirm({
                content: $t('Are you sure you want to delete this passkey?'),
                actions: {
                    confirm: function () {
                        $.ajax({
                            url: self.options.deleteUrl,
                            type: 'POST',
                            data: { entity_id: entityId },
                            dataType: 'json'
                        }).then(function (result) {
                            if (result.errors) {
                                self._showMessage(result.message, 'error');
                            } else {
                                $row.fadeOut(300, function () { $row.remove(); });
                                self._showMessage($t('Passkey deleted.'), 'success');
                            }
                        }).catch(function () {
                            self._showMessage($t('Failed to delete passkey.'), 'error');
                        });
                    }
                }
            });
        },

        _onRename: function (e) {
            var self = this;
            var $row = $(e.currentTarget).closest('tr');
            var $nameCell = $row.find('.passkey-name');
            var $display = $nameCell.find('.name-display');
            var $edit = $nameCell.find('.name-edit');
            var entityId = $row.data('entity-id');

            if ($edit.is(':visible')) {
                // Save
                var newName = $edit.val().trim();
                if (!newName) {
                    $edit.hide();
                    $display.show();
                    return;
                }

                $.ajax({
                    url: self.options.renameUrl,
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        entity_id: entityId,
                        friendly_name: newName
                    }),
                    dataType: 'json'
                }).then(function (result) {
                    if (result.errors) {
                        self._showMessage(result.message, 'error');
                    } else {
                        $display.text(result.friendly_name || newName);
                    }
                    $edit.hide();
                    $display.show();
                }).catch(function () {
                    self._showMessage($t('Failed to rename passkey.'), 'error');
                    $edit.hide();
                    $display.show();
                });
            } else {
                // Enter edit mode
                $display.hide();
                $edit.show().focus().select();

                $edit.one('keydown', function (evt) {
                    if (evt.key === 'Enter') {
                        $(e.currentTarget).trigger('click');
                    } else if (evt.key === 'Escape') {
                        $edit.hide();
                        $display.show();
                    }
                });
            }
        },

        _showMessage: function (text, type) {
            this.$message
                .text(text)
                .removeClass('message-success message-error')
                .addClass('message-' + type)
                .show();
        },

        _clearMessage: function () {
            this.$message.hide().text('');
        }
    });

    return $.mageOS.passkeyManage;
});
