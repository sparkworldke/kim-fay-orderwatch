<?php

namespace App\Services\Dtc;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;

class DtcPriceListPdfService
{
    public function render(Collection $rows, array $filters): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->html($rows, $filters));
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $canvas->page_text(36, 806, 'Prices subject to change without notice.  ·  Page {PAGE_NUM} of {PAGE_COUNT}', null, 8, [0.35, 0.35, 0.35]);

        return $dompdf->output();
    }

    private function html(Collection $rows, array $filters): string
    {
        $e = static fn (mixed $v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $logo = '';
        $path = (string) config('company.logo_path');
        if (is_file($path)) {
            $logo = 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
        }
        $generated = now('Africa/Nairobi')->format('d M Y H:i').' EAT';
        $filterText = collect($filters)->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])->map(fn ($v, $k) => $k.': '.(is_array($v) ? implode(', ', $v) : $v))->implode(' · ');
        $body = $rows->map(function ($row) use ($e) {
            return '<tr><td>'.$e($row->inventory_id).'</td><td>'.$e($row->description).'</td><td>'.$e($row->uom).'</td><td class="num">'.number_format($row->effectivePrice(), 2).'</td><td>'.$e($row->taxation).'</td><td>'.$e($row->effective_date?->toDateString()).'</td></tr>';
        })->implode('');

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            @page{margin:28px 32px 48px}body{font-family:DejaVu Sans,sans-serif;color:#243126;font-size:9px}
            .head{border-bottom:3px solid #16733d;padding-bottom:10px;margin-bottom:12px}.logo{width:88px;float:left;margin-right:16px}.legal{font-size:16px;font-weight:bold;color:#126334}.contact{font-size:8.5px;line-height:1.55}.clear{clear:both}
            h1{font-size:15px;color:#126334;margin:8px 0 3px}.meta{color:#59655c;margin-bottom:10px}table{width:100%;border-collapse:collapse}th{background:#16733d;color:#fff;text-align:left;padding:6px 5px}td{border-bottom:1px solid #d8e1da;padding:5px}.num{text-align:right;white-space:nowrap}tr:nth-child(even){background:#f4f8f5}
        </style></head><body><div class="head">'.($logo ? '<img class="logo" src="'.$logo.'">' : '').'<div class="legal">'.$e(config('company.legal_name')).'</div><div class="contact">'.$e(config('company.address')).'<br>Call: '.$e(config('company.phone')).' · WhatsApp: '.$e(config('company.whatsapp')).'<br>'.$e(config('company.email')).'</div><div class="clear"></div></div><h1>DTC ACCOUNT PRICE LIST</h1><div class="meta">Generated: '.$e($generated).($filterText ? '<br>Filters: '.$e($filterText) : '').'</div><table><thead><tr><th>Inventory ID</th><th>Description</th><th>UOM</th><th>DTC Price (KES)</th><th>Tax category</th><th>Effective date</th></tr></thead><tbody>'.$body.'</tbody></table></body></html>';
    }
}
