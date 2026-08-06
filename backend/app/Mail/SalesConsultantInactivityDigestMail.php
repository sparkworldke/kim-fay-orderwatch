<?php

namespace App\Mail;

use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SalesConsultantInactivityDigestMail extends Mailable
{
    public function __construct(public readonly User $consultant, public readonly array $digest) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Sight sales and customer update');
    }

    public function content(): Content
    {
        $d = $this->digest;
        $orders = $d['orders'];
        $undelivered = $d['undelivered'];
        $reasons = collect($undelivered['reasons'])->map(fn ($count, $reason) =>
            '<li>'.e((string) $reason).': <strong>'.number_format((int) $count).'</strong></li>'
        )->implode('');
        $recommendations = collect($d['recommendations'])->map(fn ($text) => '<li>'.e($text).'</li>')->implode('');
        $url = FrontendUrl::path('/app/accounts');
        $name = e($this->consultant->name);
        $period = e($d['period_label']);

        return new Content(htmlString: <<<HTML
        <html><body style="margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#172033">
        <table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 12px">
        <table width="640" style="max-width:640px;width:100%;background:#fff;border-radius:10px;overflow:hidden">
          <tr><td style="background:#0756a3;color:#fff;padding:22px 28px"><strong style="font-size:20px">Kim-Fay Sight</strong><div style="margin-top:4px">Sales portfolio update</div></td></tr>
          <tr><td style="padding:24px 28px">
            <p>Hello {$name},</p>
            <p>You have not signed in to Sight for more than 25 hours. Here is your portfolio update for <strong>{$period}</strong>.</p>
            <h3>Orders</h3>
            <table width="100%" style="border-collapse:collapse;text-align:center">
              <tr style="background:#eef4fb"><td style="padding:10px">Total<br><strong>{$orders['total']}</strong></td><td>Completed<br><strong>{$orders['completed']}</strong></td><td>Rejected<br><strong>{$orders['rejected']}</strong></td><td>Shipping<br><strong>{$orders['shipping']}</strong></td></tr>
            </table>
            <h3>Customers</h3><p><strong>{$d['customers']['portfolio']}</strong> assigned customers; <strong>{$d['customers']['with_orders']}</strong> placed orders in this period.</p>
            <h3>Undelivered items</h3>
            <p><strong>{$undelivered['lines']}</strong> lines / <strong>{$undelivered['units']}</strong> units:
              Manufactured <strong>{$undelivered['manufactured_units']}</strong>, Partner brands <strong>{$undelivered['partner_units']}</strong>, Unclassified <strong>{$undelivered['unclassified_units']}</strong>.
            </p><ul>{$reasons}</ul>
            <h3>Recommended actions</h3><ul>{$recommendations}</ul>
            <p style="margin-top:24px"><a href="{$url}" style="background:#0756a3;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">Open My Portfolio</a></p>
          </td></tr>
          <tr><td style="padding:16px 28px;background:#f7f8fa;color:#667085;font-size:12px">This reminder is controlled by your administrator.</td></tr>
        </table></td></tr></table></body></html>
        HTML);
    }
}
