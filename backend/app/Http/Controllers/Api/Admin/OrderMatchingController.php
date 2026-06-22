<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\OrderMatchRun;
use App\Services\Email\OrderMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderMatchingController extends Controller
{
    public function __construct(private readonly OrderMatchingService $matching)
    {
    }

    /** Run PO extraction on all unprocessed emails. */
    public function extractPo(Request $request): JsonResponse
    {
        $result = $this->matching->runPoExtraction();

        return response()->json([
            'message'   => "PO extraction complete: {$result['extracted']} extracted from {$result['processed']} emails.",
            'processed' => $result['processed'],
            'extracted' => $result['extracted'],
        ]);
    }

    /** Run order matching (POs → Acumatica SOs). */
    public function matchOrders(Request $request): JsonResponse
    {
        $run = $this->matching->runOrderMatching($request->user()?->id);

        return response()->json([
            'message' => $run->status === 'completed'
                ? "Matching complete: {$run->matched} matched, {$run->duplicate} duplicate, {$run->missing_in_acumatica} missing in Acumatica."
                : "Matching failed: {$run->error_message}",
            'run' => $run,
        ], $run->status === 'failed' ? 422 : 200);
    }

    /** Run both extraction + matching in one shot. */
    public function runAll(Request $request): JsonResponse
    {
        $extraction = $this->matching->runPoExtraction();
        $run        = $this->matching->runOrderMatching($request->user()?->id);

        $message = $run->status === 'completed'
            ? "Done: {$extraction['extracted']} POs extracted, {$run->matched} matched, {$run->duplicate} duplicate."
            : "Extraction succeeded but matching failed: {$run->error_message}";

        return response()->json([
            'message'    => $message,
            'extraction' => $extraction,
            'match_run'  => $run,
        ], $run->status === 'failed' ? 422 : 200);
    }

    /** Manual PO override for a specific email. */
    public function overridePo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_id'  => ['required', 'integer', 'exists:emails,id'],
            'po_number' => ['required', 'string', 'max:100'],
        ]);

        $email = Email::findOrFail($validated['email_id']);
        $order = $this->matching->manualPoOverride($email, $validated['po_number']);

        return response()->json([
            'message' => $order
                ? "PO {$validated['po_number']} linked to order {$order->acumatica_order_nbr}."
                : "PO number saved but no matching Acumatica order found.",
            'matched_order' => $order,
        ]);
    }

    /** Recent match run history. */
    public function history(): JsonResponse
    {
        return response()->json(
            OrderMatchRun::orderByDesc('started_at')->limit(20)->get()
        );
    }

    /** Emails pending manual PO entry (extraction attempted but no PO found). */
    public function pendingManual(Request $request): JsonResponse
    {
        $emails = Email::where('po_extraction_attempted', true)
            ->whereNull('extracted_po_number')
            ->orderByDesc('received_at')
            ->paginate(50);

        return response()->json($emails);
    }
}
