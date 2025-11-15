{*
* Sales Events Tab Template
*
* Displays real-time sales events (customers, orders, invoices, payments, coupons)
* with comprehensive filtering and sync status tracking. This is the main monitoring
* interface where users can see all detected sales changes and their sync status with Odoo.
*
* Adapted from odoo_direct_stock_sync events_tab.tpl
* - Changed: Stock events → Sales events (multiple entity types)
* - Added: Entity-specific columns and data display
* - Kept: Same filtering structure, action buttons, pagination
*}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-list"></i> {l s='Sales Events' mod='odoo_sales_sync'}
        {* Quick action buttons for common operations *}
        <span class="panel-heading-action">
            <a class="list-toolbar-btn" href="#" onclick="refreshEvents(); return false;" title="{l s='Refresh' mod='odoo_sales_sync'}">
                <i class="process-icon-refresh"></i>
            </a>
        </span>
    </div>

    {* Time-based and entity filtering *}
    <div class="well">
        <form id="events-filter-form" class="form-inline" method="get" action="">
            {* Preserve ALL existing URL parameters except the ones we're explicitly setting *}
            {foreach from=$smarty.get key=param item=value}
                {if $param != 'page' && $param != 'limit' && $param != 'tab' && $param != 'events_filter_minutes' && $param != 'events_filter_entity' && $param != 'events_filter_action' && $param != 'events_filter_status'}
                    <input type="hidden" name="{$param|escape:'html':'UTF-8'}" value="{$value|escape:'html':'UTF-8'}" />
                {/if}
            {/foreach}
            {* Ensure we keep the tab parameter *}
            <input type="hidden" name="tab" value="events" />
            <input type="hidden" id="events_page" name="page" value="1" />

            <div class="form-group">
                <label>{l s='Time range:' mod='odoo_sales_sync'}</label>
                <select name="events_filter_minutes" id="events_filter_minutes" class="form-control">
                    <option value="">{l s='All time' mod='odoo_sales_sync'}</option>
                    <option value="5" {if $filters.minutes == '5'}selected{/if}>{l s='Last 5 minutes' mod='odoo_sales_sync'}</option>
                    <option value="15" {if $filters.minutes == '15'}selected{/if}>{l s='Last 15 minutes' mod='odoo_sales_sync'}</option>
                    <option value="30" {if $filters.minutes == '30'}selected{/if}>{l s='Last 30 minutes' mod='odoo_sales_sync'}</option>
                    <option value="60" {if $filters.minutes == '60'}selected{/if}>{l s='Last hour' mod='odoo_sales_sync'}</option>
                    <option value="180" {if $filters.minutes == '180'}selected{/if}>{l s='Last 3 hours' mod='odoo_sales_sync'}</option>
                    <option value="360" {if $filters.minutes == '360'}selected{/if}>{l s='Last 6 hours' mod='odoo_sales_sync'}</option>
                    <option value="720" {if $filters.minutes == '720'}selected{/if}>{l s='Last 12 hours' mod='odoo_sales_sync'}</option>
                    <option value="1440" {if $filters.minutes == '1440'}selected{/if}>{l s='Last 24 hours' mod='odoo_sales_sync'}</option>
                    <option value="10080" {if $filters.minutes == '10080'}selected{/if}>{l s='Last 7 days' mod='odoo_sales_sync'}</option>
                </select>
            </div>

            {* Entity type filter - critical for sales events *}
            <div class="form-group">
                <label>{l s='Entity type:' mod='odoo_sales_sync'}</label>
                <select name="events_filter_entity" id="events_filter_entity" class="form-control">
                    <option value="">{l s='All types' mod='odoo_sales_sync'}</option>
                    <option value="customer" {if $filters.entity == 'customer'}selected{/if}>{l s='Customer' mod='odoo_sales_sync'}</option>
                    <option value="order" {if $filters.entity == 'order'}selected{/if}>{l s='Order' mod='odoo_sales_sync'}</option>
                    <option value="invoice" {if $filters.entity == 'invoice'}selected{/if}>{l s='Invoice' mod='odoo_sales_sync'}</option>
                    <option value="payment" {if $filters.entity == 'payment'}selected{/if}>{l s='Payment' mod='odoo_sales_sync'}</option>
                    <option value="coupon" {if $filters.entity == 'coupon'}selected{/if}>{l s='Coupon/Discount' mod='odoo_sales_sync'}</option>
                </select>
            </div>

            {* Action type filter *}
            <div class="form-group">
                <label>{l s='Action:' mod='odoo_sales_sync'}</label>
                <select name="events_filter_action" id="events_filter_action" class="form-control">
                    <option value="">{l s='All actions' mod='odoo_sales_sync'}</option>
                    <option value="created" {if $filters.action == 'created'}selected{/if}>{l s='Created' mod='odoo_sales_sync'}</option>
                    <option value="updated" {if $filters.action == 'updated'}selected{/if}>{l s='Updated' mod='odoo_sales_sync'}</option>
                    <option value="deleted" {if $filters.action == 'deleted'}selected{/if}>{l s='Deleted' mod='odoo_sales_sync'}</option>
                    <option value="status_changed" {if $filters.action == 'status_changed'}selected{/if}>{l s='Status Changed' mod='odoo_sales_sync'}</option>
                    <option value="applied" {if $filters.action == 'applied'}selected{/if}>{l s='Applied' mod='odoo_sales_sync'}</option>
                    <option value="removed" {if $filters.action == 'removed'}selected{/if}>{l s='Removed' mod='odoo_sales_sync'}</option>
                </select>
            </div>

            {* Sync status filter - critical for monitoring sync health *}
            <div class="form-group">
                <label>{l s='Sync status:' mod='odoo_sales_sync'}</label>
                <select name="events_filter_status" id="events_filter_status" class="form-control">
                    <option value="">{l s='All statuses' mod='odoo_sales_sync'}</option>
                    <option value="pending" {if $filters.status == 'pending'}selected{/if}>{l s='Pending' mod='odoo_sales_sync'}</option>
                    <option value="success" {if $filters.status == 'success'}selected{/if}>{l s='Sent Successfully' mod='odoo_sales_sync'}</option>
                    <option value="failed" {if $filters.status == 'failed'}selected{/if}>{l s='Failed' mod='odoo_sales_sync'}</option>
                </select>
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-default">
                    <i class="icon-search"></i> {l s='Filter' mod='odoo_sales_sync'}
                </button>
                <button type="button" class="btn btn-default" onclick="clearEventFilters();">
                    <i class="icon-eraser"></i> {l s='Clear' mod='odoo_sales_sync'}
                </button>
            </div>
        </form>
    </div>

    {* Events table with entity-specific columns *}
    <div class="table-responsive">
        <table class="table table-striped" id="events-table">
            <thead>
                <tr>
                    <th class="text-center" style="width: 40px;">
                        <input type="checkbox" id="events-select-all" />
                    </th>
                    <th style="width: 60px;">{l s='ID' mod='odoo_sales_sync'}</th>
                    <th style="width: 140px;">{l s='Date/Time' mod='odoo_sales_sync'}</th>
                    <th style="width: 100px;">{l s='Entity Type' mod='odoo_sales_sync'}</th>
                    <th>{l s='Entity' mod='odoo_sales_sync'}</th>
                    <th style="width: 100px;">{l s='Action' mod='odoo_sales_sync'}</th>
                    <th>{l s='Details' mod='odoo_sales_sync'}</th>
                    <th style="width: 120px;" class="text-center">{l s='Status' mod='odoo_sales_sync'}</th>
                    <th style="width: 120px;" class="text-right">{l s='Actions' mod='odoo_sales_sync'}</th>
                </tr>
            </thead>
            <tbody>
                {if $events}
                    {foreach from=$events item=event}
                        <tr data-event-id="{$event.id}" data-entity-type="{$event.entity_type}" class="event-row-{$event.sync_status}">
                            <td class="text-center">
                                <input type="checkbox" name="event_ids[]" value="{$event.id}" class="event-checkbox" />
                            </td>
                            <td>#{$event.id}</td>
                            <td>
                                <span title="{$event.date_add}">
                                    {$event.date_add|date_format:"%Y-%m-%d"}<br>
                                    <small class="text-muted">{$event.date_add|date_format:"%H:%M:%S"}</small>
                                </span>
                            </td>
                            <td>
                                {* Entity type badge *}
                                {if $event.entity_type == 'customer'}
                                    <span class="label label-primary"><i class="icon-user"></i> Customer</span>
                                {elseif $event.entity_type == 'order'}
                                    <span class="label label-info"><i class="icon-shopping-cart"></i> Order</span>
                                {elseif $event.entity_type == 'invoice'}
                                    <span class="label label-success"><i class="icon-file-text"></i> Invoice</span>
                                {elseif $event.entity_type == 'payment'}
                                    <span class="label label-warning"><i class="icon-credit-card"></i> Payment</span>
                                {elseif $event.entity_type == 'coupon'}
                                    <span class="label label-danger"><i class="icon-tag"></i> Coupon</span>
                                {else}
                                    <span class="label label-default">{$event.entity_type}</span>
                                {/if}
                            </td>
                            <td>
                                {* Entity-specific display *}
                                {if $event.entity_type == 'customer'}
                                    {* Customer: Name + Email *}
                                    {if isset($event.customer_data)}
                                        <a href="{$link->getAdminLink('AdminCustomers', true, [], ['id_customer' => $event.entity_id, 'viewcustomer' => 1])}" target="_blank">
                                            <strong>{$event.customer_data.firstname} {$event.customer_data.lastname}</strong>
                                        </a>
                                        <br><small class="text-muted">{$event.customer_data.email}</small>
                                    {else}
                                        {$event.entity_name}
                                    {/if}

                                {elseif $event.entity_type == 'order'}
                                    {* Order: Reference + Customer *}
                                    {if isset($event.order_data)}
                                        <a href="{$link->getAdminLink('AdminOrders', true, [], ['id_order' => $event.entity_id, 'vieworder' => 1])}" target="_blank">
                                            <strong>{$event.order_data.reference}</strong>
                                        </a>
                                        <br><small class="text-muted">
                                            {if isset($event.order_data.customer_name)}
                                                {$event.order_data.customer_name}
                                            {/if}
                                        </small>
                                    {else}
                                        {$event.entity_name}
                                    {/if}

                                {elseif $event.entity_type == 'invoice'}
                                    {* Invoice: Number + Order *}
                                    {if isset($event.invoice_data)}
                                        <strong>{$event.invoice_data.number}</strong>
                                        <br><small class="text-muted">
                                            {if isset($event.invoice_data.order_reference)}
                                                Order: {$event.invoice_data.order_reference}
                                            {/if}
                                        </small>
                                    {else}
                                        {$event.entity_name}
                                    {/if}

                                {elseif $event.entity_type == 'payment'}
                                    {* Payment: Method + Amount *}
                                    {if isset($event.payment_data)}
                                        <strong>{$event.payment_data.payment_method}</strong>
                                        <br><small class="text-muted">
                                            {if isset($event.payment_data.amount)}
                                                {displayPrice price=$event.payment_data.amount}
                                            {/if}
                                        </small>
                                    {else}
                                        {$event.entity_name}
                                    {/if}

                                {elseif $event.entity_type == 'coupon'}
                                    {* Coupon: Code + Discount *}
                                    {if isset($event.coupon_data)}
                                        <strong>{$event.coupon_data.code}</strong>
                                        <br><small class="text-muted">
                                            {if isset($event.coupon_data.reduction_amount) && $event.coupon_data.reduction_amount > 0}
                                                -{displayPrice price=$event.coupon_data.reduction_amount}
                                            {elseif isset($event.coupon_data.reduction_percent) && $event.coupon_data.reduction_percent > 0}
                                                -{$event.coupon_data.reduction_percent}%
                                            {/if}
                                        </small>
                                    {else}
                                        {$event.entity_name}
                                    {/if}

                                {else}
                                    {$event.entity_name}
                                {/if}
                            </td>
                            <td>
                                {* Action type badge *}
                                {if $event.action_type == 'created'}
                                    <span class="label label-success">{l s='Created' mod='odoo_sales_sync'}</span>
                                {elseif $event.action_type == 'updated'}
                                    <span class="label label-info">{l s='Updated' mod='odoo_sales_sync'}</span>
                                {elseif $event.action_type == 'deleted'}
                                    <span class="label label-danger">{l s='Deleted' mod='odoo_sales_sync'}</span>
                                {elseif $event.action_type == 'status_changed'}
                                    <span class="label label-warning">{l s='Status Changed' mod='odoo_sales_sync'}</span>
                                {elseif $event.action_type == 'applied'}
                                    <span class="label label-primary">{l s='Applied' mod='odoo_sales_sync'}</span>
                                {elseif $event.action_type == 'removed'}
                                    <span class="label label-default">{l s='Removed' mod='odoo_sales_sync'}</span>
                                {else}
                                    <span class="label label-default">{$event.action_type}</span>
                                {/if}
                            </td>
                            <td>
                                {* Entity-specific details *}
                                {if $event.entity_type == 'order' && isset($event.order_data)}
                                    {if isset($event.order_data.total_paid_tax_incl)}
                                        <strong>{displayPrice price=$event.order_data.total_paid_tax_incl}</strong><br>
                                    {/if}
                                    {if isset($event.order_data.current_state_name)}
                                        <small class="text-muted">{$event.order_data.current_state_name}</small>
                                    {/if}
                                {elseif $event.entity_type == 'invoice' && isset($event.invoice_data)}
                                    {if isset($event.invoice_data.total_paid_tax_incl)}
                                        <strong>{displayPrice price=$event.invoice_data.total_paid_tax_incl}</strong>
                                    {/if}
                                {elseif $event.entity_type == 'customer' && isset($event.customer_data)}
                                    <small class="text-muted">
                                        {if $event.customer_data.active}
                                            <i class="icon-check text-success"></i> Active
                                        {else}
                                            <i class="icon-times text-danger"></i> Inactive
                                        {/if}
                                    </small>
                                {else}
                                    <small class="text-muted">{$event.hook_name|truncate:30:'...'}</small>
                                {/if}
                            </td>
                            <td class="text-center">
                                {* Sync status with visual indicators *}
                                {if $event.sync_status == 'pending'}
                                    <span class="label label-warning">
                                        <i class="icon-clock-o"></i> {l s='Pending' mod='odoo_sales_sync'}
                                    </span>
                                {elseif $event.sync_status == 'sending'}
                                    <span class="label label-info">
                                        <i class="icon-refresh icon-spin"></i> {l s='Sending' mod='odoo_sales_sync'}
                                    </span>
                                {elseif $event.sync_status == 'success'}
                                    <span class="label label-success">
                                        <i class="icon-check"></i> {l s='Sent' mod='odoo_sales_sync'}
                                    </span>
                                {elseif $event.sync_status == 'failed'}
                                    <span class="label label-danger">
                                        <i class="icon-times"></i> {l s='Failed' mod='odoo_sales_sync'}
                                    </span>
                                    {if $event.sync_attempts}
                                        <br>
                                        <small class="text-muted">{l s='Attempts:' mod='odoo_sales_sync'} {$event.sync_attempts}</small>
                                    {/if}
                                {/if}
                            </td>
                            <td class="text-right">
                                {* Action buttons for each event *}
                                <div class="btn-group">
                                    <button class="btn btn-xs btn-default" onclick="viewEventDetails({$event.id}, '{$event.entity_type|escape:'javascript':'UTF-8'}');" title="{l s='View details' mod='odoo_sales_sync'}">
                                        <i class="icon-search"></i>
                                    </button>
                                    {if $event.sync_status == 'failed' || $event.sync_status == 'pending'}
                                        <button class="btn btn-xs btn-warning" onclick="retryEvent({$event.id});" title="{l s='Retry sync' mod='odoo_sales_sync'}">
                                            <i class="icon-refresh"></i>
                                        </button>
                                    {/if}
                                </div>
                            </td>
                        </tr>
                    {/foreach}
                {else}
                    <tr>
                        <td colspan="9" class="text-center">
                            <p class="alert alert-info">{l s='No events found matching your criteria' mod='odoo_sales_sync'}</p>
                        </td>
                    </tr>
                {/if}
            </tbody>
        </table>
    </div>

    {* Bulk actions toolbar *}
    {if $events}
        <div class="panel-footer">
            <div class="row">
                <div class="col-md-6">
                    <div class="btn-group dropup">
                        <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown">
                            {l s='Bulk actions' mod='odoo_sales_sync'} <span class="caret"></span>
                        </button>
                        <ul class="dropdown-menu">
                            <li><a href="#" onclick="bulkRetryEvents(); return false;">
                                <i class="icon-refresh"></i> {l s='Retry selected' mod='odoo_sales_sync'}
                            </a></li>
                            <li><a href="#" onclick="bulkMarkAsSent(); return false;">
                                <i class="icon-check"></i> {l s='Mark as sent' mod='odoo_sales_sync'}
                            </a></li>
                            <li class="divider"></li>
                            <li><a href="#" onclick="bulkExportEvents(); return false;">
                                <i class="icon-download"></i> {l s='Export selected' mod='odoo_sales_sync'}
                            </a></li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-6 text-right">
                    {* Pagination controls *}
                    {if $pagination.pages > 1}
                        <ul class="pagination">
                            <li {if $pagination.page <= 1}class="disabled"{/if}>
                                <a href="#" onclick="loadEventsPage({$pagination.page - 1}); return false;">&laquo;</a>
                            </li>
                            {for $p=1 to $pagination.pages}
                                {if $p <= 3 || $p > $pagination.pages - 3 || ($p >= $pagination.page - 1 && $p <= $pagination.page + 1)}
                                    <li {if $p == $pagination.page}class="active"{/if}>
                                        <a href="#" onclick="loadEventsPage({$p}); return false;">{$p}</a>
                                    </li>
                                {elseif $p == 4 || $p == $pagination.pages - 3}
                                    <li class="disabled"><a>...</a></li>
                                {/if}
                            {/for}
                            <li {if $pagination.page >= $pagination.pages}class="disabled"{/if}>
                                <a href="#" onclick="loadEventsPage({$pagination.page + 1}); return false;">&raquo;</a>
                            </li>
                        </ul>
                    {/if}

                    <div class="form-inline" style="display: inline-block;">
                        <select id="events_limit" class="form-control input-sm" onchange="changeEventsLimit();">
                            <option value="100" {if $pagination.limit == 100}selected{/if}>100</option>
                            <option value="300" {if $pagination.limit == 300}selected{/if}>300</option>
                            <option value="500" {if $pagination.limit == 500}selected{/if}>500</option>
                            <option value="1000" {if $pagination.limit == 1000}selected{/if}>1000</option>
                        </select>
                        <span>{l s='per page' mod='odoo_sales_sync'}</span>
                    </div>
                </div>
            </div>
        </div>
    {/if}
</div>

{* Event detail modal *}
<div class="modal fade" id="event-detail-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">{l s='Event Details' mod='odoo_sales_sync'}</h4>
            </div>
            <div class="modal-body" id="event-detail-content">
                {* Content loaded via AJAX *}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{l s='Close' mod='odoo_sales_sync'}</button>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
// Initialize event handlers when document ready
$(document).ready(function() {
    // Select all checkbox functionality
    $('#events-select-all').on('change', function() {
        $('.event-checkbox').prop('checked', $(this).prop('checked'));
    });

    // Update select all checkbox when individual checkboxes change
    $('.event-checkbox').on('change', function() {
        var allChecked = $('.event-checkbox:not(:checked)').length === 0;
        $('#events-select-all').prop('checked', allChecked);
    });
});

// Global functions for event management
function refreshEvents() {
    window.location.reload();
}

function clearEventFilters() {
    $('#events_filter_minutes').val('');
    $('#events_filter_entity').val('');
    $('#events_filter_action').val('');
    $('#events_filter_status').val('');
    $('#events_page').val(1);
    $('#events-filter-form').submit();
}

function loadEventsPage(page) {
    $('#events_page').val(page);
    $('#events-filter-form').submit();
}

function changeEventsLimit() {
    var limit = $('#events_limit').val();
    var url = window.location.href;

    // Update or add limit parameter
    if (url.indexOf('limit=') > -1) {
        url = url.replace(/limit=\d+/, 'limit=' + limit);
    } else {
        url += (url.indexOf('?') > -1 ? '&' : '?') + 'limit=' + limit;
    }

    // Reset to page 1
    if (url.indexOf('page=') > -1) {
        url = url.replace(/page=\d+/, 'page=1');
    }

    window.location.href = url;
}

function viewEventDetails(eventId, entityType) {
    // AJAX call to get event details
    $.ajax({
        url: window.location.href + '&ajax=1&action=viewEventDetails',
        type: 'GET',
        data: {
            event_id: eventId,
            entity_type: entityType
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#event-detail-content').html(response.html);
                $('#event-detail-modal').modal('show');
            } else {
                alert(response.message || 'Error loading event details');
            }
        },
        error: function() {
            alert('Error loading event details');
        }
    });
}

