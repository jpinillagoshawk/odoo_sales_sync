{*
* System Logs Tab Template
* 
* Displays system and API logs with comprehensive filtering similar to odoo_stock_report.
* This tab is crucial for debugging sync issues and monitoring module performance.
* Logs are enriched with correlation IDs to track related events across the system.
*}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-file-text"></i> {l s='System Logs' mod='odoo_sales_sync'}
        <span class="panel-heading-action">
            <a class="list-toolbar-btn" href="#" onclick="refreshLogs(); return false;" title="{l s='Refresh' mod='odoo_sales_sync'}">
                <i class="process-icon-refresh"></i>
            </a>
        </span>
    </div>
    
    {* Debug info - temporary *}
    {if isset($smarty.get.logs_level) || isset($logs_filters.level)}
    <div class="alert alert-info">
        Debug: GET logs_level = {if isset($smarty.get.logs_level)}{$smarty.get.logs_level|escape:'html':'UTF-8'}{else}NOT SET{/if}<br>
        Filter level from controller = {if isset($logs_filters.level)}{$logs_filters.level|escape:'html':'UTF-8'}{else}NOT SET{/if}<br>
        All GET params: {$smarty.get|@json_encode|escape:'html':'UTF-8'}
    </div>
    {/if}
    
    {* Log filtering controls - matches odoo_stock_report functionality *}
    <div class="well">
        <form id="logs-filter-form" class="form-inline" method="get" action="">
            {* Preserve existing URL parameters except the ones we're explicitly setting *}
            {foreach from=$smarty.get key=param item=value}
                {if $param != 'logs_page' && $param != 'logs_per_page' && $param != 'tab' && $param != 'logs_level' && $param != 'logs_category' && $param != 'logs_date_from' && $param != 'logs_date_to' && $param != 'logs_search'}
                    <input type="hidden" name="{$param|escape:'html':'UTF-8'}" value="{$value|escape:'html':'UTF-8'}" />
                {/if}
            {/foreach}
            <input type="hidden" name="tab" value="logs" />
            <input type="hidden" id="logs_page" name="logs_page" value="1" />
            <div class="form-group">
                <label>{l s='Minimum Level:' mod='odoo_sales_sync'}</label>
                <select name="logs_level" id="logs_level" class="form-control" title="{l s='Show this level and all more severe levels' mod='odoo_sales_sync'}">
                    <option value="">{l s='All levels' mod='odoo_sales_sync'}</option>
                    <option value="debug" {if $logs_filters.level == 'debug'}selected{/if} class="text-muted">{l s='Debug and above' mod='odoo_sales_sync'}</option>
                    <option value="info" {if $logs_filters.level == 'info'}selected{/if} class="text-info">{l s='Info and above' mod='odoo_sales_sync'}</option>
                    <option value="warning" {if $logs_filters.level == 'warning'}selected{/if} class="text-warning">{l s='Warning and above' mod='odoo_sales_sync'}</option>
                    <option value="error" {if $logs_filters.level == 'error'}selected{/if} class="text-danger">{l s='Error and above' mod='odoo_sales_sync'}</option>
                    <option value="critical" {if $logs_filters.level == 'critical'}selected{/if} style="color: #d9534f; font-weight: bold;">{l s='Critical only' mod='odoo_sales_sync'}</option>
                </select>
            </div>
            
            {* Category filter - helps isolate specific module functions *}
            <div class="form-group">
                <label>{l s='Category:' mod='odoo_sales_sync'}</label>
                <select name="logs_category" id="logs_category" class="form-control">
                    <option value="">{l s='All categories' mod='odoo_sales_sync'}</option>
                    <option value="detection" {if $logs_filters.category == 'detection'}selected{/if}>{l s='Detection' mod='odoo_sales_sync'}</option>
                    <option value="api" {if $logs_filters.category == 'api'}selected{/if}>{l s='API' mod='odoo_sales_sync'}</option>
                    <option value="sync" {if $logs_filters.category == 'sync'}selected{/if}>{l s='Sync' mod='odoo_sales_sync'}</option>
                    <option value="system" {if $logs_filters.category == 'system'}selected{/if}>{l s='System' mod='odoo_sales_sync'}</option>
                    <option value="performance" {if $logs_filters.category == 'performance'}selected{/if}>{l s='Performance' mod='odoo_sales_sync'}</option>
                </select>
            </div>
            
            {* Date range filter *}
            <div class="form-group">
                <label>{l s='From:' mod='odoo_sales_sync'}</label>
                <input type="text" name="logs_date_from" id="logs_date_from" class="form-control datepicker" value="{$logs_filters.date_from|default:''}" />
            </div>
            
            <div class="form-group">
                <label>{l s='To:' mod='odoo_sales_sync'}</label>
                <input type="text" name="logs_date_to" id="logs_date_to" class="form-control datepicker" value="{$logs_filters.date_to|default:''}" />
            </div>
            
            {* Search in message and context *}
            <div class="form-group">
                <label>{l s='Search:' mod='odoo_sales_sync'}</label>
                <input type="text" name="logs_search" id="logs_search" class="form-control" placeholder="{l s='Search in logs...' mod='odoo_sales_sync'}" value="{$logs_filters.search|default:''}" />
            </div>
            
            
            <div class="form-group">
                <button type="submit" class="btn btn-default">
                    <i class="icon-search"></i> {l s='Filter' mod='odoo_sales_sync'}
                </button>
                <button type="button" class="btn btn-default" onclick="clearLogFilters();">
                    <i class="icon-eraser"></i> {l s='Clear' mod='odoo_sales_sync'}
                </button>
                <button type="button" class="btn btn-default" onclick="exportLogs();">
                    <i class="icon-download"></i> {l s='Export' mod='odoo_sales_sync'}
                </button>
                <button type="button" class="btn btn-default" onclick="showAllContext();">
                    <i class="icon-expand"></i> {l s='Show all context' mod='odoo_sales_sync'}
                </button>
            </div>
        </form>
    </div>
    
    {* Logs display with context viewer *}
    <div class="logs-container">
        {if $logs}
            {foreach from=$logs item=log}
                {* Determine log entry class based on level *}
                {if $log.level == 'error' || $log.level == 'critical'}
                    {assign var="log_class" value="log-entry-error"}
                {elseif $log.level == 'warning'}
                    {assign var="log_class" value="log-entry-warning"}
                {elseif $log.level == 'info'}
                    {assign var="log_class" value="log-entry-info"}
                {else}
                    {assign var="log_class" value="log-entry-debug"}
                {/if}
                
                <div class="log-entry {$log_class}" data-log-id="{$log.id_log}">
                    <div class="log-header">
                        {* Timestamp and level *}
                        <span class="log-timestamp">{$log.date_add}</span>
                        <span class="label label-{if $log.level == 'error' || $log.level == 'critical'}danger{elseif $log.level == 'warning'}warning{elseif $log.level == 'info'}info{else}default{/if}">
                            {$log.level|upper}
                        </span>
                        <span class="label label-primary">{$log.category}</span>
                        
                        {* Correlation ID for tracking related events *}
                        {if $log.correlation_id}
                            <span class="label label-default" title="{l s='Correlation ID' mod='odoo_sales_sync'}">
                                <i class="icon-link"></i> {$log.correlation_id|truncate:12:'...'}
                            </span>
                        {/if}
                        
                        {* Performance metrics *}
                        {if $log.execution_time > 0}
                            <span class="label label-info">
                                <i class="icon-clock-o"></i> {$log.execution_time|round:3}s
                            </span>
                        {/if}
                        
                        {if $log.memory_usage > 0}
                            <span class="label label-info">
                                <i class="icon-tachometer"></i> {($log.memory_usage / 1024 / 1024)|round:2} MB
                            </span>
                        {/if}
                    </div>
                    
                    <div class="log-message">
                        {$log.message|escape:'html':'UTF-8'}
                    </div>
                    
                    {* Context data viewer *}
                    {if $log.context}
                        <div class="log-context">
                            <a href="#" class="toggle-context" onclick="toggleLogContext({$log.id_log}); return false;">
                                <i class="icon-plus-square-o"></i> {l s='Show context' mod='odoo_sales_sync'}
                            </a>
                            <div class="context-data" id="context-{$log.id_log}" style="display: none;">
                                <pre class="json-viewer">{$log.context}</pre>
                            </div>
                        </div>
                    {/if}
                </div>
            {/foreach}
            
            {* Pagination *}
            <div class="panel-footer">
                <div class="row">
                    <div class="col-md-6">
                        <span class="badge">{l s='Total logs:' mod='odoo_sales_sync'} {$logs_pagination.total}</span>
                    </div>
                    <div class="col-md-6 text-right">
                        {if $logs_pagination.pages > 1}
                            <ul class="pagination">
                                <li {if $logs_pagination.page <= 1}class="disabled"{/if}>
                                    <a href="#" onclick="loadLogsPage({$logs_pagination.page - 1}); return false;">&laquo;</a>
                                </li>
                                {for $p=1 to $logs_pagination.pages}
                                    {if $p <= 3 || $p > $logs_pagination.pages - 3 || ($p >= $logs_pagination.page - 1 && $p <= $logs_pagination.page + 1)}
                                        <li {if $p == $logs_pagination.page}class="active"{/if}>
                                            <a href="#" onclick="loadLogsPage({$p}); return false;">{$p}</a>
                                        </li>
                                    {elseif $p == 4 || $p == $logs_pagination.pages - 3}
                                        <li class="disabled"><a>...</a></li>
                                    {/if}
                                {/for}
                                <li {if $logs_pagination.page >= $logs_pagination.pages}class="disabled"{/if}>
                                    <a href="#" onclick="loadLogsPage({$logs_pagination.page + 1}); return false;">&raquo;</a>
                                </li>
                            </ul>
                        {/if}
                        
                        <div class="form-inline" style="display: inline-block;">
                            <select name="logs_per_page" id="logs_per_page" class="form-control input-sm" onchange="changeLogsLimit(); return false;">
                                <option value="100" {if $logs_pagination.limit == 100}selected{/if}>100</option>
                                <option value="300" {if $logs_pagination.limit == 300}selected{/if}>300</option>
                                <option value="500" {if $logs_pagination.limit == 500}selected{/if}>500</option>
                                <option value="1000" {if $logs_pagination.limit == 1000}selected{/if}>1000</option>
                            </select>
                            <span>{l s='per page' mod='odoo_sales_sync'}</span>
                        </div>
                    </div>
                </div>
            </div>
        {else}
            <div class="alert alert-info">
                {l s='No logs found matching your criteria' mod='odoo_sales_sync'}
            </div>
        {/if}
    </div>
</div>

<style>
{* Log entry styles matching odoo_stock_report aesthetic *}
.logs-container {
    max-height: 800px;
    overflow-y: auto;
    background: #f8f8f8;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.log-entry {
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 3px;
    margin-bottom: 10px;
    padding: 10px;
    transition: all 0.2s ease;
}

.log-entry:hover {
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.log-entry-error {
    border-left: 4px solid #d9534f;
}

.log-entry-warning {
    border-left: 4px solid #f0ad4e;
}

.log-entry-info {
    border-left: 4px solid #5bc0de;
}

.log-entry-debug {
    border-left: 4px solid #999;
}

.log-header {
    margin-bottom: 5px;
}

.log-timestamp {
    color: #666;
    font-size: 0.9em;
    margin-right: 10px;
}

.log-message {
    font-family: 'Courier New', monospace;
    font-size: 0.95em;
    line-height: 1.4;
    color: #333;
    word-wrap: break-word;
}

.log-context {
    margin-top: 10px;
}

.toggle-context {
    color: #337ab7;
    text-decoration: none;
    font-size: 0.9em;
}

.toggle-context:hover {
    text-decoration: underline;
}

.context-data {
    margin-top: 10px;
    background: #f5f5f5;
    border: 1px solid #ddd;
    border-radius: 3px;
    padding: 10px;
}

.json-viewer {
    margin: 0;
    font-size: 0.85em;
    max-height: 300px;
    overflow-y: auto;
}
</style>

<script type="text/javascript">
$(document).ready(function() {
    // Initialize datepickers
    $('.datepicker').datepicker({
        dateFormat: 'yy-mm-dd'
    });
});

function refreshLogs() {
    window.location.reload();
}

function clearLogFilters() {
    $('#logs_level').val('');
    $('#logs_category').val('');
    $('#logs_date_from').val('');
    $('#logs_date_to').val('');
    $('#logs_search').val('');
    $('#logs_page').val(1);
    $('#logs-filter-form').submit();
}

function exportLogs() {
    // Get current form data
    var formData = $('#logs-filter-form').serializeArray();
    var params = {};
    $.each(formData, function(i, field) {
        params[field.name] = field.value;
    });
    
    // Build export URL
    var exportUrl = '{$export_url|escape:'javascript'}';
    var queryString = $.param(params);
    window.location.href = exportUrl + '&' + queryString;
}

function toggleLogContext(logId) {
    var $context = $('#context-' + logId);
    var $toggleLink = $context.prev('.toggle-context');
    var $icon = $toggleLink.find('i');
    
    if ($context.is(':visible')) {
        $context.hide();
        $icon.removeClass('icon-minus-square-o').addClass('icon-plus-square-o');
        $toggleLink.html('<i class="icon-plus-square-o"></i> {l s='Show context' mod='odoo_sales_sync' js=1}');
    } else {
        $context.show();
        $icon.removeClass('icon-plus-square-o').addClass('icon-minus-square-o');
        $toggleLink.html('<i class="icon-minus-square-o"></i> {l s='Hide context' mod='odoo_sales_sync' js=1}');
    }
}

function showAllContext() {
    $('.context-data').each(function() {
        if (!$(this).is(':visible')) {
            var logId = $(this).attr('id').replace('context-', '');
            toggleLogContext(logId);
        }
    });
}

function loadLogsPage(page) {
    $('#logs_page').val(page);
    $('#logs-filter-form').submit();
}
</script>