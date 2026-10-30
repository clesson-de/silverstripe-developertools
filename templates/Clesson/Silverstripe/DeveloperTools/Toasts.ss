<link rel="stylesheet" href="$CSSURL.ATT">
<aside id="$OverlayID.ATT" class="dt-toasts" aria-label="$RegionLabel.ATT">
    <% loop $Groups %>
        <div class="dt-toast-region" data-horizontal="$Horizontal.ATT" data-vertical="$Vertical.ATT">
            <% loop $Messages %>
                <section class="dt-toast dt-toast--$Type.ATT" role="$Role.ATT" aria-atomic="true" data-autohide="$AutoHideSeconds.ATT" tabindex="0">
                    <% if $Title || $Removable %>
                        <div class="dt-toast__heading">
                            <% if $Title %><strong>$Title.XML</strong><% end_if %>
                            <% if $Removable %>
                                <button type="button" class="dt-toast__close" aria-label="$Top.CloseLabel.ATT" hidden>&times;</button>
                            <% end_if %>
                        </div>
                    <% end_if %>
                    <div class="dt-toast__content">$Content</div>
                </section>
            <% end_loop %>
        </div>
    <% end_loop %>
</aside>
<script src="$JSURL.ATT" defer></script>
