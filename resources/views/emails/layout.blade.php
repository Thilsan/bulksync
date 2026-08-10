{{--
    Shared shell for every Product Creation Request email.

    Table-based with inline styles on purpose: Outlook and most corporate clients
    strip <style> blocks and ignore flexbox, so anything structural has to be a
    table. The wordmark is real text rather than part of the logo image, because
    image loading is blocked by default in a lot of mail clients — the branding
    has to survive that.
--}}
@php
    $brand     = '#1d5a74';
    $brandSoft = '#e9f7fc';
    $appUrl    = rtrim(config('app.url'), '/');
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <title>@yield('subject', 'AI E-Commerce Studio')</title>
    <!--[if mso]>
    <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
    <![endif]-->
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; -webkit-font-smoothing:antialiased;">

    {{-- Inbox preview line: what the recipient sees before opening. --}}
    <div style="display:none; font-size:1px; color:#f3f4f6; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">
        @yield('preheader', 'An update on a product creation request.')
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:24px 12px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"
                       style="width:600px; max-width:600px; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e5e7eb;">

                    {{-- Header: logo, then the product name as text --}}
                    <tr>
                        <td align="center" style="padding:28px 24px 20px 24px; background-color:#ffffff;">
                            <img src="{{ $appUrl }}/aih_logo-1.png" width="170" alt="Abuissa Holding"
                                 style="display:block; width:170px; max-width:170px; height:auto; border:0; margin:0 auto 14px auto;">
                            <div style="font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:17px; font-weight:700; color:{{ $brand }}; letter-spacing:0.3px;">
                                AI E-Commerce Studio
                            </div>
                            <div style="font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; color:#9ca3af; margin-top:4px;">
                                Product Creation Request
                            </div>
                        </td>
                    </tr>

                    {{-- Accent rule --}}
                    <tr><td style="height:4px; background-color:{{ $brand }}; line-height:4px; font-size:0;">&nbsp;</td></tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:28px 28px 8px 28px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#374151; font-size:15px; line-height:1.6;">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:20px 28px 26px 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr><td style="border-top:1px solid #f3f4f6; height:1px; line-height:1px; font-size:0;">&nbsp;</td></tr>
                            </table>
                            <p style="margin:16px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:1.6; color:#9ca3af;">
                                You are receiving this because you are involved in this product creation request.
                                Manage everything at
                                <a href="{{ $appUrl }}/product-requests" style="color:{{ $brand }}; text-decoration:underline;">{{ $appUrl }}</a>.
                            </p>
                            <p style="margin:10px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; color:#9ca3af;">
                                Powered by the Abuissa Holding E-Commerce Department
                            </p>
                        </td>
                    </tr>
                </table>

                <p style="margin:16px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:11px; color:#b0b7c3;">
                    AI E-Commerce Studio &middot; Abuissa Holding
                </p>

            </td>
        </tr>
    </table>
</body>
</html>
