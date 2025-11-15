{*
* Events tab template
*
* @author Odoo Sales Sync Module
* @version 1.0.0
*}

<div class="panel-body">
    <h3>{l s='Sales Events' mod='odoo_sales_sync'}</h3>
    <p>{l s='All sales-related events tracked by the module' mod='odoo_sales_sync'}</p>

    {if $events && count($events) > 0}
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>{l s='ID' mod='odoo_sales_sync'}</th>
                        <th>{l s='Entity Type' mod='odoo_sales_sync'}</th>
                        <th>{l s='Entity ID' mod='odoo_sales_sync'}</th>
                        <th>{l s='Action' mod='odoo_sales_sync'}</th>
                        <th>{l s='Hook' mod='odoo_sales_sync'}</th>
                        <th>{l s='Status' mod='odoo_sales_sync'}</th>
                        <th>{l s='Date' mod='odoo_sales_sync'}</th>
                        <th>{l s='Response' mod='odoo_sales_sync'}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $events as $event}
                        <tr>
                            <td>#{$event.id_event}</td>
                            <td><span class="label label-info">{$event.entity_type}</span></td>
                            <td>{$event.entity_id}</td>
                            <td>{$event.action_type}</td>
                            <td><small>{$event.hook_name}</small></td>
                            <td>
                                {if $event.sync_status == 'success'}
                                    <span class="label label-success">{l s='Success' mod='odoo_sales_sync'}</span>
                                {elseif $event.sync_status == 'failed'}
                                    <span class="label label-danger">{l s='Failed' mod='odoo_sales_sync'}</span>
                                {elseif $event.sync_status == 'pending'}
                                    <span class="label label-warning">{l s='Pending' mod='odoo_sales_sync'}</span>
                                {else}
                                    <span class="label label-default">{$event.sync_status}</span>
                                {/if}
                            </td>
                            <td><small>{$event.date_add}</small></td>
                            <td>
                                {if $event.webhook_response_code}
                                    <span class="badge {if $event.webhook_response_code >= 200 && $event.webhook_response_code < 300}badge-success{else}badge-danger{/if}">
                                        {$event.webhook_response_code}
                                    </span>
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
                        <li><a href="{$link->getAdminLink('AdminModules')|escape:'html':'UTF-8'}&configure=odoo_sales_sync&active_tab=events&events_page={$pagination.page - 1}">&laquo;</a></li>
                    {/if}

                    {for $i=1 to $pagination.pages}
                        <li class="{if $i == $pagination.page}active{/if}">
                            <a href="{$link->getAdminLink('AdminModules')|escape:'html':'UTF-8'}&configure=odoo_sales_sync&active_tab=events&events_page={$i}">{$i}</a>
                        </li>
                    {/for}

                    {if $pagination.page < $pagination.pages}
                        <li><a href="{$link->getAdminLink('AdminModules')|escape:'html':'UTF-8'}&configure=odoo_sales_sync&active_tab=events&events_page={$pagination.page + 1}">&raquo;</a></li>
                    {/if}
                </ul>
                <p class="text-muted">{l s='Showing page' mod='odoo_sales_sync'} {$pagination.page} {l s='of' mod='odoo_sales_sync'} {$pagination.pages} ({$pagination.total} {l s='total events' mod='odoo_sales_sync'})</p>
            </div>
        {/if}
    {else}
        <div class="alert alert-info">
            <i class="icon-info-circle"></i> {l s='No events found' mod='odoo_sales_sync'}
        </div>
    {/if}
</div>
