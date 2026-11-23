<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>You have been invited to Metricove</title>
</head>
<body style="margin:0;background:#f0fdfa;color:#17252f;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f0fdfa;padding:36px 16px;">
        <tr><td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #dce6eb;border-radius:18px;overflow:hidden;">
                <tr><td style="padding:28px 36px;background:#123d4b;color:#ffffff;">
                    <p style="margin:0;color:#99f6e4;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">Metricove</p>
                    <p style="margin:10px 0 0;font-size:14px;line-height:1.5;color:#e2fffb;">Your household, in focus.</p>
                </td></tr>
                <tr><td style="padding:36px;">
                    <p style="margin:0 0 10px;color:#0f766e;font-size:12px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">Your invitation</p>
                    <h1 style="margin:0 0 18px;color:#123d4b;font-size:28px;line-height:1.25;">You have been invited to Metricove</h1>
                    <p style="margin:0 0 24px;color:#405563;font-size:16px;line-height:1.65;">An administrator has invited you to join their Metricove household. Accept the invitation to choose your name and set a password.</p>
                    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 26px;"><tr><td bgcolor="#0f766e" style="border-radius:10px;">
                        <a href="{{ $invitationUrl }}" style="display:inline-block;padding:14px 22px;border:1px solid #0f766e;border-radius:10px;color:#f0fdfa;font-size:15px;font-weight:bold;text-decoration:none;">Accept invitation</a>
                    </td></tr></table>
                    <p style="margin:0 0 14px;color:#586d79;font-size:14px;line-height:1.6;">This link expires {{ $expiresAt }} and can only be used once.</p>
                    <p style="margin:0;color:#586d79;font-size:14px;line-height:1.6;">If you were not expecting this invitation, you can ignore this email.</p>
                    <hr style="height:1px;margin:28px 0 18px;border:0;background:#e5edf0;">
                    <p style="margin:0;color:#82939d;font-size:12px;line-height:1.6;">If the button does not work, copy this link into your browser:<br><a href="{{ $invitationUrl }}" style="color:#0f766e;word-break:break-all;">{{ $invitationUrl }}</a></p>
                </td></tr>
            </table>
            <p style="margin:18px 0 0;color:#82939d;font-size:12px;">Metricove · Your household, in one place</p>
        </td></tr>
    </table>
</body>
</html>
