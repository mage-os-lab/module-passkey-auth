define([
    'jquery',
    'MageOS_PasskeyAuth/js/passkey-core',
    'mage/translate',
    'Magento_Ui/js/modal/confirm',
    'Magento_Ui/js/modal/prompt',
    'jquery-ui-modules/widget'
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
            this.element.on('click', '.action.cancel-rename', function (e) {
                this._endRename($(e.currentTarget).closest('tr'));
            }.bind(this));
        },

        /**
         * Mirror the server-side rule so a bad name is caught before the
         * browser creates a credential that could then not be saved.
         *
         * @returns {String|null} error message
         */
        _validateName: function (name) {
            if (name.length > 255) {
                return $t('Passkey name must be 255 characters or fewer.');
            }
            if (/[<>&]/.test(name)) {
                return $t('Passkey names can\'t contain <, > or &.');
            }

            return null;
        },

        /**
         * Server message from a failed $.ajax call, or the fallback.
         */
        _errorMessage: function (err, fallback) {
            return (err && err.responseJSON && err.responseJSON.message)
                || (err && err.message)
                || fallback;
        },

        _onRegister: function () {
            var self = this;

            if (!passkeyCore.isAvailable()) {
                this._showMessage(
                    window.isSecureContext
                        ? $t('Your browser does not support passkeys.')
                        : $t('Passkeys require a secure (HTTPS) connection.'),
                    'error'
                );
                return;
            }

            prompt({
                title: $t('Add a Passkey'),
                content: $t('Give this passkey a name so you can recognize it later:'),
                value: passkeyCore.suggestName(),
                actions: {
                    confirm: function (friendlyName) {
                        var name = $.trim(friendlyName || ''),
                            error = self._validateName(name);

                        if (error) {
                            self._showMessage(error, 'error');
                            return;
                        }
                        self._doRegistration(name || null);
                    }
                }
            });
        },

        _doRegistration: function (friendlyName) {
            var self = this;
            var $addButton = this.element.find('#passkey-add-btn');

            this._clearMessage();
            $addButton.prop('disabled', true).attr('aria-busy', 'true');

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
                    $addButton.prop('disabled', false).attr('aria-busy', 'false');
                } else {
                    self._showMessage($t('Passkey registered successfully.'), 'success');
                    setTimeout(function () { window.location.reload(); }, 1000);
                }
            }).catch(function (err) {
                if (err.name === 'NotAllowedError') {
                    self._showMessage($t('Passkey registration was cancelled.'), 'error');
                } else if (err.name === 'InvalidStateError') {
                    self._showMessage(
                        $t('This device already has a passkey for your account. Try signing in with it instead.'),
                        'error'
                    );
                } else {
                    self._showMessage(self._errorMessage(err, $t('Registration failed.')), 'error');
                }
                $addButton.prop('disabled', false).attr('aria-busy', 'false');
            });
        },

        _onDelete: function (e) {
            var self = this;
            var $row = $(e.currentTarget).closest('tr');
            var entityId = $row.data('entity-id');
            var name = $.trim($row.find('.name-display').text()) || $t('this passkey');

            confirm({
                title: $t('Delete Passkey'),
                content: $t('Delete "%1"? You will no longer be able to sign in with it.').replace('%1', name),
                buttons: [{
                    text: $t('Cancel'),
                    class: 'action-secondary action-dismiss',
                    click: function (event) {
                        this.closeModal(event);
                    }
                }, {
                    text: $t('Delete'),
                    class: 'action-primary action-accept',
                    click: function (event) {
                        this.closeModal(event, true);
                    }
                }],
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
                                $row.fadeOut(300, function () {
                                    var $wrapper = $row.closest('.table-wrapper');

                                    $row.remove();
                                    if (!$wrapper.find('tbody tr').length) {
                                        $wrapper.remove();
                                        self.element.find('.passkey-empty').show();
                                    }
                                });
                                self._showMessage($t('Passkey deleted.'), 'success');
                            }
                        }).catch(function (err) {
                            self._showMessage(self._errorMessage(err, $t('Failed to delete passkey.')), 'error');
                        });
                    }
                }
            });
        },

        _onRename: function (e) {
            var $row = $(e.currentTarget).closest('tr');

            if ($row.hasClass('renaming')) {
                this._saveRename($row);
            } else {
                this._startRename($row);
            }
        },

        _startRename: function ($row) {
            var self = this;
            var $display = $row.find('.name-display');
            var $edit = $row.find('.name-edit');

            this._clearMessage();
            $row.addClass('renaming');
            $row.find('.action.rename span').text($t('Save'));
            $row.find('.action.cancel-rename').show();
            $display.hide();
            $edit.show().trigger('focus').trigger('select');

            $edit.off('keydown.passkeyRename').on('keydown.passkeyRename', function (evt) {
                if (evt.key === 'Enter') {
                    evt.preventDefault();
                    self._saveRename($row);
                } else if (evt.key === 'Escape') {
                    self._endRename($row);
                }
            });
        },

        /**
         * Leave edit mode, keeping (or restoring) the displayed name.
         */
        _endRename: function ($row) {
            var $display = $row.find('.name-display');
            var $edit = $row.find('.name-edit');
            var saved = $edit.data('saved');

            $row.removeClass('renaming');
            $row.find('.action.rename span').text($t('Rename'));
            $row.find('.action.cancel-rename').hide();
            $edit.off('keydown.passkeyRename').hide().val(saved !== undefined ? saved : $edit.prop('defaultValue'));
            $display.show();
        },

        _saveRename: function ($row) {
            var self = this;
            var $edit = $row.find('.name-edit');
            var newName = $.trim($edit.val());
            var error = newName ? this._validateName(newName) : $t('Passkey name cannot be empty.');

            if (error) {
                this._showMessage(error, 'error');
                $edit.trigger('focus');
                return;
            }

            $.ajax({
                url: this.options.renameUrl,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    entity_id: $row.data('entity-id'),
                    friendly_name: newName
                }),
                dataType: 'json'
            }).then(function (result) {
                if (result.errors) {
                    self._showMessage(result.message, 'error');
                    return;
                }
                $row.find('.name-display').text(result.friendly_name || newName);
                $edit.data('saved', result.friendly_name || newName);
                self._endRename($row);
                self._showMessage($t('Passkey renamed.'), 'success');
            }).catch(function (err) {
                self._showMessage(self._errorMessage(err, $t('Failed to rename passkey.')), 'error');
            });
        },

        _showMessage: function (text, type) {
            this.$message
                .removeClass('error success info')
                .addClass(type)
                .find('div').text(text);
            this.$message.show();
        },

        _clearMessage: function () {
            this.$message.hide().find('div').text('');
        }
    });

    return $.mageOS.passkeyManage;
});
