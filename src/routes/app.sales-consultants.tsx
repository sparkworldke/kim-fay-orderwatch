import { ConsultantLink, DateLink } from "@/components/entity-links";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, Outlet, createFileRoute, useRouterState } from "@tanstack/react-router";
import { BriefcaseBusiness, Download, Eye, Search, UserCheck, Users } from "lucide-react";
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from "react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { MultiSelect } from "@/components/ui/multi-select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { MaskedKES } from "@/components/MaskedCurrency";
import { apiFetch } from "@/lib/api";

export const Route = createFileRoute("/app/sales-consultants")({
  head: () => ({ meta: [{ title: "Sales Consultants - Kim-Fay Sight" }] }),
  component: SalesConsultantsPage,
});

type SalesConsultant = {
  id: number;
  name: string;
  email: string;
  role: string;
  rep_code: string | null;
  employee_number: string | null;
  is_active: boolean;
  assigned_orders: number;
  active_orders: number;
  completed_orders: number;
  assigned_revenue: number;
  last_order_date: string | null;
  outlet_count: number;
  channels: string[];
  regions: string[];
  department: string | null;
  department_role: string | null;
  org_level: string | null;
  reports_to_user_id: number | null;
  outlets: Array<{ id: string; name: string; region: string | null }>;
};

type SalesConsultantsResponse = {
  scope: "team" | "own";
  rep_code: string | null;
  period?: { from: string; to: string; label: string; timezone: string };
  items: SalesConsultant[];
  message?: string;
};

type ConsultantImportSource = "sales_orders" | "acumatica_users";

type ConsultantImportResult = {
  message: string;
  source: ConsultantImportSource;
  requested_rep_code: string | null;
  found: number;
  created: number;
  updated: number;
  skipped: number;
  errors: Array<{ rep_code?: string; message: string }>;
  items: Array<{
    id: number;
    name: string;
    email: string;
    rep_code: string;
    is_active: boolean;
    source_entity: string;
    placeholder_email: boolean;
  }>;
};

function useSalesConsultants(search: string) {
  return useQuery({
    queryKey: ["operations", "sales-consultants", { q: search }],
    queryFn: () => {
      const qs = search.trim() ? `?q=${encodeURIComponent(search.trim())}` : "";
      return apiFetch<SalesConsultantsResponse>(`operations/sales-consultants${qs}`);
    },
  });
}

function SalesConsultantsPage() {
  const pathname = useRouterState({ select: (state) => state.location.pathname });
  if (pathname.replace(/\/$/, "") !== "/app/sales-consultants") {
    return <Outlet />;
  }

  return <SalesConsultantsIndex />;
}

