<div class="developer-tools-logs">
    <% if $Error %>
        <p class="monitoring-error" role="alert">$Error</p>
    <% else_if $Files %>
        <ul class="developer-tools-logs__list">
            <% loop $Files %>
                <li><a href="$URL" data-log-file>$Name</a></li>
            <% end_loop %>
        </ul>
        <div class="developer-tools-logs__viewer" data-log-viewer hidden>
            <label>
                $Interval
                <input type="number" min="5" max="360" step="1" value="30" class="no-change-track" data-log-interval>
            </label>
            <label>
                <input type="checkbox" class="no-change-track" data-log-enabled>
                $Enable
            </label>
            <p class="monitoring-error" data-log-error role="status" hidden>$InvalidInterval</p>
            <iframe title="$FrameTitle" sandbox data-log-frame></iframe>
        </div>
    <% else %>
        <p>$Empty</p>
    <% end_if %>
</div>
