<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>$Heading.XML</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;color:#1e293b;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;">
                    <tr>
                        <td style="padding:28px;background-color:#172554;border-radius:12px 12px 0 0;">
                            <h1 style="margin:0;color:#ffffff;font-size:24px;line-height:32px;">$Heading.XML</h1>
                            <p style="margin:12px 0 0;color:#dbeafe;font-size:16px;line-height:24px;">$Summary.XML</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;background-color:#ffffff;border-radius:0 0 12px 12px;">
                            <p style="margin:0 0 24px;font-size:15px;line-height:24px;color:#475569;">$Intro.XML</p>
                            <% if $Rows %>
                                <% loop $Rows %>
                                    <table role="presentation" class="monitoring-project-card" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:16px;border:1px solid #e2e8f0;border-left:5px solid $Color.ATT;border-radius:8px;background-color:$Background.ATT;">
                                        <tr>
                                            <td style="padding:20px;">
                                                <p style="margin:0 0 10px;font-size:12px;font-weight:bold;line-height:18px;color:$Color.ATT;">$Badge.XML</p>
                                                <h2 style="margin:0 0 6px;font-size:18px;line-height:26px;color:#0f172a;">$Name.XML</h2>
                                                <p style="margin:0 0 14px;font-size:13px;line-height:20px;color:#64748b;word-break:break-all;">$URL.XML</p>
                                                <p style="margin:0;font-size:14px;line-height:22px;color:#334155;">$Status.XML</p>
                                            </td>
                                        </tr>
                                    </table>
                                <% end_loop %>
                            <% else %>
                                <p style="font-size:15px;line-height:24px;">$Empty.XML</p>
                            <% end_if %>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 16px;text-align:center;color:#64748b;font-size:12px;line-height:20px;">
                            <p style="margin:0 0 12px;">$AutomaticNotice.XML</p>
                            <p style="margin:0;">$Origin.XML<br>$Sender.XML</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