function SalesConsultantsIndex() {
  const [searchInput, setSearchInput] = useState("");
  const [searchDebounced, setSearchDebounced] = useState("");
  const [selectedRegions, setSelectedRegions] = useState<string[]>([]);
  const [selectedCustomers, setSelectedCustomers] = useState<string[]>([]);
  const [selectedConsultants, setSelectedConsultants] = useState<string[]>([]);

  useEffect(() => {
    const timer = setTimeout(() => setSearchDebounced(searchInput), 350);
    return () => clearTimeout(timer);
  }, [searchInput]);

  const { data, isLoading, error } = useSalesConsultants(searchDebounced);
  const items = data?.items ?? [];
  const regionOptions = useMemo(
    () => [...new Set(items.flatMap((item) => item.outlets.map((outlet) => outlet.region).filter((value): value is string => Boolean(value))))].sort(),
    [items],
  );
  const customerOptions = useMemo(() => {
    const outlets = items.flatMap((item) => item.outlets)
      .filter((outlet) => selectedRegions.length === 0 || (outlet.region && selectedRegions.includes(outlet.region)));
    return [...new Map(outlets.map((outlet) => [outlet.id, { value: outlet.id, label: outlet.name }])).values()]
      .sort((a, b) => a.label.localeCompare(b.label));
  }, [items, selectedRegions]);
  const consultantOptions = useMemo(() => items
    .filter((item) => item.outlets.some((outlet) =>
      (selectedRegions.length === 0 || (outlet.region && selectedRegions.includes(outlet.region)))
      && (selectedCustomers.length === 0 || selectedCustomers.includes(outlet.id)),
    ) || (selectedRegions.length === 0 && selectedCustomers.length === 0))
    .map((item) => ({ value: String(item.id), label: item.name }))
    .sort((a, b) => a.label.localeCompare(b.label)), [items, selectedRegions, selectedCustomers]);
  const filteredItems = useMemo(() => {
    const q = searchDebounced.trim().toLowerCase();
    return items.filter((item) => {
      const matchesRegion = selectedRegions.length === 0 || item.outlets.some((outlet) => outlet.region && selectedRegions.includes(outlet.region));
      const matchesCustomer = selectedCustomers.length === 0 || item.outlets.some((outlet) => selectedCustomers.includes(outlet.id));
      const matchesConsultant = selectedConsultants.length === 0 || selectedConsultants.includes(String(item.id));
      const matchesSearch = !q ||
        item.name.toLowerCase().includes(q) ||
        (item.rep_code ?? "").toLowerCase().includes(q) ||
        (item.employee_number ?? "").toLowerCase().includes(q) ||
        item.outlets.some((outlet) => outlet.name.toLowerCase().includes(q) || outlet.id.toLowerCase().includes(q));
      return matchesRegion && matchesCustomer && matchesConsultant && matchesSearch;
    });
  }, [items, searchDebounced, selectedRegions, selectedCustomers, selectedConsultants]);
  const displayItems = filteredItems;
  const totals = displayItems.reduce(
    (acc, item) => ({
      assignedOrders: acc.assignedOrders + item.assigned_orders,
      activeOrders: acc.activeOrders + item.active_orders,
      completedOrders: acc.completedOrders + item.completed_orders,
      assignedRevenue: acc.assignedRevenue + item.assigned_revenue,
    }),
    { assignedOrders: 0, activeOrders: 0, completedOrders: 0, assignedRevenue: 0 },
  );
  const ownProfile = data?.scope === "own";
  const departmentGroups = useMemo(() => {
    const groups = new Map<string, SalesConsultant[]>();
    displayItems.forEach((item) => {
      const department = item.department ?? "Unassigned team";
      groups.set(department, [...(groups.get(department) ?? []), item]);
    });
    return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b));
  }, [displayItems]);

  return (
    <div className="flex flex-col gap-6 p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">
            {ownProfile ? "My Profile" : "My Team"}
          </h1>
          <p className="text-sm text-muted-foreground">
            {ownProfile
              ? "Your profile, portfolio, and current sales activity."
              : "Only members in your reporting hierarchy, grouped by department and channel."}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {data?.period && (
            <Badge variant="outline" className="mt-1">
              {data.period.label}
            </Badge>
          )}
          {ownProfile && data?.rep_code && (
            <Badge variant="secondary" className="mt-1 font-mono">Rep Code {data.rep_code}</Badge>
          )}
        </div>
      </div>

      {error && (
        <div className="rounded border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
          {error instanceof Error ? error.message : "Unable to load sales consultants."}
        </div>
      )}

      <Card className="border-primary/15 bg-muted/10">
        <CardContent className="p-4">
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
              <p className="text-sm font-semibold">Filter reporting tree</p>
              <p className="text-xs text-muted-foreground">Choose one or more regions, outlets, and consultants. Options narrow from left to right.</p>
            </div>
            {(selectedRegions.length > 0 || selectedCustomers.length > 0 || selectedConsultants.length > 0) && (
              <Button variant="ghost" size="sm" onClick={() => {
                setSelectedRegions([]);
                setSelectedCustomers([]);
                setSelectedConsultants([]);
              }}>Clear filters</Button>
            )}
          </div>
          <div className="grid gap-3 md:grid-cols-3">
            <MultiSelect
              label="1. Region"
              options={regionOptions}
              selected={selectedRegions}
              onChange={(values) => {
                setSelectedRegions(values);
                setSelectedCustomers([]);
                setSelectedConsultants([]);
              }}
              emptyLabel="All regions"
              allLabel="All regions"
              searchPlaceholder="Search regions..."
            />
            <MultiSelect
              label="2. Customer / outlet"
              options={customerOptions}
              selected={selectedCustomers}
              onChange={(values) => {
                setSelectedCustomers(values);
                setSelectedConsultants([]);
              }}
              emptyLabel="All outlets"
              allLabel="All outlets"
              searchPlaceholder="Search outlet name or ID..."
            />
            <MultiSelect
              label="3. Consultant"
              options={consultantOptions}
              selected={selectedConsultants}
              onChange={setSelectedConsultants}
              emptyLabel="All consultants"
              allLabel="All consultants"
              searchPlaceholder="Search consultants..."
            />
          </div>
          <div className="mt-3 flex flex-wrap gap-2 text-xs text-muted-foreground">
            <Badge variant="outline">{displayItems.length} consultant(s)</Badge>
            <Badge variant="outline">{new Set(displayItems.flatMap((item) => item.outlets.map((outlet) => outlet.id))).size} outlet(s)</Badge>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard tone="blue" label={ownProfile ? "Profile" : "Team members"} value={displayItems.length} loading={isLoading} icon={Users} />
        <MetricCard tone="violet" label="Orders this period" value={totals.assignedOrders} loading={isLoading} icon={BriefcaseBusiness} />
        <MetricCard tone="emerald" label="Active orders" value={totals.activeOrders} loading={isLoading} icon={UserCheck} />
        <MetricCard tone="amber" label="Revenue this period" value={<MaskedKES value={totals.assignedRevenue} />} loading={isLoading} text />
      </div>

      {!ownProfile && departmentGroups.length > 0 && (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {departmentGroups.map(([department, members], index) => {
            const outlets = members.reduce((total, member) => total + member.outlet_count, 0);
            const channels = [...new Set(members.flatMap((member) => member.channels))];
            return (
              <Card key={department} className={`overflow-hidden border-l-4 ${departmentTone(index)}`}>
                <CardContent className="p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="font-semibold">{department}</p>
                      <p className="mt-1 text-xs text-muted-foreground">{members.length} member(s) · {outlets.toLocaleString("en-KE")} outlets</p>
                    </div>
                    <Badge variant="secondary">Team</Badge>
                  </div>
                  <div className="mt-3 flex flex-wrap gap-1.5">
                    {channels.length ? channels.map((channel) => <ChannelBadge key={channel} channel={channel} />) : <Badge variant="outline">No channel</Badge>}
                  </div>
                </CardContent>
              </Card>
            );
          })}
        </div>
      )}

      <Card className="rounded-lg shadow-sm">
        <CardHeader className="pb-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <CardTitle className="text-base">
              {ownProfile ? "Profile" : "Reporting hierarchy"}
            </CardTitle>
            <div className="relative w-full max-w-xs">
              <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                type="text"
                placeholder="Search name, rep code, or employee number..."
                value={searchInput}
                onChange={(event) => setSearchInput(event.target.value)}
                className="pl-9"
              />
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-2">
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
            </div>
          ) : displayItems.length === 0 ? (
            <div className="rounded border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
              {searchDebounced.trim() ? "No consultants match your search." : (data?.message ?? "No sales consultant profiles found.")}
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Team member</TableHead>
                  <TableHead>Rep Code</TableHead>
                  <TableHead>Emp. No.</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Department / Coverage</TableHead>
                  <TableHead className="text-right">Outlets</TableHead>
                  <TableHead className="text-right">Orders {data?.period ? "(period)" : ""}</TableHead>
                  <TableHead className="text-right">Active</TableHead>
                  <TableHead className="text-right">Completed</TableHead>
                  <TableHead className="text-right">Revenue {data?.period ? "(period)" : ""}</TableHead>
                  <TableHead>Last Order (all-time)</TableHead>
                  <TableHead className="text-right">Details</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {displayItems.map((item) => (
                  <TableRow key={`${item.id}-${item.rep_code ?? "no-rep"}`}>
                    <TableCell>
                      <ConsultantLink consultantId={String(item.id)} repCode={item.rep_code} consultantName={item.name} className="font-medium">
                        <div className="font-medium">{item.name}</div>
                      </ConsultantLink>
                      <div className="text-xs text-muted-foreground">{item.email}</div>
                    </TableCell>
                    <TableCell className="font-mono">
                      <ConsultantLink consultantId={String(item.id)} repCode={item.rep_code} />
                    </TableCell>
                    <TableCell className="font-mono text-xs text-muted-foreground">
                      {item.employee_number ? (
                        <ConsultantLink consultantId={String(item.id)} repCode={item.rep_code}>
                          {item.employee_number}
                        </ConsultantLink>
                      ) : (
                        "-"
                      )}
                    </TableCell>
                    <TableCell>
                      <Badge variant={item.is_active ? "default" : "secondary"}>
                        {item.is_active ? "Active" : "Inactive"}
                      </Badge>
                    </TableCell>
                    <TableCell className="max-w-[260px]">
                      <div className="flex flex-wrap gap-1">
                        <Badge variant="secondary">{item.department ?? "Unassigned"}</Badge>
                        {item.department_role && item.department_role !== "member" ? <Badge variant="outline">{item.department_role.toUpperCase()}</Badge> : null}
                      </div>
                      <div className="mt-1.5 flex flex-wrap gap-1">
                        {item.channels.length ? item.channels.map((channel) => <ChannelBadge key={channel} channel={channel} />) : <Badge variant="outline">No channel</Badge>}
                      </div>
                      {item.regions.length ? <div className="mt-1 text-[10px] text-muted-foreground">{item.regions.join(", ")}</div> : null}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">{item.outlet_count.toLocaleString("en-KE")}</TableCell>
                    <TableCell className="text-right tabular-nums">{item.assigned_orders.toLocaleString("en-KE")}</TableCell>
                    <TableCell className="text-right tabular-nums">{item.active_orders.toLocaleString("en-KE")}</TableCell>
                    <TableCell className="text-right tabular-nums">{item.completed_orders.toLocaleString("en-KE")}</TableCell>
                    <TableCell className="text-right tabular-nums"><MaskedKES value={item.assigned_revenue} /></TableCell>
                    <TableCell>
                      <DateLink value={item.last_order_date} emptyText="-">{formatDate(item.last_order_date)}</DateLink>
                    </TableCell>
                    <TableCell className="text-right">
                      <Button asChild variant="ghost" size="sm">
                        <Link
                          to="/app/sales-consultants/$id"
                          params={{ id: String(item.id) }}
                        >
                          <Eye className="mr-1.5 h-3.5 w-3.5" />
                          View
                        </Link>
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

    </div>
  );
}

function ConsultantImportPanel() {
  const queryClient = useQueryClient();
  const [source, setSource] = useState<ConsultantImportSource>("acumatica_users");
  const [repCode, setRepCode] = useState("");
  const [lastResult, setLastResult] = useState<ConsultantImportResult | null>(null);

  const importer = useMutation({
    mutationFn: (payload: { source: ConsultantImportSource; rep_code?: string }) =>
      apiFetch<ConsultantImportResult>("operations/sales-consultants/import", {
        method: "POST",
        body: payload,
      }),
    onSuccess: (result) => {
      setLastResult(result);
      toast.success(result.message);
      queryClient.invalidateQueries({ queryKey: ["operations", "sales-consultants"] });
    },
    onError: (error) => {
      toast.error(error instanceof Error ? error.message : "Consultant import failed.");
    },
  });

  function importAll() {
    setLastResult(null);
    importer.mutate({ source });
  }

  function importRepCode(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const normalized = repCode.trim();

    if (!normalized) {
      toast.error("Enter a Rep Code to import.");
      return;
    }

    if (!/^[A-Za-z0-9 ._\-/]{1,50}$/.test(normalized)) {
      toast.error("Rep Code can only contain letters, numbers, spaces, period, underscore, hyphen, or slash.");
      return;
    }

    setLastResult(null);
    importer.mutate({ source, rep_code: normalized });
  }

  const sourceLabel = source === "sales_orders" ? "SO assignments" : "Acumatica Users module";

  return (
    <Card className="rounded-lg shadow-sm">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">Import Consultants</CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-3 lg:grid-cols-[220px_minmax(220px,1fr)_auto] lg:items-end">
          <div className="grid gap-1.5">
            <Label htmlFor="consultant-import-source">Source</Label>
            <Select value={source} onValueChange={(value) => setSource(value as ConsultantImportSource)}>
              <SelectTrigger id="consultant-import-source">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="acumatica_users">From Users Module</SelectItem>
                <SelectItem value="sales_orders">From SO</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <form className="grid gap-3 sm:grid-cols-[minmax(180px,1fr)_auto]" onSubmit={importRepCode}>
            <div className="grid gap-1.5">
              <Label htmlFor="consultant-rep-code">Rep Code</Label>
              <Input
                id="consultant-rep-code"
                value={repCode}
                onChange={(event) => setRepCode(event.target.value)}
                placeholder="e.g. P505"
              />
            </div>
            <Button type="submit" variant="outline" disabled={importer.isPending}>
              <Download className="mr-2 h-4 w-4" />
              Import Rep Code
            </Button>
          </form>

          <Button type="button" onClick={importAll} disabled={importer.isPending}>
            <Download className="mr-2 h-4 w-4" />
            {importer.isPending ? "Importing..." : "Import All Consultants"}
          </Button>
        </div>

        <p className="text-xs text-muted-foreground">
          {source === "sales_orders"
            ? "SO import uses local sales order consultant assignments already captured in OrderWatch."
            : "Users Module import tries Consultant, SalesPerson, and User entities from Acumatica."}
        </p>

        {lastResult && (
          <div className={`rounded-md border p-3 text-sm ${lastResult.skipped > 0 ? "border-amber-300 bg-amber-50 text-amber-950" : "bg-muted/20"}`}>
            <div className="font-medium">{lastResult.message}</div>
            <div className="mt-1 text-xs text-muted-foreground">
              Source: {sourceLabel}; found {lastResult.found}; created {lastResult.created}; updated {lastResult.updated}; skipped {lastResult.skipped}.
            </div>
            {lastResult.errors.length > 0 && (
              <div className="mt-2 space-y-1 text-xs">
                {lastResult.errors.slice(0, 5).map((item, index) => (
                  <div key={`${item.rep_code ?? "row"}-${index}`}>
                    <span className="font-mono">{item.rep_code ?? "-"}</span>: {item.message}
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function MetricCard({
  label,
  value,
  loading,
  icon: Icon,
  text = false,
  tone = "blue",
}: {
  label: string;
  value: number | string | ReactNode;
  loading: boolean;
  icon?: typeof Users;
  text?: boolean;
  tone?: "blue" | "violet" | "emerald" | "amber";
}) {
  const tones = {
    blue: "border-blue-200 bg-blue-50/50 dark:border-blue-900 dark:bg-blue-950/20",
    violet: "border-violet-200 bg-violet-50/50 dark:border-violet-900 dark:bg-violet-950/20",
    emerald: "border-emerald-200 bg-emerald-50/50 dark:border-emerald-900 dark:bg-emerald-950/20",
    amber: "border-amber-200 bg-amber-50/50 dark:border-amber-900 dark:bg-amber-950/20",
  };
  return (
    <Card className={`rounded-lg shadow-sm ${tones[tone]}`}>
      <CardContent className="flex items-center justify-between p-4">
        <div>
          <p className="text-sm text-muted-foreground">{label}</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums">
            {loading ? "..." : text ? value : Number(value).toLocaleString("en-KE")}
          </p>
        </div>
        {Icon && <Icon className="h-5 w-5 text-muted-foreground" />}
      </CardContent>
    </Card>
  );
}

function ChannelBadge({ channel }: { channel: string }) {
  const normalized = channel.toUpperCase();
  const tone = normalized === "MT1"
    ? "border-blue-200 bg-blue-100 text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200"
    : normalized === "MT2"
      ? "border-violet-200 bg-violet-100 text-violet-800 dark:border-violet-800 dark:bg-violet-950 dark:text-violet-200"
      : normalized === "KP"
        ? "border-emerald-200 bg-emerald-100 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200"
        : normalized === "GT"
          ? "border-amber-200 bg-amber-100 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
          : "border-slate-200 bg-slate-100 text-slate-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200";
  return <Badge variant="outline" className={tone}>{channel}</Badge>;
}

function departmentTone(index: number) {
  return ["border-l-blue-500", "border-l-violet-500", "border-l-emerald-500", "border-l-amber-500", "border-l-rose-500"][index % 5];
}

function formatMoney(value: number | string | null | undefined) {
  const amount = Number(value ?? 0);

  return `KES ${(Number.isFinite(amount) ? amount : 0).toLocaleString("en-KE", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function formatDate(value: string | null) {
  if (!value) return "-";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString("en-KE", { timeZone: "Africa/Nairobi" });
}
