<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grooming Done</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f7fa; color: #334155; font-family: Arial, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f4f7fa;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 560px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px;">
                    <tr>
                        <td align="center" style="padding: 28px 24px 12px;">
                            <img src="{{ $message->embed($logoPath) }}" width="112" alt="Bethlehem Animal Clinic &amp; Grooming logo" style="display: block; width: 112px; max-width: 100%; height: auto; border: 0;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 12px 32px 32px; text-align: center;">
                            <h1 style="margin: 0 0 20px; color: #2f4b66; font-size: 24px; line-height: 1.3;">Grooming Done</h1>
                            <p style="margin: 0 0 16px; font-size: 16px; line-height: 1.6;">Hi {{ $firstName }},</p>
                            <p style="margin: 0; font-size: 16px; line-height: 1.6;">Grooming is done for <strong>{{ $petNames }}</strong>.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 18px 24px; background-color: #eef4f8; border-radius: 0 0 16px 16px; text-align: center; color: #64748b; font-size: 12px; line-height: 1.5;">
                            Bethlehem Animal Clinic &amp; Grooming
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
