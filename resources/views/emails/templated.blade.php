<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $companyName }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Helvetica,Arial,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;">
                    <tr>
                        <td style="background:#121e3b;padding:16px 24px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding-right:12px;"><img src="{{ $message->embed(public_path('images/logo-receipt.png')) }}" width="40" height="40" alt="" style="display:block;border-radius:20px;background:#ffffff;"></td>
                                    <td style="color:#ffffff;font-size:17px;font-weight:bold;">{{ $companyName }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;font-size:15px;line-height:1.55;">{!! $htmlBody !!}</td>
                    </tr>
                    <tr>
                        <td style="padding:14px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
                            {{ __('This is an automatic message from the :company remittance system.', ['company' => $companyName]) }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
