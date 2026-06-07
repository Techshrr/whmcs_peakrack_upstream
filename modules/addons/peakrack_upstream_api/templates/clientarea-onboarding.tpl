{if $error}
    <div class="alert alert-danger">{$error|escape}</div>
{/if}
{if $notice}
    <div class="alert alert-info">{$notice|escape}</div>
{/if}

{if $state eq "login_required"}
    <div class="alert alert-warning">Please log in to manage your PeakRack upstream integration.</div>
{elseif $state eq "ineligible"}
    <div class="alert alert-warning">
        <p>Your account is not currently eligible to apply for upstream API access.</p>
        <ul>
            {foreach from=$reasons item=reason}<li>{$reason|escape}</li>{/foreach}
        </ul>
    </div>
{elseif $state eq "empty"}
    <div class="panel panel-default">
        <div class="panel-heading">Apply for PeakRack Upstream API Access</div>
        <div class="panel-body">
            <form method="post">
                <input type="hidden" name="action" value="submit_application">
                <input type="hidden" name="csrf_token" value="{$csrf_token|escape}">
                <div class="form-group">
                    <label>Company / Brand Name</label>
                    <input class="form-control" name="brand_name" value="">
                </div>
                <div class="form-group">
                    <label>Downstream WHMCS Domain</label>
                    <input class="form-control" name="downstream_domain" value="" placeholder="billing.example.com">
                </div>
                <div class="form-group">
                    <label>Downstream Server Outbound IPs</label>
                    <textarea class="form-control" name="outbound_ips" rows="3" placeholder="192.0.2.10"></textarea>
                    <p class="help-block">Enter one IP or CIDR per line. These are the source IPs allowed to call the upstream API.</p>
                </div>
                <div class="form-group">
                    <label>Business Type</label>
                    <input class="form-control" name="business_type" value="">
                </div>
                <div class="form-group">
                    <label>Telegram</label>
                    <input class="form-control" name="telegram" value="" placeholder="@username">
                </div>
                <div class="form-group">
                    <label>QQ</label>
                    <input class="form-control" name="qq" value="">
                </div>
                <div class="form-group">
                    <label>Contact Phone</label>
                    <input class="form-control" name="phone" value="">
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea class="form-control" name="notes" rows="3"></textarea>
                </div>
                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="terms_accepted" value="1">
                        I agree to the integration terms.
                        {if $terms_url}<a href="{$terms_url|escape}" target="_blank" rel="noopener">View terms</a>{/if}
                    </label>
                </div>
                <button type="submit" class="btn btn-primary">Submit Application</button>
            </form>
        </div>
    </div>
{elseif $state eq "pending"}
    <div class="alert alert-info">
        Your integration application has been submitted and is waiting for administrator review.
    </div>
{elseif $state eq "rejected"}
    <div class="alert alert-danger">
        Your integration application was not approved.
        {if $application.admin_message}<br>{$application.admin_message|escape}{/if}
    </div>
{elseif $state eq "approved"}
    <div class="panel panel-default">
        <div class="panel-heading">Integration Configuration</div>
        <div class="panel-body">
            <p><strong>Downstream module:</strong> <code>{$guide.module_name|escape}</code></p>
            <p><strong>Server module label:</strong> <code>{$guide.server_module_label|escape}</code></p>
            <p><strong>API Base URL:</strong> <code>{$guide.api_base_url|escape}</code></p>
            <p><strong>Health Check URL:</strong> <code>{$guide.health_url|escape}</code></p>
            <p><strong>Public Key:</strong> <code>{$guide.public_key|escape}</code></p>
            {if $guide.secret}
                <div class="alert alert-warning">
                    <strong>API Secret:</strong> <code>{$guide.secret|escape}</code>
                    <p>Store this value now. It will not be shown again after you confirm.</p>
                    <form method="post">
                        <input type="hidden" name="action" value="confirm_secret_displayed">
                        <input type="hidden" name="csrf_token" value="{$csrf_token|escape}">
                        <button type="submit" class="btn btn-warning">I have stored the API Secret</button>
                    </form>
                </div>
            {/if}
            {if $guide.download_url}
                <p><a class="btn btn-default" href="{$guide.download_url|escape}">Download Downstream Module</a></p>
            {/if}
            <p><strong>Cron example:</strong></p>
            <pre>{$guide.cron_example|escape}</pre>
            <p class="text-muted">{$reset_policy|escape}</p>
            <form method="post">
                <input type="hidden" name="action" value="reset_secret">
                <input type="hidden" name="csrf_token" value="{$csrf_token|escape}">
                <button type="submit" class="btn btn-danger">Reset API Secret</button>
            </form>
        </div>
    </div>
{/if}
