import { createFileRoute } from "@tanstack/react-router";
import { ShieldCheck } from "lucide-react";
import type { ComponentType, ReactNode } from "react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useRoles, usePermissions } from "@/hooks/admin/useAdminSettings";

export const Route = createFileRoute("/app/roles")({
  head: () => ({ meta: [{ title: "Roles & Permissions — Kim-Fay OrderWatch" }] }),
  component: RolesPage,
});

function RolesPage() {
  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Roles & Permissions</h1>
        <p className="text-sm text-muted-foreground">
          View system roles and their assigned permissions.
        </p>
      </div>
      <RolesPanel />
      <PermissionsPanel />
    </div>
  );
}

// ── Shared primitives ─────────────────────────────────────────────────────────

function Panel({ title, icon: Icon, children }: { title: string; icon: ComponentType<{ className?: string }>; children: ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold">
        <Icon className="h-4 w-4" />
        {title}
      </h3>
      {children}
    </div>
  );
}

function MiniTable({
  headers,
  rows,
  empty = "No records found.",
}: {
  headers: string[];
  rows: string[][];
  empty?: string;
}) {
  return (
    <div className="overflow-x-auto rounded-md border">
      <table className="w-full text-sm">
        <thead className="bg-muted/30 text-[11px] uppercase text-muted-foreground">
          <tr>
            {headers.map((h) => (
              <th key={h} className="px-3 py-2 text-left">{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length > 0 ? (
            rows.map((row, i) => (
              <tr key={i} className="border-t">
                {row.map((cell, j) => (
                  <td key={`${i}-${j}`} className="px-3 py-2 align-top">{cell}</td>
                ))}
              </tr>
            ))
          ) : (
            <tr>
              <td className="px-3 py-4 text-muted-foreground" colSpan={headers.length}>{empty}</td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

function PanelSkeleton() {
  return (
    <div className="space-y-3 rounded-lg border bg-card p-4">
      <Skeleton className="h-5 w-48" />
      <Skeleton className="h-10 w-full" />
      <Skeleton className="h-10 w-full" />
      <Skeleton className="h-10 w-2/3" />
    </div>
  );
}

function ErrorBlock({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <p className="text-sm font-medium">{message}</p>
      <Button className="mt-3" variant="outline" onClick={onRetry}>
        Retry
      </Button>
    </div>
  );
}

// ── Roles panel ───────────────────────────────────────────────────────────────

function RolesPanel() {
  const { data, isLoading, isError, refetch } = useRoles();

  if (isLoading) return <PanelSkeleton />;
  if (isError || !data) return <ErrorBlock message="Roles could not be loaded." onRetry={() => refetch()} />;

  return (
    <Panel title="Roles" icon={ShieldCheck}>
      <MiniTable
        headers={["Role", "Users", "Permissions"]}
        rows={data.map((role) => [
          role.name,
          String(role.users_count),
          role.permissions.map((p) => p.name).join(", ") || "No permissions",
        ])}
      />
    </Panel>
  );
}

// ── Permissions matrix panel ──────────────────────────────────────────────────

function PermissionsPanel() {
  const roles = useRoles();
  const permissions = usePermissions();

  if (roles.isLoading || permissions.isLoading) return <PanelSkeleton />;
  if (roles.isError || permissions.isError || !roles.data || !permissions.data) {
    return (
      <ErrorBlock
        message="Permissions could not be loaded."
        onRetry={() => {
          roles.refetch();
          permissions.refetch();
        }}
      />
    );
  }

  return (
    <Panel title="Permission Matrix" icon={ShieldCheck}>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-muted/30 text-[11px] uppercase text-muted-foreground">
            <tr>
              <th className="px-3 py-2 text-left">Permission</th>
              {roles.data.map((role) => (
                <th key={role.id} className="px-3 py-2 text-center">{role.name}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {permissions.data.map((permission) => (
              <tr key={permission.id} className="border-t">
                <td className="px-3 py-2 font-mono text-xs">{permission.name}</td>
                {roles.data.map((role) => {
                  const enabled = role.permissions.some((p) => p.id === permission.id);
                  return (
                    <td key={role.id} className="px-3 py-2 text-center text-xs">
                      {enabled ? (
                        <span className="font-semibold text-success">Yes</span>
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Panel>
  );
}
