import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { BellRing, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { apiFetch, getErrorMessage } from "@/lib/api";

type Consultant = {
  id: number; name: string; email: string; is_active: boolean; inactivity_digest_enabled: boolean;
  last_login_at: string | null; last_inactivity_digest_sent_at: string | null;
};
const key = ["admin", "sales-consultant-digests"] as const;

export function SalesConsultantDigestPanel() {
  const qc = useQueryClient();
  const consultants = useQuery({ queryKey: key, queryFn: () => apiFetch<{ data: Consultant[] }>("admin/sales-consultant-digests") });
  const update = useMutation({
    mutationFn: ({ id, enabled }: { id: number; enabled: boolean }) =>
      apiFetch(`admin/sales-consultant-digests/${id}`, { method: "PUT", body: { enabled } }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: key }),
    onError: (e) => toast.error(getErrorMessage(e)),
  });
  const bulk = useMutation({
    mutationFn: (enabled: boolean) => apiFetch<{ message: string }>("admin/sales-consultant-digests/bulk", { method: "PUT", body: { enabled } }),
    onSuccess: (data) => { toast.success(data.message); void qc.invalidateQueries({ queryKey: key }); },
    onError: (e) => toast.error(getErrorMessage(e)),
  });

  return <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div><h3 className="flex items-center gap-2 text-sm font-semibold"><BellRing className="h-4 w-4" />Sales Consultant Inactivity Updates</h3>
        <p className="mt-1 text-xs text-muted-foreground">Email enabled active consultants after more than 25 hours without a login. Eligible users receive at most one update every 24 hours while inactive.</p></div>
      <div className="flex gap-2">
        <Button variant="outline" disabled={bulk.isPending} onClick={() => bulk.mutate(false)}>
          Deactivate all
        </Button>
        <Button disabled={bulk.isPending} onClick={() => bulk.mutate(true)}>
          {bulk.isPending && <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" />}
          Activate all
        </Button>
      </div>
    </div>
    <div className="mt-4 overflow-x-auto rounded-md border"><table className="w-full text-xs"><thead className="bg-muted/40"><tr>
      <th className="px-3 py-2 text-left">Consultant</th><th className="px-3 py-2 text-left">Account</th><th className="px-3 py-2 text-left">Last login</th><th className="px-3 py-2 text-left">Last update sent</th><th className="px-3 py-2 text-right">Email update</th>
    </tr></thead><tbody>{consultants.isLoading?<tr><td colSpan={5} className="p-6 text-center"><Loader2 className="mx-auto h-5 w-5 animate-spin"/></td></tr>:consultants.data?.data.map(user=><tr key={user.id} className="border-t">
      <td className="px-3 py-2"><strong>{user.name}</strong><div className="text-muted-foreground">{user.email}</div></td>
      <td className="px-3 py-2">{user.is_active?"Active":"Inactive"}</td>
      <td className="px-3 py-2">{date(user.last_login_at)}</td><td className="px-3 py-2">{date(user.last_inactivity_digest_sent_at)}</td>
      <td className="px-3 py-2 text-right"><Switch checked={user.inactivity_digest_enabled} disabled={update.isPending} onCheckedChange={enabled=>update.mutate({id:user.id,enabled})}/></td>
    </tr>)}</tbody></table></div>
  </div>;
}
function date(value:string|null){return value?new Date(value).toLocaleString("en-KE",{timeZone:"Africa/Nairobi"}):"Never";}
