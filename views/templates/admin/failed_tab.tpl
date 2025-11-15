{*
* Failed Events tab template
*
* @author Odoo Sales Sync Module
* @version 1.0.0
*}

<div class="panel-body">
    <div class="row">
        <div class="col-md-8">
            <h3>{l s='Failed Events' mod='odoo_sales_sync'}</h3>
            <p>{l s='Events that failed to sync with Odoo' mod='odoo_sales_sync'}</p>
        </div>
        <div class="col-md-4 text-right">
            <button type="button" class="btn btn-primary" id="retry-failed-btn">
                <i class="icon-refresh"></i> {l s='Retry All Failed' mod='odoo_sales_sync'}
            </button>
        </div>
    </div>

    {if $failed_events && count($failed_events) > 0}
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>{l s='ID' mod='odoo_sales_sync'}</th>
                        <th>{l s='Entity Type' mod='odoo_sales_sync'}</th>
                        <th>{l s='Entity ID' mod='odoo_sales_sync'}</th>
                        <th>{l s='Action' mod='odoo_sales_sync'}</th>
                        <th>{l s='Attempts' mod='odoo_sales_sync'}</th>
                        <th>{l s='Last Attempt' mod='odoo_sales_sync'}</th>
                        <th>{l s='Error' mod='odoo_sales_sync'}</th>
                        <th>{l s='Response Code' mod='odoo_sales_sync'}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $failed_events as $event}
                        <tr>
                            <td>#{$event.id_event}</td>
                            <td><span class="label label-info">{$event.entity_type}</span></td>
                            <td>{$event.entity_id}</td>
                            <td>{$event.action_type}</td>
                            <td>
                                <span class="badge badge-danger">{$event.sync_attempts}</span>
                            </td>
                            <td><small>{$event.sync_last_attempt}</small></td>
                            <td>
                                {if $event.sync_error}
                                    <span class="text-danger" title="{$event.sync_error|escape:'htmlall':'UTF-8'}">
                                        {$event.sync_error|truncate:50:'...'}
                                    </span>
                                {else}
                                    -
                                {/if}
                            </td>
                            <td>
                                {if $event.webhook_response_code}
                                    <span class="badge badge-danger">{$event.webhook_response_code}</span>
                                {else}
                                    -
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>

        {* Pagination *}
        {if $pagination.pages > 1}
            <div class="panel-footer">
                <ul class="pagination">
                    {if $pagination.page > 1}
                        <li><a href="{$link->getAdminLink('AdminModules')|escape:'html':'UTF-8'}&configure=odoo_sales_sync&active_tab=failed&failed_page={$pagination.page - 1}">&laquo;</a></li>
                    {/if}

                    {for $i=1 to $pagination.pages}
                        <li class="{if $i == $pagination.page}active{/if}">
                            <a href="{$link->getAdminLink('AdminModules')|escape:'html':'UTF-8'}&configure=odoo_sales_sync&active_tab=failed&failed_page={$i}">{$i}</a>
                        </li>
                    {/for}

                    {if $pagination.page < $pagination.pages}
                        <li><a href="{$link->getAdminLink('AdminModules')|escape:'html':'UTF-8'}&configure=odoo_sales_sync&active_tab=failed&failed_page={$pagination.page + 1}">&raquo;</a></li>
                    {/if}
                </ul>
                <p class="text-muted">{l s='Showing page' mod='odoo_sales_sync'} {$pagination.page} {l s='of' mod='odoo_sales_sync'} {$pagination.pages} ({$pagination.total} {l s='failed events' mod='odoo_sales_sync'})</p>
            </div>
        {/if}
    {else}
        <div class="alert alert-success">
            <i class="icon-check-circle"></i> {l s='No failed events - all syncs successful!' mod='odoo_sales_sync'}
        </div>
    {/if}
</div>
