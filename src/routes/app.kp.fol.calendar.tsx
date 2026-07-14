import { Link, createFileRoute } from "@tanstack/react-router";
import { useMemo, useState, type ReactNode } from "react";
import {
  ArrowLeft,
  CalendarDays,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  CircleDot,
  MapPin,
  RefreshCw,
  Users,
} from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useCapabilities } from "@/hooks/useCapabilities";
import {
  useFolTechnicianCalendar,
  useFolTechnicians,
  useResolveFolTechnician,
  type FolCalendarItem,
} from "@/hooks/useFol";
import { FOL_STATUS_CLASS, FOL_STATUS_LABEL, formatFolDate } from "@/lib/fol";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/app/kp/fol/calendar")({
  head: () => ({ meta: [{ title: "Technician Calendar - Kim-Fay OrderWatch" }] }),
  component: FolTechnicianCalendarPage,
});

function monthLabel(ym: string) {
  const [y, m] = ym.split("-").map(Number);
  return new Date(y, (m ?? 1) - 1, 1).toLocaleDateString("en-KE", {
    month: "long",
    year: "numeric",
    timeZone: "Africa/Nairobi",
  });
}

function shiftMonth(ym: string, delta: number): string {
  const [y, m] = ym.split("-").map(Number);
  const d = new Date(y, (m ?? 1) - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

function daysInMonthGrid(ym: string): Array<{ date: string | null; inMonth: boolean }> {
  const [y, m] = ym.split("-").map(Number);
  const first = new Date(y, (m ?? 1) - 1, 1);
  const lastDay = new Date(y, m ?? 1, 0).getDate();
  // Monday-first grid
  let startPad = first.getDay() - 1;
  if (startPad < 0) startPad = 6;
  const cells: Array<{ date: string | null; inMonth: boolean }> = [];
  for (let i = 0; i < startPad; i++) cells.push({ date: null, inMonth: false });
  for (let day = 1; day <= lastDay; day++) {
    cells.push({
      date: `${ym}-${String(day).padStart(2, "0")}`,
      inMonth: true,
    });
  }
  while (cells.length % 7 !== 0) cells.push({ date: null, inMonth: false });
  return cells;
}

function FolTechnicianCalendarPage() {
  const caps = useCapabilities();
  const permissions = caps.permissions ?? [];
  const canExecute = permissions.includes("kp.fol.install.execute");
  const canManage = permissions.includes("kp.fol.install.manage");
  const canView = permissions.includes("kp.fol.view");

  const [month, setMonth] = useState(() => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
  });
  const [selectedDate, setSelectedDate] = useState<string | null>(null);
  const [techFilter, setTechFilter] = useState<string>("me");

  const technicians = useFolTechnicians(canManage);
  const techId =
    canManage && techFilter !== "me" && techFilter !== "all"
      ? Number(techFilter)
      : undefined;

  const calendar = useFolTechnicianCalendar({
    month,
    technicianUserId: techId ?? null,
    enabled: canView && (canExecute || canManage),
  });

  const dayMap = useMemo(() => {
    const map = new Map<string, { open: number; resolved: number; items: FolCalendarItem[] }>();
    for (const day of calendar.data?.days ?? []) {
      map.set(day.date, { open: day.open, resolved: day.resolved, items: day.items });
    }
    return map;
  }, [calendar.data?.days]);

  const selectedItems = useMemo(() => {
    if (!selectedDate) return calendar.data?.items ?? [];
    return dayMap.get(selectedDate)?.items ?? [];
  }, [selectedDate, dayMap, calendar.data?.items]);

  const cells = useMemo(() => daysInMonthGrid(month), [month]);
  const summary = calendar.data?.summary;
  const accounts = calendar.data?.accounts ?? [];

  if (!canView || (!canExecute && !canManage)) {
    return (
      <div className="rounded-lg border bg-card p-6 text-sm text-muted-foreground">
        Technician calendar is available for Technician and Technician Manager roles.
        <div className="mt-3">
          <Button asChild variant="outline" size="sm">
            <Link to="/app/kp/fol">
              <ArrowLeft className="mr-1 h-3.5 w-3.5" /> Back to FOL
            </Link>
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <div className="mb-1 flex items-center gap-2 text-sm text-muted-foreground">
            <Link to="/app/kp/fol" className="inline-flex items-center hover:text-foreground">
              <ArrowLeft className="mr-1 h-3.5 w-3.5" /> KP FOL
            </Link>
          </div>
          <h1 className="text-xl font-semibold tracking-tight">Technician calendar</h1>
          <p className="text-sm text-muted-foreground">
            Your allocated accounts and installs — open work to resolve, and how many you have completed.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {canManage && (
            <Select value={techFilter} onValueChange={setTechFilter}>
              <SelectTrigger className="h-9 w-[200px]">
                <SelectValue placeholder="Technician" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="me">My calendar</SelectItem>
                {(technicians.data ?? []).map((t) => (
                  <SelectItem key={t.id} value={String(t.id)}>
                    {t.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
          <Button variant="outline" size="sm" onClick={() => calendar.refetch()}>
            <RefreshCw className="mr-1 h-3.5 w-3.5" /> Refresh
          </Button>
        </div>
      </div>

      {/* KPI strip */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Kpi
          label="Open to resolve"
          value={summary?.allocated_open}
          icon={<CircleDot className="h-4 w-4 text-amber-600" />}
          loading={calendar.isLoading}
        />
        <Kpi
          label="Resolved (all time)"
          value={summary?.resolved}
          icon={<CheckCircle2 className="h-4 w-4 text-emerald-600" />}
          loading={calendar.isLoading}
        />
        <Kpi
          label="Accounts allocated"
          value={summary?.distinct_accounts}
          icon={<Users className="h-4 w-4 text-blue-600" />}
          loading={calendar.isLoading}
        />
        <Kpi
          label={`${monthLabel(month)} resolved`}
          value={summary?.resolved_this_month}
          hint={`${summary?.open_this_month ?? 0} open this month`}
          icon={<CalendarDays className="h-4 w-4 text-indigo-600" />}
          loading={calendar.isLoading}
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
        {/* Month calendar */}
        <div className="rounded-lg border bg-card p-4 shadow-sm">
          <div className="mb-3 flex items-center justify-between gap-2">
            <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => { setMonth(shiftMonth(month, -1)); setSelectedDate(null); }}>
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <div className="text-sm font-semibold">{monthLabel(month)}</div>
            <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => { setMonth(shiftMonth(month, 1)); setSelectedDate(null); }}>
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>

          {calendar.data?.technician && (
            <p className="mb-3 text-xs text-muted-foreground">
              Showing allocations for <strong>{calendar.data.technician.name}</strong>
            </p>
          )}

          <div className="grid grid-cols-7 gap-1 text-center text-[10px] font-semibold uppercase text-muted-foreground">
            {["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((d) => (
              <div key={d} className="py-1">{d}</div>
            ))}
          </div>
          <div className="grid grid-cols-7 gap-1">
            {cells.map((cell, idx) => {
              if (!cell.date) {
                return <div key={`pad-${idx}`} className="min-h-[64px] rounded-md bg-muted/20" />;
              }
              const day = dayMap.get(cell.date);
              const open = day?.open ?? 0;
              const resolved = day?.resolved ?? 0;
              const selected = selectedDate === cell.date;
              const isToday = cell.date === new Date().toISOString().slice(0, 10);
              return (
                <button
                  key={cell.date}
                  type="button"
                  onClick={() => setSelectedDate(selected ? null : cell.date)}
                  className={cn(
                    "min-h-[64px] rounded-md border p-1.5 text-left transition hover:bg-muted/40",
                    selected && "border-primary bg-primary/5 ring-1 ring-primary/30",
                    isToday && !selected && "border-blue-300",
                    open + resolved === 0 && "opacity-70",
                  )}
                >
                  <div className="text-[11px] font-medium">{Number(cell.date.slice(-2))}</div>
                  {(open > 0 || resolved > 0) && (
                    <div className="mt-1 space-y-0.5">
                      {open > 0 && (
                        <div className="rounded bg-amber-500/15 px-1 text-[10px] font-medium text-amber-800 dark:text-amber-200">
                          {open} open
                        </div>
                      )}
                      {resolved > 0 && (
                        <div className="rounded bg-emerald-500/15 px-1 text-[10px] font-medium text-emerald-800 dark:text-emerald-200">
                          {resolved} done
                        </div>
                      )}
                    </div>
                  )}
                </button>
              );
            })}
          </div>
          <p className="mt-3 text-[11px] text-muted-foreground">
            Click a day to filter allocations. Counts use the assignment date (EAT).
          </p>
        </div>

        {/* Accounts panel */}
        <div className="rounded-lg border bg-card p-4 shadow-sm">
          <h2 className="mb-1 text-sm font-semibold">Accounts allocated</h2>
          <p className="mb-3 text-xs text-muted-foreground">
            Customer accounts on your FOL allocations — open vs resolved.
          </p>
          {calendar.isLoading ? (
            <div className="space-y-2">
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
            </div>
          ) : accounts.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">No accounts allocated yet.</p>
          ) : (
            <div className="max-h-[420px] space-y-2 overflow-y-auto">
              {accounts.map((acc) => (
                <div key={acc.customer_acumatica_id} className="rounded-md border px-3 py-2">
                  <div className="font-medium text-sm">{acc.customer_name}</div>
                  <div className="font-mono text-[11px] text-muted-foreground">{acc.customer_acumatica_id}</div>
                  <div className="mt-1.5 flex flex-wrap gap-1.5">
                    <Badge variant="outline" className="border-amber-300 bg-amber-50 text-[10px] text-amber-800">
                      {acc.open} open
                    </Badge>
                    <Badge variant="outline" className="border-emerald-300 bg-emerald-50 text-[10px] text-emerald-800">
                      {acc.resolved} resolved
                    </Badge>
                    <Badge variant="outline" className="text-[10px]">
                      {acc.total} total
                    </Badge>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Day / month allocation list */}
      <div className="rounded-lg border bg-card shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
          <div>
            <h2 className="text-sm font-semibold">
              {selectedDate
                ? `Allocations on ${selectedDate}`
                : `Allocations in ${monthLabel(month)}`}
            </h2>
            <p className="text-xs text-muted-foreground">
              {selectedItems.length} job(s) · open work can be marked resolved when ready
            </p>
          </div>
          {selectedDate && (
            <Button variant="ghost" size="sm" onClick={() => setSelectedDate(null)}>
              Show full month
            </Button>
          )}
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-muted/40">
              <tr>
                <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">FOL</th>
                <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Account</th>
                <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Location</th>
                <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Status</th>
                <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Assigned</th>
                <th className="px-4 py-2.5 text-right text-[11px] font-semibold uppercase text-muted-foreground">Action</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {calendar.isLoading &&
                Array.from({ length: 4 }).map((_, i) => (
                  <tr key={i}>
                    <td colSpan={6} className="px-4 py-3">
                      <Skeleton className="h-5 w-full" />
                    </td>
                  </tr>
                ))}
              {!calendar.isLoading && selectedItems.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-4 py-10 text-center text-sm text-muted-foreground">
                    No allocations for this period.
                  </td>
                </tr>
              )}
              {selectedItems.map((item) => (
                <AllocationRow key={item.id} item={item} canResolve={canExecute || canManage} />
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function Kpi({
  label,
  value,
  hint,
  icon,
  loading,
}: {
  label: string;
  value?: number;
  hint?: string;
  icon: ReactNode;
  loading?: boolean;
}) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="flex items-center justify-between gap-2">
        <div className="text-xs font-medium text-muted-foreground">{label}</div>
        {icon}
      </div>
      {loading ? (
        <Skeleton className="mt-2 h-8 w-16" />
      ) : (
        <div className="mt-1 text-2xl font-semibold tracking-tight">{value ?? 0}</div>
      )}
      {hint && <div className="mt-0.5 text-[11px] text-muted-foreground">{hint}</div>}
    </div>
  );
}

function AllocationRow({ item, canResolve }: { item: FolCalendarItem; canResolve: boolean }) {
  const resolve = useResolveFolTechnician(item.id);
  const open = item.resolve_state === "open";

  async function markResolved() {
    if (!confirm(`Mark ${item.public_ref} (${item.customer_name}) as resolved?`)) return;
    try {
      await resolve.mutateAsync({ comment: "Resolved via technician calendar" });
      toast.success("Allocation marked resolved");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not resolve");
    }
  }

  return (
    <tr className="hover:bg-muted/20">
      <td className="px-4 py-2.5">
        <Link
          to="/app/kp/fol/$id"
          params={{ id: String(item.id) }}
          className="font-medium text-primary hover:underline"
        >
          {item.public_ref}
        </Link>
        <div className="text-[11px] text-muted-foreground">{item.lines_count} line(s)</div>
      </td>
      <td className="px-4 py-2.5">
        <div className="font-medium">{item.customer_name}</div>
        <div className="font-mono text-[11px] text-muted-foreground">{item.customer_acumatica_id}</div>
      </td>
      <td className="px-4 py-2.5 text-xs text-muted-foreground">
        {item.installation_location ? (
          <span className="inline-flex items-start gap-1">
            <MapPin className="mt-0.5 h-3 w-3 shrink-0" />
            {item.installation_location}
          </span>
        ) : (
          "—"
        )}
      </td>
      <td className="px-4 py-2.5">
        <div className="flex flex-col gap-1">
          <Badge variant="outline" className={FOL_STATUS_CLASS[item.status]}>
            {FOL_STATUS_LABEL[item.status] ?? item.status}
          </Badge>
          {item.resolve_state === "resolved" && (
            <span className="text-[10px] font-medium text-emerald-700">Resolved</span>
          )}
          {item.resolve_state === "open" && (
            <span className="text-[10px] font-medium text-amber-700">To resolve</span>
          )}
        </div>
      </td>
      <td className="px-4 py-2.5 text-xs text-muted-foreground">
        {formatFolDate(item.technician_assigned_at)}
      </td>
      <td className="px-4 py-2.5 text-right">
        {canResolve && open && (
          <Button
            size="sm"
            variant="outline"
            className="h-7 text-xs"
            disabled={resolve.isPending}
            onClick={() => void markResolved()}
          >
            <CheckCircle2 className="mr-1 h-3.5 w-3.5" />
            {resolve.isPending ? "Saving…" : "Mark resolved"}
          </Button>
        )}
        {item.resolve_state === "resolved" && (
          <span className="text-xs text-muted-foreground">Done</span>
        )}
      </td>
    </tr>
  );
}
