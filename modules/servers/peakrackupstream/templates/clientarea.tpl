<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title">{$LANG.peakrackupstream_service_information|escape:'html'}</h3>
    </div>
    <div class="panel-body">
        <dl class="dl-horizontal">
            <dt>{$LANG.peakrackupstream_status|escape:'html'}</dt>
            <dd>{$service_status|escape:'html'}</dd>

            <dt>{$LANG.peakrackupstream_primary_ip|escape:'html'}</dt>
            <dd>{if $primary_ip}{$primary_ip|escape:'html'}{else}-{/if}</dd>

            <dt>{$LANG.peakrackupstream_panel_url|escape:'html'}</dt>
            <dd>
                {if $panel_url}
                    <a href="{$panel_url|escape:'html'}" target="_blank" rel="noopener noreferrer">
                        {$panel_url|escape:'html'}
                    </a>
                {else}
                    -
                {/if}
            </dd>

            <dt>{$LANG.peakrackupstream_last_sync|escape:'html'}</dt>
            <dd>{if $last_sync_time}{$last_sync_time|escape:'html'}{else}-{/if}</dd>
        </dl>

        {if $sso_available}
            <a class="btn btn-primary" href="clientarea.php?action=productdetails&amp;id={$serviceid|escape:'url'}&amp;dosinglesignon=1">
                {$LANG.peakrackupstream_open_panel|escape:'html'}
            </a>
        {/if}
    </div>
</div>
