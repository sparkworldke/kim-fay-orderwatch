import { useState } from "react";
import { Upload, AlertTriangle, GitBranch } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import {
  useImportStaff,
  useStaffImportGaps,
  useResolveStaffGap,
  useCreateUserFromGap,
  useSeedOrgTree,
  useTeamMembers,
} from "@/hooks/admin/useAdminSettings";

export function StaffImportPanel() {
  const importStaff = useImportStaff();
  const gaps = useStaffImportGaps();
  const members = useTeamMembers();
  const resolveGap = useResolveStaffGap();
  const createFromGap = useCreateUserFromGap();
  const seedTree = useSeedOrgTree();
  const [linkUserByGap, setLinkUserByGap] = useState<Record<number, string>>({});

  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm space-y-4">
      <div>
        <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold">
          <Upload className="h-4 w-4" />
          Import Staff from HR Match
        </h3>
        <p className="mb-4 text-sm text-muted-foreground">
          Imports from <code className="text-xs">agent-tools/staff_email_match.json</code>.
          New users are inactive until activated. Shared mailboxes get <code className="text-xs">deny_all</code> automatically.
        </p>
        <div className="flex flex-wrap gap-2">
          <Button
            variant="outline"
            size="sm"
            disabled={importStaff.isPending}
            onClick={() => importStaff.mutate({ dry_run: true, preserve_manual: true, min_confidence: "high" })}
          >
            Preview import
          </Button>
          <Button
            size="sm"
            disabled={importStaff.isPending}
            onClick={() => importStaff.mutate({ dry_run: false, preserve_manual: true, min_confidence: "high" })}
          >
            {importStaff.isPending ? "Importing…" : "Run import"}
          </Button>
          <Button
            variant="outline"
            size="sm"
            disabled={seedTree.isPending}
            onClick={() => seedTree.mutate({ dry_run: false })}
          >
            <GitBranch className="mr-1 h-3.5 w-3.5" />
            Seed org tree (CCO → HODs)
          </Button>
        </div>
        {importStaff.data?.stats && (
          <div className="mt-3 grid grid-cols-2 gap-2 text-xs md:grid-cols-4">
            <div className="rounded border px-2 py-1">Created: {importStaff.data.stats.created}</div>
            <div className="rounded border px-2 py-1">Updated: {importStaff.data.stats.updated}</div>
            <div className="rounded border px-2 py-1">Skipped: {importStaff.data.stats.skipped}</div>
            <div className="rounded border px-2 py-1">Gaps: {importStaff.data.stats.gaps}</div>
          </div>
        )}
      </div>

      <div>
        <h4 className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase text-muted-foreground">
          <AlertTriangle className="h-3.5 w-3.5" />
          Open gaps ({gaps.data?.length ?? 0})
        </h4>
        {gaps.isLoading && <Skeleton className="h-16 w-full" />}
        {gaps.data && gaps.data.length === 0 && (
          <p className="text-sm text-muted-foreground">No open import gaps.</p>
        )}
        {gaps.data && gaps.data.length > 0 && (
          <div className="max-h-64 overflow-y-auto rounded-md border text-xs">
            <table className="w-full">
              <thead className="bg-muted/30 sticky top-0">
                <tr>
                  <th className="px-2 py-1 text-left">Email</th>
                  <th className="px-2 py-1 text-left">Name</th>
                  <th className="px-2 py-1 text-left">Actions</th>
                </tr>
              </thead>
              <tbody>
                {gaps.data.map((gap) => (
                  <tr key={gap.id} className="border-t align-top">
                    <td className="px-2 py-1">{gap.email ?? "—"}</td>
                    <td className="px-2 py-1">{gap.display_name ?? "—"}</td>
                    <td className="px-2 py-1">
                      <div className="flex flex-col gap-1">
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="h-6 text-[10px]"
                          disabled={createFromGap.isPending || !gap.email}
                          onClick={() => createFromGap.mutate(gap.id)}
                        >
                          Create user
                        </Button>
                        <Select
                          value={linkUserByGap[gap.id] ?? ""}
                          onValueChange={(v) => setLinkUserByGap((s) => ({ ...s, [gap.id]: v }))}
                        >
                          <SelectTrigger className="h-7 text-[10px]">
                            <SelectValue placeholder="Link to user" />
                          </SelectTrigger>
                          <SelectContent>
                            {(members.data ?? []).map((m) => (
                              <SelectItem key={m.id} value={String(m.id)}>{m.name}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="h-6 text-[10px]"
                          disabled={resolveGap.isPending || !linkUserByGap[gap.id]}
                          onClick={() =>
                            resolveGap.mutate({
                              gapId: gap.id,
                              resolution_status: "linked",
                              resolved_user_id: Number(linkUserByGap[gap.id]),
                            })
                          }
                        >
                          Link
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-6 text-[10px] text-muted-foreground"
                          onClick={() =>
                            resolveGap.mutate({ gapId: gap.id, resolution_status: "ignored" })
                          }
                        >
                          Ignore
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}