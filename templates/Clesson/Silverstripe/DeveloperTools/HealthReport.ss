<section class="monitoring-health">
    <h3>$Title.XML</h3>
    <% if $Rows %>
        <table class="table">
            <tbody>
                <% loop $Rows %>
                    <tr class="$row_class.ATT" data-health-group="$group_key.ATT">
                        <th scope="row"><% if $is_detail %><span class="monitoring-health__detail-marker" aria-hidden="true">↳</span> <% end_if %>$title.XML</th>
                        <td class="monitoring-health--$status.ATT"><strong>$label.XML</strong></td>
                        <td>
                            $message.XML
                            <br><small>$checked_at.XML</small>
                            <br><small><strong>$category.XML</strong> | $source.XML | $state_label.XML | severity: $severity_label.XML | priority: $priority_label.XML</small>
                            <% if $target %><br><small>Target: $target.XML</small><% end_if %>
                            <% if $evidence %><br><small>Evidence: $evidence.XML</small><% end_if %>
                            <% if $impact %><br><small>Impact: $impact.XML</small><% end_if %>
                            <% if $remediation %><br><small>Remediation: $remediation.XML</small><% end_if %>
                            <% if $limitations %><br><small>Limitations: $limitations.XML</small><% end_if %>
                            <% if $reference %><br><small>Reference: $reference.XML</small><% end_if %>
                        </td>
                    </tr>
                <% end_loop %>
            </tbody>
        </table>
    <% else %>
        <p>$Empty.XML</p>
    <% end_if %>
</section>