function retryEvent(eventId) {
    if (!confirm('Retry sync for this event?')) {
        return;
    }

    $.ajax({
        url: window.location.href + '&ajax=1&action=retryEvent',
        type: 'POST',
        data: { event_id: eventId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message || 'Event retry scheduled');
                window.location.reload();
            } else {
                alert(response.message || 'Error retrying event');
            }
        },
        error: function() {
            alert('Error retrying event');
        }
    });
}

function bulkRetryEvents() {
    var selectedIds = [];
    $('.event-checkbox:checked').each(function() {
        selectedIds.push($(this).val());
    });

    if (selectedIds.length === 0) {
        alert('No events selected');
        return;
    }

    if (!confirm('Retry sync for ' + selectedIds.length + ' selected events?')) {
        return;
    }

    $.ajax({
        url: window.location.href + '&ajax=1&action=bulkRetryEvents',
        type: 'POST',
        data: { event_ids: selectedIds },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message || 'Events retry scheduled');
                window.location.reload();
            } else {
                alert(response.message || 'Error retrying events');
            }
        },
        error: function() {
            alert('Error retrying events');
        }
    });
}

function bulkMarkAsSent() {
    var selectedIds = [];
    $('.event-checkbox:checked').each(function() {
        selectedIds.push($(this).val());
    });

    if (selectedIds.length === 0) {
        alert('No events selected');
        return;
    }

    if (!confirm('Mark ' + selectedIds.length + ' selected events as sent?')) {
        return;
    }

    $.ajax({
        url: window.location.href + '&ajax=1&action=bulkMarkAsSent',
        type: 'POST',
        data: { event_ids: selectedIds },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message || 'Events marked as sent');
                window.location.reload();
            } else {
                alert(response.message || 'Error marking events as sent');
            }
        },
        error: function() {
            alert('Error marking events as sent');
        }
    });
}

function bulkExportEvents() {
    var selectedIds = [];
    $('.event-checkbox:checked').each(function() {
        selectedIds.push($(this).val());
    });

    if (selectedIds.length === 0) {
        alert('No events selected');
        return;
    }

    // Build export URL with selected IDs
    var exportUrl = window.location.href + '&ajax=1&action=exportEvents';
    var queryString = $.param({ event_ids: selectedIds });
    window.location.href = exportUrl + '&' + queryString;
}
</script>
