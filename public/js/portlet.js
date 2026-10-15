opendxp.registerNS('opendxp.layout.portlets.pringuinHealth');

/**
 * Dashboard portlet showing the last stored health report at a glance.
 * Reuses the same report route as the Tools > Health Report window and links
 * through to it for the full details.
 *
 * @private
 */
opendxp.layout.portlets.pringuinHealth = Class.create(opendxp.layout.portlets.abstract, {

    getType: function () {
        return 'opendxp.layout.portlets.pringuinHealth';
    },

    getName: function () {
        return t('pringuin_health_report');
    },

    getIcon: function () {
        return 'opendxp_nav_icon_info';
    },

    isAvailable: function () {
        const user = opendxp.globalmanager.get('user');

        return !!user && user.isAllowed('pringuin_health');
    },

    getLayout: function (portletId) {
        const config = this.getDefaultConfig();

        this.panel = new Ext.Panel({
            bodyCls: 'pringuin-health-portlet',
            autoScroll: true,
            html: '<div class="pringuin-health-empty">' + t('pringuin_health_running') + '</div>'
        });

        this.layout = Ext.create('Portal.view.Portlet', Object.assign(config, {
            title: this.getName(),
            iconCls: this.getIcon(),
            height: 275,
            layout: 'fit',
            items: [this.panel],
            tools: config.tools.concat([
                {
                    type: 'refresh',
                    tooltip: t('refresh'),
                    handler: this.loadReport.bind(this)
                },
                {
                    type: 'maximize',
                    tooltip: t('pringuin_health_report'),
                    handler: this.openFullReport.bind(this)
                }
            ])
        }));

        this.layout.portletId = portletId;
        this.loadReport();

        return this.layout;
    },

    loadReport: function () {
        Ext.Ajax.request({
            url: Routing.generate('pringuin_opendxp_health_report'),
            method: 'GET',
            success: function (response) {
                const data = Ext.decode(response.responseText);
                this.renderReport(data.report);
            }.bind(this),
            failure: function (response) {
                if (this.panel) {
                    this.panel.update(
                        '<div class="pringuin-health-empty">'
                        + t('pringuin_health_request_failed') + ' (HTTP ' + response.status + ')'
                        + '</div>'
                    );
                }
            }.bind(this)
        });
    },

    openFullReport: function () {
        const plugin = opendxp.globalmanager.get('pringuin_opendxphealth');
        if (plugin) {
            plugin.openReportWindow();
        }
    },

    renderReport: function (report) {
        if (!this.panel) {
            return;
        }

        if (!report) {
            this.panel.update(
                '<div class="pringuin-health-empty">' + t('pringuin_health_no_report') + '</div>'
            );

            return;
        }

        const encode = Ext.util.Format.htmlEncode;
        const createdAt = new Date(report.createdAt);

        let issueCount = 0;
        report.results.forEach(function (result) {
            if (result.status !== 'ok') {
                issueCount++;
            }
        });

        const summary = issueCount > 0
            ? issueCount + ' ' + t('pringuin_health_issues_found')
            : t('pringuin_health_all_ok');

        let html = '<div class="pringuin-health-header pringuin-health-status-' + encode(report.overallStatus) + '">'
            + '<span class="pringuin-health-badge">' + encode(report.overallStatus.toUpperCase()) + '</span>'
            + '<span class="pringuin-health-portlet-summary">' + encode(summary) + '</span>'
            + '<span class="pringuin-health-date">' + encode(createdAt.toLocaleString()) + '</span>'
            + '</div>';

        html += '<table class="pringuin-health-portlet-list">';
        report.results.forEach(function (result) {
            html += '<tr class="pringuin-health-status-' + encode(result.status) + '">'
                + '<td class="pringuin-health-portlet-badge">'
                + '<span class="pringuin-health-badge">' + encode(result.status.toUpperCase()) + '</span>'
                + '</td>'
                + '<td class="pringuin-health-portlet-label">'
                + encode(result.checkLabel)
                + (result.status !== 'ok'
                    ? '<div class="pringuin-health-portlet-detail">' + encode(result.summary) + '</div>'
                    : '')
                + '</td>'
                + '</tr>';
        });
        html += '</table>';

        this.panel.update(html);
    }

});
