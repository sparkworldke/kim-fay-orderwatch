<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class AdoptionReportController extends Controller {
 public function index(Request $request):JsonResponse{
  abort_unless($request->user()->hasPermission('adoption.report.view'),403);
  $rows=User::query()->whereNotNull('trained_at')->where('is_shared_mailbox',false)->withMax('signInLogs','created_at')->orderBy('name')->get(['id','name','email','role','trained_at','is_active'])->map(fn($u)=>['id'=>$u->id,'name'=>$u->name,'email'=>$u->email,'role'=>$u->role,'trained_at'=>$u->trained_at,'last_signed_in_at'=>$u->sign_in_logs_max_created_at,'has_signed_in'=>!!$u->sign_in_logs_max_created_at,'is_active'=>$u->is_active]);
  return response()->json(['data'=>$rows,'summary'=>['trained'=>$rows->count(),'signed_in'=>$rows->where('has_signed_in',true)->count(),'not_signed_in'=>$rows->where('has_signed_in',false)->count()],'source'=>'sign_in_logs','data_as_of'=>now('Africa/Nairobi')->toIso8601String()]);
 }
 public function markTrained(Request $request,User $user):JsonResponse{abort_unless($request->user()->hasPermission('adoption.report.manage'),403);abort_if($user->is_shared_mailbox,422,'Shared mailboxes cannot join the trained-user cohort.');$user->forceFill(['trained_at'=>now('Africa/Nairobi'),'trained_by'=>$request->user()->id])->save();return response()->json(['message'=>'User marked trained.']);}
}
