<div class="monitoring-details">
    <% if $Error %><p role="alert" class="monitoring-error">$Error.XML</p><% end_if %>
    <% if $Rows %>
        <table class="table monitoring-versions">
            <thead><tr><th scope="col">$ItemTitle.XML</th><th scope="col">$CurrentTitle.XML</th><th scope="col">$LatestTitle.XML</th><th scope="col">$StatusTitle.XML</th></tr></thead>
            <tbody>
            <% loop $Rows %>
                <tr>
                    <th scope="row">$Item.XML</th>
                    <td>$Current.XML</td>
                    <td>$Latest.XML</td>
                    <td>
                        <span class="monitoring-status monitoring-status--$Status.ATT">
                            <span class="monitoring-status-icon" aria-hidden="true"></span> $Label.XML
                        </span>
                        <% if $CheckedAt %><small>$CheckedAt.XML</small><% end_if %>
                        <% if $Source %><small>$Source.XML</small><% end_if %>
                        <% if $Error %><small>$Error.XML</small><% end_if %>
                    </td>
                </tr>
            <% end_loop %>
            </tbody>
        </table>
    <% else %>
        <p>$Empty.XML</p>
    <% end_if %>
    <p>$Disclaimer.XML</p>
</div>
