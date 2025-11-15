{*
* Main admin template with tabbed interface
*
* This template provides the main structure for the module's admin interface.
* It implements a Bootstrap-based tabbed layout matching odoo_direct_stock_sync pattern.
*
* @author Odoo Sales Sync Module
* @version 1.0.0
*}

<div class="odoo-sales-sync-panel">
    {* Module header *}
    <div class="module-header">
        <div class="row">
            <div class="col-md-8">
                <h2>
                    <i class="icon-sync" style="font-size: 24px; margin-right: 10px;"></i>
                    {l s='Odoo Sales Sync' mod='odoo_sales_sync'}
                    <span class="badge badge-info">{l s='v1.0.0' mod='odoo_sales_sync'}</span>
                </h2>
            </div>
            <div class="col-md-4 text-right">
                {* Real-time sync status indicator *}
                <div class="sync-status-indicator" id="sync-status">
                    <i class="icon-refresh icon-spin"></i> {l s='Loading status...' mod='odoo_sales_sync'}
                </div>
            </div>
        </div>
    </div>

    {* Bootstrap tabbed interface *}
    <div class="odoo-sync-tabs">
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation" class="{if $active_tab == 'configuration'}active{/if}">
                <a href="#configuration" aria-controls="configuration" role="tab" data-toggle="tab">
                    <i class="icon-cogs"></i> {l s='Configuration' mod='odoo_sales_sync'}
                </a>
            </li>
            <li role="presentation" class="{if $active_tab == 'events'}active{/if}">
                <a href="#events" aria-controls="events" role="tab" data-toggle="tab">
                    <i class="icon-list"></i> {l s='Sales Events' mod='odoo_sales_sync'}
                    {* Badge shows pending events count *}
                    <span class="badge badge-warning" id="pending-events-count" style="display: none;">0</span>
                </a>
            </li>
            <li role="presentation" class="{if $active_tab == 'failed'}active{/if}">
                <a href="#failed" aria-controls="failed" role="tab" data-toggle="tab">
                    <i class="icon-exclamation-triangle"></i> {l s='Failed Events' mod='odoo_sales_sync'}
                    {* Badge shows failed events count *}
                    <span class="badge badge-danger" id="failed-events-count" style="display: none;">0</span>
                </a>
            </li>
            <li role="presentation" class="{if $active_tab == 'logs'}active{/if}">
                <a href="#logs" aria-controls="logs" role="tab" data-toggle="tab">
                    <i class="icon-file-text"></i> {l s='System Logs' mod='odoo_sales_sync'}
                    {* Badge shows error count in last hour *}
                    <span class="badge badge-danger" id="error-logs-count" style="display: none;">0</span>
                </a>
            </li>
        </ul>

        {* Tab content panels *}
        <div class="tab-content">
            {* Configuration tab - API settings and sync parameters *}
            <div role="tabpanel" class="tab-pane {if $active_tab == 'configuration'}active{/if}" id="configuration">
                {$config_content nofilter}
            </div>

            {* Events tab - Real-time sales events with sync status *}
            <div role="tabpanel" class="tab-pane {if $active_tab == 'events'}active{/if}" id="events">
                {$events_content nofilter}
            </div>

            {* Failed Events tab - Events that failed to sync to Odoo *}
            <div role="tabpanel" class="tab-pane {if $active_tab == 'failed'}active{/if}" id="failed">
                {$failed_content nofilter}
            </div>

            {* Logs tab - System and API logs with filtering *}
            <div role="tabpanel" class="tab-pane {if $active_tab == 'logs'}active{/if}" id="logs">
                {$logs_content nofilter}
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
$(document).ready(function() {
    // Initialize tab persistence - remembers last active tab
    $('.odoo-sync-tabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        localStorage.setItem('odoo_sales_sync_active_tab', $(e.target).attr('href'));
    });

    // Restore last active tab
    var activeTab = localStorage.getItem('odoo_sales_sync_active_tab');
    if (activeTab) {
        $('.odoo-sync-tabs a[href="' + activeTab + '"]').tab('show');
    }

    // Initialize status updates
    updateSyncStatus();

    // Auto-refresh status every 30 seconds
    setInterval(updateSyncStatus, 30000);
});

/**
 * Update sync status indicator
 * Shows connection health and event counts
 */
function updateSyncStatus() {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            ajax: 1,
            action: 'getSyncStatus'
        },
        dataType: 'json',
        success: function(response) {
            var $status = $('#sync-status');

            if (response.success) {
                $status.html('<span class="label label-success"><i class="icon-check"></i> {l s='Connected' mod='odoo_sales_sync' js=1}</span>');

                // Update event count badges
                if (response.pending_events && response.pending_events > 0) {
                    $('#pending-events-count').text(response.pending_events).show();
                } else {
                    $('#pending-events-count').hide();
                }

                if (response.failed_events && response.failed_events > 0) {
                    $('#failed-events-count').text(response.failed_events).show();
                } else {
                    $('#failed-events-count').hide();
                }

                if (response.error_logs && response.error_logs > 0) {
                    $('#error-logs-count').text(response.error_logs).show();
                } else {
                    $('#error-logs-count').hide();
                }
            } else {
                $status.html('<span class="label label-danger"><i class="icon-times"></i> {l s='Disconnected' mod='odoo_sales_sync' js=1}</span>');
            }
        },
        error: function() {
            // Silent fail - don't update UI on error
        }
    });
}
</script>
