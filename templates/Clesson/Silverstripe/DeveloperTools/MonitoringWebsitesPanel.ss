<div class="monitoring-websites-panel">
    <% if $Rows %>
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">$WebsiteTitle.XML</th>
                    <th scope="col">$ActionTitle.XML</th>
                </tr>
            </thead>
            <tbody>
                <% loop $Rows %>
                    <tr>
                        <td><strong>$Name.XML</strong><br><small>$BaseURL.XML</small></td>
                        <td>$Indicator</td>
                    </tr>
                <% end_loop %>
            </tbody>
        </table>
    <% else %>
        <p>$Empty.XML</p>
    <% end_if %>
</div>
