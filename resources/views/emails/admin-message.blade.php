<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $subjectLine }} — Zyrlent</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        :root { color-scheme: light dark; supported-color-schemes: light dark; }

        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }

        /* Dark mode overrides */
        @media (prefers-color-scheme: dark) {
            body, .email-bg { background-color: #0b1220 !important; }
            .card { background-color: #111827 !important; border-color: #1f2937 !important; box-shadow: none !important; }
            .divider { border-color: #1f2937 !important; }
            .title { color: #f1f5f9 !important; }
            .body-text, .greeting { color: #cbd5e1 !important; }
            .footer-bg { background-color: #0f1626 !important; border-color: #1f2937 !important; }
            .footer-text { color: #94a3b8 !important; }
            .footer-muted { color: #64748b !important; }
            .link { color: #60a5fa !important; }
        }

        @media only screen and (max-width: 600px) {
            .card { width: 100% !important; border-radius: 0 !important; }
            .px { padding-left: 24px !important; padding-right: 24px !important; }
        }
    </style>
</head>
<body class="email-bg" style="margin: 0; padding: 0; width: 100% !important; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;">

<!-- Preheader (hidden preview text) -->
<div style="display: none; max-height: 0; overflow: hidden; opacity: 0; mso-hide: all;">
    A message from the Zyrlent team.
</div>

<table class="email-bg" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f1f5f9; padding: 40px 16px;">
    <tr>
        <td align="center">

            <table class="card" role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="width: 560px; max-width: 560px; background-color: #ffffff; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px -2px rgba(15,23,42,0.08); overflow: hidden;">

                <!-- Brand Header (always dark so the logo is visible in light & dark mode) -->
                <tr>
                    <td align="center" style="background-color: #0a0b3d; background-image: linear-gradient(135deg, #0a0b3d 0%, #131559 100%); padding: 28px 32px;">
                        <img src="{{ $message->embed(public_path('email-logo.png')) }}" alt="Zyrlent" width="170" style="display: block; width: 170px; max-width: 60%; height: auto;">
                    </td>
                </tr>

                <!-- Content -->
                <tr>
                    <td class="px" style="padding: 36px 40px 28px;">

                        <h1 class="title" style="margin: 0 0 20px; font-size: 20px; line-height: 1.35; font-weight: 700; color: #0f172a;">
                            {{ $subjectLine }}
                        </h1>

                        @if (!empty($recipientName))
                            <p class="greeting" style="margin: 0 0 16px; font-size: 15px; line-height: 1.6; color: #334155;">
                                Hi {{ $recipientName }},
                            </p>
                        @endif

                        <div class="body-text" style="margin: 0; font-size: 15px; line-height: 1.7; color: #475569;">
                            {!! nl2br(e($bodyText)) !!}
                        </div>

                    </td>
                </tr>

                <!-- Sign-off -->
                <tr>
                    <td class="px" style="padding: 0 40px 32px;">
                        <p class="body-text" style="margin: 0; font-size: 15px; line-height: 1.6; color: #475569;">
                            Warm regards,<br>
                            <strong style="color: #0f172a;" class="title">The Zyrlent Team</strong>
                        </p>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td class="footer-bg px" style="padding: 24px 40px; background-color: #f8fafc; border-top: 1px solid #e2e8f0;">
                        <p class="footer-text" style="margin: 0 0 6px; font-size: 13px; line-height: 1.6; color: #64748b;">
                            Need help? Reach our support team at
                            <a href="mailto:support@zyrlent.com" class="link" style="color: #0066cc; text-decoration: none; font-weight: 600;">support@zyrlent.com</a>
                        </p>
                        <p class="footer-muted" style="margin: 0; font-size: 11px; line-height: 1.5; color: #94a3b8;">
                            &copy; {{ date('Y') }} Zyrlent. All rights reserved.<br>
                            This email was sent to you because you have a Zyrlent account.
                        </p>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
