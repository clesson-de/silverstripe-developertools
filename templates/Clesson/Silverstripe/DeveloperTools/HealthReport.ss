<section class="monitoring-health">
    <h3>$Title.XML</h3>
    <% if $Rows %>
        <table class="table">
            <tbody>
                <% loop $Rows %>
                    <tr>
                        <th scope="row">$title.XML</th>
                        <td class="monitoring-health--$status.ATT"><strong>$label.XML</strong></td>
                        <td>$message.XML<br><small>$checked_at.XML</small></td>
                    </tr>
                <% end_loop %>
            </tbody>
        </table>
    <% else %>
        <p>$Empty.XML</p>
    <% end_if %>
</section>
