<div class="developer-tools-logs">
    <% if $Error %>
        <p class="monitoring-error" role="alert">$Error</p>
    <% else %>
        <div class="developer-tools-logs__field">
            <% if $Files %>
                <div class="developer-tools-logs__badges" role="list">
                    <% loop $Files %>
                        <a href="$URL" class="developer-tools-logs__badge" role="listitem" aria-pressed="false" data-log-file data-log-name="$Name">$Name</a>
                    <% end_loop %>
                </div>
            <% else %>
                <p class="developer-tools-logs__empty">$Empty</p>
            <% end_if %>
        </div>
        <% if $Files %>
            <div class="developer-tools-logs__viewer" data-log-viewer data-log-reset-url="$ResetURL" data-log-reset-confirm="$ResetConfirm" data-log-reset-success="$ResetSuccess" data-log-reset-error="$ResetError" hidden>
                <div class="developer-tools-logs__toolbar">
                    <label>
                        $Interval
                        <input type="number" min="5" max="360" step="1" value="30" class="no-change-track" data-log-interval>
                    </label>
                    <label>
                        <input type="checkbox" class="no-change-track" data-log-enabled>
                        $Enable
                    </label>
                    <button type="button" class="btn btn-secondary" data-log-reset>$ResetLabel</button>
                </div>
                <p class="monitoring-error" data-log-error role="status" hidden>$InvalidInterval</p>
                <iframe title="$FrameTitle" sandbox data-log-frame></iframe>
            </div>
        <% end_if %>
    <% end_if %>
</div>
