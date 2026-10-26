define('custom:views/meeting/fields/smart-reminders', ['crm:views/meeting/fields/reminders'], function (Dep) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            this.addActionHandler('suggestSmartReminders', () => this.actionSuggestSmartReminders());
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (this.isEditMode()) {
                this.renderSmartReminderButton();
            }
        },

        renderSmartReminderButton: function () {
            if (this.$el.find('button[data-action="suggestSmartReminders"]').length > 0) {
                return;
            }

            const label = this.translate('suggestTiming', 'labels', 'SmartReminder') || 'Smart Timing';
            const $addBtn = this.$el.find('button[data-action="addReminder"]');

            const $suggestBtn = $('<button>')
                .attr('type', 'button')
                .attr('data-action', 'suggestSmartReminders')
                .addClass('btn btn-default')
                .attr('title', label)
                .css('margin-left', '6px')
                .html('<span class="fas fa-magic text-primary"></span> <span class="hidden-xs" style="margin-left: 4px;">' + label + '</span>');

            if ($addBtn.length) {
                $addBtn.after($suggestBtn);
            } else {
                this.$el.append($suggestBtn);
            }

            // Ensure container for dynamic feedback banner
            if (this.$el.find('.smart-reminder-feedback').length === 0) {
                const $feedback = $('<div>')
                    .addClass('smart-reminder-feedback text-muted')
                    .css({
                        'margin-top': '6px',
                        'font-size': '0.9em',
                        'display': 'none',
                    });

                this.$el.append($feedback);
            }
        },

        actionSuggestSmartReminders: function () {
            const dateVal = this.model.get(this.dateField);

            if (!dateVal || dateVal.trim() === '') {
                const warnMsg = this.translate('invalidDate', 'messages', 'SmartReminder') ||
                    'Please set a start date before requesting smart reminders.';
                Espo.Ui.warning(warnMsg);
                return;
            }

            const $btn = this.$el.find('button[data-action="suggestSmartReminders"]');
            const originalHtml = $btn.html();
            $btn.prop('disabled', true)
                .html('<span class="fas fa-spinner fa-spin text-primary"></span> <span class="hidden-xs" style="margin-left: 4px;">' +
                    (this.translate('loading', 'labels') || 'Analyzing...') + '</span>');

            const payload = {
                entityType: this.model.entityType,
                dateStart: dateVal,
                name: this.model.get('name') || '',
                description: this.model.get('description') || '',
                priority: this.model.get('priority') || 'Normal',
                useAi: true,
            };

            Espo.Ajax.postRequest('SmartReminder/action/suggestTiming', payload)
                .then(response => {
                    if (!response || !response.reminders || response.reminders.length === 0) {
                        Espo.Ui.info('No reminders recommended for the given timeframe.');
                        return;
                    }

                    // Replace current list with suggested timing
                    this.reminderList = Espo.Utils.cloneDeep(response.reminders);
                    this.model.set(this.name, Espo.Utils.cloneDeep(this.reminderList));

                    // Re-render items
                    this.$container.empty();
                    this.reminderList.forEach(item => {
                        this.addItemHtml(item);
                    });

                    this.showFeedbackBanner(response);
                    this.trigger('change');

                    const successMsg = this.translate('timingSuggested', 'messages', 'SmartReminder') ||
                        'Smart reminders applied.';
                    Espo.Ui.success(successMsg);
                })
                .catch(() => {
                    Espo.Ui.error('Could not compute smart reminders.');
                })
                .finally(() => {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        showFeedbackBanner: function (response) {
            const $feedback = this.$el.find('.smart-reminder-feedback');

            if (!$feedback.length) {
                return;
            }

            const sourceBadge = response.source === 'ai'
                ? '<span class="label label-info" style="margin-right: 5px;">AI</span>'
                : '<span class="label label-default" style="margin-right: 5px;">Rules</span>';

            const reasonText = Espo.Utils.escapeString(response.reason || '');

            $feedback.html(sourceBadge + ' ' + reasonText).fadeIn(200);
        },
    });
});
