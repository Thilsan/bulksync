{{-- Bulletproof CTA: VML fallback so Outlook renders the fill, not a bare link. --}}
@php $brand = '#1d5a74'; @endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 6px 0;">
    <tr>
        <td align="center" bgcolor="{{ $brand }}" style="border-radius:8px;">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                         href="{{ $url }}" style="height:44px;v-text-anchor:middle;width:260px;" arcsize="18%"
                         stroke="f" fillcolor="{{ $brand }}">
              <w:anchorlock/>
              <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">{{ $label }}</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-- -->
            <a href="{{ $url }}"
               style="display:inline-block; padding:13px 28px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
                      font-size:15px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:8px; background-color:{{ $brand }};">
                {{ $label }}
            </a>
            <!--<![endif]-->
        </td>
    </tr>
</table>
