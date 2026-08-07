<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Services\Sfa\SfaSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class SfaSyncController extends Controller {
    public function status(SfaSyncService $sync): JsonResponse { return response()->json($sync->status()); }
    public function run(Request $request,SfaSyncService $sync): JsonResponse { $data=$request->validate(['table'=>['required','string','in:reps,customers']]); $log=$sync->run($data['table']); return response()->json($log,$log->status==='success'?200:502); }
}
