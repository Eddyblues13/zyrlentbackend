<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification Code</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0f0f1a; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #0f0f1a; padding: 40px 0;">
        <tr>
            <td align="center">
                <table width="460" cellpadding="0" cellspacing="0" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border-radius: 16px; border: 1px solid rgba(123, 104, 238, 0.3); padding: 40px;">
                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <h1 style="color: #ffffff; font-size: 24px; margin: 0; font-weight: 700;">Zyrlent</h1>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding-bottom: 12px;">
                            <p style="color: #b0b0c0; font-size: 15px; margin: 0;">Your verification code is:</p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <div style="background: linear-gradient(135deg, #7B68EE, #33CCFF); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-size: 48px; font-weight: 800; letter-spacing: 12px; padding: 16px 0;">
                                {{ $code }}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding-bottom: 8px;">
                            <p style="color: #8888a0; font-size: 13px; margin: 0;">This code expires in <strong style="color: #b0b0c0;">10 minutes</strong>.</p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center">
                            <p style="color: #666680; font-size: 12px; margin: 0;">If you didn't request this, you can safely ignore this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
