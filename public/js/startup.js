opendxp.registerNS('pringuin.opendxphealth');

pringuin.opendxphealth = Class.create({

    reportWindow: null,

    initialize: function () {
        document.addEventListener(opendxp.events.preMenuBuild, this.preMenuBuild.bind(this));
    },

    preMenuBuild: function (e) {
        const menu = e.detail.menu;
        const user = opendxp.globalmanager.get('user');

        if (!menu.extras || !user.isAllowed('pringuin_health')) {
            return;
        }

        menu.extras.items.push({
            text: t('pringuin_health_report'),
            iconCls: 'opendxp_nav_icon_info',
            itemId: 'opendxp_menu_extras_pringuin_health',
            handler: this.openReportWindow.bind(this)
        });
    },

    openReportWindow: function () {
        if (this.reportWindow) {
            this.reportWindow.show();
            this.reportWindow.toFront();
            return;
        }

        this.reportPanel = new Ext.Panel({
            bodyCls: 'pringuin-health-report',
            autoScroll: true,
            html: ''
        });

        this.reportWindow = new Ext.Window({
            title: t('pringuin_health_report'),
            width: 800,
            height: 600,
            layout: 'fit',
            closeAction: 'destroy',
            items: [this.reportPanel],
            tbar: [
                {
                    text: t('pringuin_health_run_now'),
                    iconCls: 'opendxp_icon_apply',
                    handler: this.runChecks.bind(this)
                },
                {
                    text: t('pringuin_health_send_mail'),
                    iconCls: 'opendxp_icon_email',
                    handler: this.sendMail.bind(this)
                },
                '->',
                {
                    text: t('refresh'),
                    iconCls: 'opendxp_icon_reload',
                    handler: this.loadReport.bind(this)
                }
            ],
            listeners: {
                destroy: function () {
                    this.reportWindow = null;
                    this.reportPanel = null;
                }.bind(this)
            }
        });

        this.reportWindow.show();
        this.loadReport();
    },

    loadReport: function () {
        Ext.Ajax.request({
            url: Routing.generate('pringuin_opendxp_health_report'),
            method: 'GET',
            success: function (response) {
                const data = Ext.decode(response.responseText);
                this.renderReport(data.report);
            }.bind(this),
            failure: this.onRequestFailure.bind(this)
        });
    },

    runChecks: function () {
        this.reportPanel.setLoading(t('pringuin_health_running'));

        Ext.Ajax.request({
            url: Routing.generate('pringuin_opendxp_health_run'),
            method: 'POST',
            // composer audit talks to the network and can take a while
            timeout: 300000,
            success: function (response) {
                this.reportPanel.setLoading(false);
                const data = Ext.decode(response.responseText);
                this.renderReport(data.report);
            }.bind(this),
            failure: function (response) {
                if (this.reportPanel) {
                    this.reportPanel.setLoading(false);
                }
                // 409: another run (admin UI or cron) is still in progress
                if (response.status === 409) {
                    const data = Ext.decode(response.responseText, true) || {};
                    opendxp.helpers.showNotification(
                        t('error'),
                        Ext.util.Format.htmlEncode(data.message || t('pringuin_health_request_failed')),
                        'error'
                    );

                    return;
                }
                this.onRequestFailure(response);
            }.bind(this)
        });
    },

    sendMail: function () {
        Ext.Ajax.request({
            url: Routing.generate('pringuin_opendxp_health_send_mail'),
            method: 'POST',
            success: function (response) {
                const data = Ext.decode(response.responseText);
                // showNotification renders HTML; the message can carry mail transport error output
                const encode = Ext.util.Format.htmlEncode;
                if (data.success) {
                    opendxp.helpers.showNotification(
                        t('success'),
                        t('pringuin_health_mail_sent') + ': ' + encode(data.recipient),
                        'success'
                    );
                } else {
                    opendxp.helpers.showNotification(t('error'), encode(data.message), 'error');
                }
            },
            failure: this.onRequestFailure.bind(this)
        });
    },

    onRequestFailure: function (response) {
        opendxp.helpers.showNotification(
            t('error'),
            t('pringuin_health_request_failed') + ' (HTTP ' + response.status + ')',
            'error'
        );
    },

    renderReport: function (report) {
        if (!this.reportPanel) {
            return;
        }

        if (!report) {
            this.reportPanel.update(
                '<div class="pringuin-health-empty">' + t('pringuin_health_no_report') + '</div>'
            );
            return;
        }

        const encode = Ext.util.Format.htmlEncode;
        const createdAt = new Date(report.createdAt);

        let html = '<div class="pringuin-health-header pringuin-health-status-' + encode(report.overallStatus) + '">'
            + '<span class="pringuin-health-badge">' + encode(report.overallStatus.toUpperCase()) + '</span>'
            + '<span class="pringuin-health-date">' + t('pringuin_health_last_run') + ': '
            + encode(createdAt.toLocaleString()) + '</span>'
            + '</div>';

        report.results.forEach(function (result) {
            html += '<div class="pringuin-health-check pringuin-health-status-' + encode(result.status) + '">'
                + '<div class="pringuin-health-check-title">'
                + '<span class="pringuin-health-badge">' + encode(result.status.toUpperCase()) + '</span> '
                + encode(result.checkLabel)
                + '</div>'
                + '<div class="pringuin-health-check-summary">' + encode(result.summary) + '</div>';

            if (result.details && result.details.length > 0) {
                html += '<ul class="pringuin-health-check-details">';
                result.details.forEach(function (detail) {
                    html += '<li>' + encode(detail) + '</li>';
                });
                html += '</ul>';
            }

            html += '</div>';
        });

        this.reportPanel.update(html);
    }
});

opendxp.globalmanager.add('pringuin_opendxphealth', new pringuin.opendxphealth());
