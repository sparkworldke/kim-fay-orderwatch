import { Link, createFileRoute } from "@tanstack/react-router";
import { CustomerLink } from "@/components/entity-links";
import { Badge } from "@/components/ui/badge";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useMemo, useState } from "react";
import {
  ArrowDown, ArrowLeft, ArrowUp, ArrowUpDown,
  Building2, RefreshCw, Search, Users,
} from "lucide-react";
import {
  flexRender,
  getCoreRowModel,
  getFilteredRowModel,
  getSortedRowModel,
  useReactTable,
  type ColumnDef,
  type SortingState,
} from "@tanstack/react-table";
import { toast } from "sonner";
import { StatusBadge as SharedStatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { apiFetch, ApiError } from "@/lib/api";
import { useCustomers } from "@/hooks/useCustomers";
import { PaginationControls } from "@/components/ui/pagination-controls";
import type { AcumaticaCustomer, PaginatedResponse } from "@/types/admin";

export const Route = createFileRoute("/app/customers")({
  head: () => ({ meta: [{ title: "Customers — Kim-Fay OrderWatch" }] }),
  component: CustomersPage,
});

// -------------------------------------------------------------------------
// Types
// -------------------------------------------------------------------------

interface CategorySummary {
  class: string;
  total: number;
  active: number;
  inactive: number;
  on_hold: number;
  other: number;
}

interface CustomerWithBranches extends AcumaticaCustomer {
  parent_acumatica_id: string | null;
  is_main_account: boolean;
  branches: AcumaticaCustomer[];
  branch_count: number;
}

interface CategoryDetail {
  class: string;
  total: number;
  customers: CustomerWithBranches[];
}

// -------------------------------------------------------------------------
// Hooks
// -------------------------------------------------------------------------

function useCategories() {
  return useQuery({
    queryKey: ["customer-categories"],
    queryFn: () => apiFetch<CategorySummary[]>("customers/categories"),
  });
}

function useCategoryCustomers(cls: string | null) {
  return useQuery({
    queryKey: ["customers-by-category", cls],
    queryFn: () => apiFetch<CategoryDetail>(`customers/by-category/${encodeURIComponent(cls!)}`),
    enabled: cls !== null,
  });
}

// -------------------------------------------------------------------------
// Page
// -------------------------------------------------------------------------

function CustomersPage() {
  const [selectedClass, setSelectedClass]       = useState<string | null>(null);
  const [statFilter, setStatFilter]             = useState<StatCardFilter | null>(null);
  const [search, setSearch]                     = useState("");
  const [globalSearch, setGlobalSearch]         = useState("");
  const [categoryQuickFilter, setCategoryQuickFilter] = useState<"all" | "KP" | "CS">("all");
  const [selectedCustomer, setSelectedCustomer] = useState<AcumaticaCustomer | null>(null);

  const categories = useCategories();
  const detail     = useCategoryCustomers(selectedClass);

  function goBack() {
    setSelectedClass(null);
    setStatFilter(null);
    setSearch("");
    setGlobalSearch("");
    setCategoryQuickFilter("all");
  }

  function handleStatCard(key: StatCardFilter) {
    // Toggle: clicking the same card again clears the filter
    setStatFilter((prev) => (prev === key ? null : key));
    setSelectedClass(null);
    setSearch("");
    setGlobalSearch("");
    setCategoryQuickFilter("all");
  }

  // Derived title/subtitle
  const statLabels: Record<StatCardFilter, string> = {
    all:      "All Accounts",
    active:   "Active Customers",
    inactive: "Inactive Customers",
    on_hold:  "On Hold Customers",
  };
  const isFiltered = statFilter !== null;
  const isCategoryView = !isFiltered && selectedClass;
  const isHomeView = !isFiltered && !selectedClass;

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div className="flex items-center gap-3">
          {(isCategoryView || isFiltered) && (
            <button
              type="button"
              onClick={goBack}
              className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
              <ArrowLeft className="h-4 w-4" /> Back
            </button>
          )}
          <div>
            <h1 className="text-xl font-semibold tracking-tight">
              {isFiltered
                ? statLabels[statFilter!]
                : isCategoryView
                ? selectedClass
                : "Customers"}
            </h1>
            <p className="text-sm text-muted-foreground">
              {isFiltered
                ? "Filtered by account status"
                : isCategoryView
                ? `${detail.data?.total ?? "…"} customers in this category`
                : "Acumatica customer records grouped by category"}
            </p>
          </div>
        </div>
        <Button
          variant="outline"
          size="sm"
          onClick={() => isCategoryView ? detail.refetch() : categories.refetch()}
        >
          <RefreshCw className="mr-1 h-3.5 w-3.5" /> Refresh
        </Button>
      </div>

      {/* Stat cards — always visible, clickable */}
      <CustomerStatCards
        data={categories.data}
        loading={categories.isLoading}
        activeFilter={statFilter}
        onFilter={handleStatCard}
      />

      {/* Global search + KP / CS quick-filter — shown on the home view */}
      {isHomeView && (
        <GlobalCustomerSearch
          search={globalSearch}
          onSearch={(v) => { setGlobalSearch(v); setCategoryQuickFilter("all"); }}
          categoryFilter={categoryQuickFilter}
          onCategoryFilter={(v) => { setCategoryQuickFilter(v); setGlobalSearch(""); }}
          onSelectCustomer={setSelectedCustomer}
        />
      )}

      {/* Stat-filter table — shown when a stat card is active */}
      {isFiltered && (
        <StatFilterTable
          filter={statFilter!}
          onSelectCustomer={setSelectedCustomer}
        />
      )}

      {/* Category card grid — only on home view when no global search / filter active */}
      {isHomeView && globalSearch === "" && categoryQuickFilter === "all" && (
        <CategoryGrid
          data={categories.data}
          loading={categories.isLoading}
          error={categories.isError}
          onSelect={setSelectedClass}
          onRetry={() => categories.refetch()}
        />
      )}

      {/* Category detail — grouped by parent account */}
      {!isFiltered && selectedClass && (
        <CategoryDetailView
          data={detail.data}
          loading={detail.isLoading}
          error={detail.isError}
          search={search}
          onSearch={setSearch}
          onSelectCustomer={setSelectedCustomer}
          onRetry={() => detail.refetch()}
        />
      )}

      {/* Customer detail sheet */}
      <Sheet open={!!selectedCustomer} onOpenChange={(o) => !o && setSelectedCustomer(null)}>
        <SheetContent className="w-full sm:max-w-md overflow-y-auto">
          {selectedCustomer && <CustomerDetailSheet customer={selectedCustomer as CustomerWithBranches} />}
        </SheetContent>
      </Sheet>
    </div>
  );
}

// -------------------------------------------------------------------------
// Top stat cards
// -------------------------------------------------------------------------

const STAT_CARD_CONFIG = [
  {
    key:    "all"      as StatCardFilter,
    label:  "Total Accounts",
    color:  "text-blue-700 dark:text-blue-300",
    bg:     "bg-blue-50 dark:bg-blue-950/40",
    border: "border-blue-200 dark:border-blue-800",
    ring:   "ring-blue-500",
    dot:    "bg-blue-500",
  },
  {
    key:    "active"   as StatCardFilter,
    label:  "Active",
    color:  "text-green-700 dark:text-green-300",
    bg:     "bg-green-50 dark:bg-green-950/40",
    border: "border-green-200 dark:border-green-800",
    ring:   "ring-green-500",
    dot:    "bg-green-500",
  },
  {
    key:    "on_hold"  as StatCardFilter,
    label:  "On Hold",
    color:  "text-amber-700 dark:text-amber-300",
    bg:     "bg-amber-50 dark:bg-amber-950/40",
    border: "border-amber-200 dark:border-amber-800",
    ring:   "ring-amber-500",
    dot:    "bg-amber-500",
  },
  {
    key:    "inactive" as StatCardFilter,
    label:  "Inactive",
    color:  "text-muted-foreground",
    bg:     "bg-muted/30",
    border: "border-muted",
    ring:   "ring-muted-foreground",
    dot:    "bg-muted-foreground/40",
  },
] as const;

function CustomerStatCards({
  data, loading, activeFilter, onFilter,
}: {
  data: CategorySummary[] | undefined;
  loading: boolean;
  activeFilter: StatCardFilter | null;
  onFilter: (key: StatCardFilter) => void;
}) {
  const totals = data
    ? data.reduce(
        (acc, cat) => ({
          total:    acc.total    + cat.total,
          active:   acc.active   + cat.active,
          inactive: acc.inactive + cat.inactive,
          on_hold:  acc.on_hold  + cat.on_hold,
        }),
        { total: 0, active: 0, inactive: 0, on_hold: 0 }
      )
    : null;

  const valueFor = (key: StatCardFilter) => {
    if (!totals) return 0;
    if (key === "all") return totals.total;
    return totals[key] ?? 0;
  };

  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
      {STAT_CARD_CONFIG.map((card) => {
        const isActive = activeFilter === card.key;
        return (
          <button
            key={card.key}
            type="button"
            onClick={() => onFilter(card.key)}
            className={`rounded-lg border p-4 text-left transition-all hover:shadow-md active:scale-[0.98] ${card.bg} ${card.border} ${
              isActive ? `ring-2 ring-offset-1 ${card.ring}` : ""
            }`}
          >
            <div className="flex items-center gap-1.5 mb-2">
              <span className={`h-2 w-2 rounded-full ${card.dot}`} />
              <span className={`text-[11px] font-semibold uppercase tracking-wide ${card.color} opacity-80`}>
                {card.label}
              </span>
              {isActive && (
                <span className="ml-auto text-[9px] font-medium text-muted-foreground">active</span>
              )}
            </div>
            {loading ? (
              <Skeleton className="h-8 w-16" />
            ) : (
              <p className={`text-3xl font-bold tabular-nums ${card.color}`}>
                {valueFor(card.key).toLocaleString()}
              </p>
            )}
            <p className={`mt-1 text-[10px] ${card.color} opacity-0 group-hover:opacity-60 transition-opacity`}>
              {isActive ? "Click to clear" : "Click to view →"}
            </p>
          </button>
        );
      })}
    </div>
  );
}

// -------------------------------------------------------------------------
// Stat filter table — shown when a stat card is clicked
// -------------------------------------------------------------------------

const STATUS_API_VALUE: Record<StatCardFilter, string | undefined> = {
  all:      undefined,
  active:   "Active",
  inactive: "Inactive",
  on_hold:  "On Hold",
};

function StatFilterTable({
  filter,
  onSelectCustomer,
}: {
  filter: StatCardFilter;
  onSelectCustomer: (c: AcumaticaCustomer) => void;
}) {
  const [search, setSearch]   = useState("");
  const [sorting, setSorting] = useState<SortingState>([]);
  const [page, setPage]       = useState(1);
  const [perPage, setPerPage] = useState(50);

  const { data, isLoading, isError, refetch } = useCustomers({
    status:   STATUS_API_VALUE[filter],
    page,
    per_page: perPage,
  });

  const flat: AcumaticaCustomer[] = data?.data ?? [];

  const filtered = useMemo(() => {
    if (!search) return flat;
    const lq = search.toLowerCase();
    return flat.filter(
      (c) =>
        c.name.toLowerCase().includes(lq) ||
        c.acumatica_id.toLowerCase().includes(lq) ||
        (c.email ?? "").toLowerCase().includes(lq) ||
        (c.customer_class ?? "").toLowerCase().includes(lq)
    );
  }, [flat, search]);

  const columns = useMemo<ColumnDef<AcumaticaCustomer>[]>(() => [
    {
      accessorKey: "name",
      header: "Customer Name",
      cell: ({ row }) => (
        <CustomerLink
          customerId={row.original.acumatica_id}
          className="text-sm font-medium"
        >
          {row.original.name}
        </CustomerLink>
      ),
    },
    {
      accessorKey: "acumatica_id",
      header: "Customer ID",
      cell: ({ row }) => (
        <CustomerLink
          customerId={row.original.acumatica_id}
          className="font-mono text-xs text-muted-foreground"
          showId
        />
      ),
    },
    {
      accessorKey: "customer_class",
      header: "Category",
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{row.original.customer_class ?? "—"}</span>
      ),
    },
    {
      accessorKey: "status",
      header: "Status",
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
    {
      accessorKey: "email",
      header: "Email",
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{row.original.email ?? "—"}</span>
      ),
    },
    {
      accessorKey: "phone",
      header: "Phone",
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{row.original.phone ?? "—"}</span>
      ),
    },
    {
      accessorKey: "payment_terms",
      header: "Terms",
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{row.original.payment_terms ?? "—"}</span>
      ),
    },
  ], []);

  const table = useReactTable({
    data: filtered,
    columns,
    state: { sorting },
    onSortingChange: setSorting,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
  });

  if (isLoading) {
    return (
      <div className="space-y-2">
        {Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}
      </div>
    );
  }

  if (isError) {
    return (
      <div className="rounded-lg border bg-card p-8 text-center text-sm text-muted-foreground">
        Failed to load customers.{" "}
        <button className="underline" onClick={() => refetch()}>Retry</button>
      </div>
    );
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-3">
        <Input
          placeholder="Search name, ID, email, category…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="h-8 max-w-xs text-sm"
        />
        <span className="ml-auto text-xs text-muted-foreground">
          {filtered.length} of {flat.length} customers
        </span>
      </div>

      <div className="overflow-x-auto rounded-lg border bg-card shadow-sm">
        <table className="w-full text-sm">
          <thead className="bg-muted/40">
            {table.getHeaderGroups().map((hg) => (
              <tr key={hg.id}>
                {hg.headers.map((h) => (
                  <th
                    key={h.id}
                    className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground whitespace-nowrap"
                  >
                    {h.isPlaceholder ? null : h.column.getCanSort() ? (
                      <button
                        className="flex items-center gap-1 hover:text-foreground transition-colors"
                        onClick={h.column.getToggleSortingHandler()}
                      >
                        {flexRender(h.column.columnDef.header, h.getContext())}
                        {h.column.getIsSorted() === "asc"  && <ArrowUp className="h-3 w-3" />}
                        {h.column.getIsSorted() === "desc" && <ArrowDown className="h-3 w-3" />}
                        {!h.column.getIsSorted()           && <ArrowUpDown className="h-3 w-3 opacity-40" />}
                      </button>
                    ) : (
                      flexRender(h.column.columnDef.header, h.getContext())
                    )}
                  </th>
                ))}
                <th className="px-4 py-2.5" />
              </tr>
            ))}
          </thead>
          <tbody className="divide-y">
            {table.getRowModel().rows.length === 0 ? (
              <tr>
                <td colSpan={columns.length + 1} className="px-4 py-10 text-center text-sm text-muted-foreground">
                  No customers match your search.
                </td>
              </tr>
            ) : (
              table.getRowModel().rows.map((row) => (
                <tr
                  key={row.id}
                  className="cursor-pointer hover:bg-muted/20 transition-colors"
                  onClick={() => onSelectCustomer(row.original)}
                >
                  {row.getVisibleCells().map((cell) => (
                    <td key={cell.id} className="px-4 py-2.5">
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </td>
                  ))}
                  <td className="px-4 py-2.5 text-right text-[10px] text-muted-foreground/50">
                    Details →
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
        <div className="border-t bg-muted/20 px-4 py-2">
          <PaginationControls
            currentPage={data?.current_page ?? 1}
            lastPage={data?.last_page ?? 1}
            total={data?.total ?? 0}
            perPage={perPage}
            onPageChange={setPage}
            onPerPageChange={(s) => { setPerPage(s); setPage(1); }}
          />
        </div>
      </div>
    </div>
  );
}

// -------------------------------------------------------------------------
// Category card grid
// -------------------------------------------------------------------------

const STATUS_PALETTE = [
  "bg-blue-600",   "bg-green-600",  "bg-purple-600",
  "bg-amber-600",  "bg-rose-600",   "bg-cyan-600",
  "bg-teal-600",   "bg-indigo-600", "bg-orange-600",
];

function CategoryGrid({
  data, loading, error, onSelect, onRetry,
}: {
  data: CategorySummary[] | undefined;
  loading: boolean;
  error: boolean;
  onSelect: (cls: string) => void;
  onRetry: () => void;
}) {
  if (loading) {
    return (
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        {Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-44" />)}
      </div>
    );
  }

  if (error) {
    return (
      <div className="rounded-lg border bg-card p-8 text-center text-sm text-muted-foreground">
        Failed to load categories.{" "}
        <button className="underline" onClick={onRetry}>Retry</button>
      </div>
    );
  }

  if (!data || data.length === 0) {
    return (
      <div className="rounded-lg border bg-card p-10 text-center">
        <Users className="mx-auto mb-3 h-8 w-8 text-muted-foreground/40" />
        <p className="text-sm text-muted-foreground">No customers synced yet.</p>
        <p className="mt-1 text-xs text-muted-foreground">
          Go to <strong>Administration → Sync Operations</strong> to import customers.
        </p>
      </div>
    );
  }

  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
      {data.map((cat, idx) => (
        <CategoryCard
          key={cat.class}
          category={cat}
          accentColor={STATUS_PALETTE[idx % STATUS_PALETTE.length]}
          onClick={() => onSelect(cat.class)}
        />
      ))}
    </div>
  );
}

function CategoryCard({
  category: cat, accentColor, onClick,
}: {
  category: CategorySummary;
  accentColor: string;
  onClick: () => void;
}) {
  const pct = (n: number) => cat.total > 0 ? Math.round((n / cat.total) * 100) : 0;

  return (
    <button
      type="button"
      onClick={onClick}
      className="group rounded-xl border bg-card p-5 text-left shadow-sm transition-all hover:shadow-md hover:border-primary/30 active:scale-[0.98]"
    >
      {/* Header */}
      <div className="flex items-start justify-between gap-2 mb-4">
        <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${accentColor}`}>
          <Building2 className="h-5 w-5 text-white" />
        </div>
        <span className="text-2xl font-bold tabular-nums text-foreground">{cat.total.toLocaleString()}</span>
      </div>

      {/* Category name */}
      <h3 className="font-semibold text-sm leading-tight mb-1 truncate">{cat.class}</h3>
      <p className="text-[11px] text-muted-foreground mb-3">
        {cat.total} customer{cat.total !== 1 ? "s" : ""}
      </p>

      {/* Status breakdown */}
      <div className="space-y-1.5">
        <StatusRow label="Active"   count={cat.active}   total={cat.total} color="text-green-600" dot="bg-green-500" />
        <StatusRow label="Inactive" count={cat.inactive} total={cat.total} color="text-muted-foreground" dot="bg-muted-foreground/40" />
        <StatusRow label="On Hold"  count={cat.on_hold}  total={cat.total} color="text-amber-600" dot="bg-amber-500" />
      </div>

      {/* Progress bar */}
      <div className="mt-3 flex h-1.5 w-full overflow-hidden rounded-full bg-muted">
        {cat.active   > 0 && <div className="bg-green-500 h-full"  style={{ width: `${pct(cat.active)}%` }} />}
        {cat.on_hold  > 0 && <div className="bg-amber-500 h-full"  style={{ width: `${pct(cat.on_hold)}%` }} />}
        {cat.inactive > 0 && <div className="bg-muted-foreground/30 h-full" style={{ width: `${pct(cat.inactive)}%` }} />}
      </div>

      <div className="mt-3 text-[10px] text-primary opacity-0 group-hover:opacity-100 transition-opacity font-medium">
        View customers →
      </div>
    </button>
  );
}

function StatusRow({
  label, count, total, color, dot,
}: {
  label: string; count: number; total: number; color: string; dot: string;
}) {
  if (count === 0) return null;
  return (
    <div className="flex items-center justify-between text-xs">
      <div className="flex items-center gap-1.5">
        <span className={`h-1.5 w-1.5 rounded-full ${dot}`} />
        <span className={color}>{label}</span>
      </div>
      <span className="tabular-nums font-medium">{count.toLocaleString()}</span>
    </div>
  );
}

// -------------------------------------------------------------------------
// Category detail — sortable table with status filter
// -------------------------------------------------------------------------

type FlatCustomer = AcumaticaCustomer & {
  parent_acumatica_id: string | null;
  is_main_account: boolean;
  branch_count: number;
  parent_name: string | null;
};

type StatCardFilter = "all" | "active" | "inactive" | "on_hold";
type StatusFilter = "all" | "active" | "inactive" | "on_hold";

const STATUS_TABS: { key: StatusFilter; label: string }[] = [
  { key: "all",      label: "All" },
  { key: "active",   label: "Active" },
  { key: "inactive", label: "Inactive" },
  { key: "on_hold",  label: "On Hold" },
];

function CategoryDetailView({
  data, loading, error, search, onSearch, onSelectCustomer, onRetry,
}: {
  data: CategoryDetail | undefined;
  loading: boolean;
  error: boolean;
  search: string;
  onSearch: (v: string) => void;
  onSelectCustomer: (c: AcumaticaCustomer) => void;
  onRetry: () => void;
}) {
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("all");
  const [sorting, setSorting]           = useState<SortingState>([]);
  const [page, setPage]                 = useState(1);
  const [perPage, setPerPage]           = useState(50);

  // Flatten main accounts + branches into a single list, enrich with parent_name
  const flat = useMemo<FlatCustomer[]>(() => {
    if (!data) return [];
    const rows: FlatCustomer[] = [];
    // Build lookup for parent names
    const nameById: Record<string, string> = {};
    data.customers.forEach((c) => { nameById[c.acumatica_id] = c.name; });

    data.customers.forEach((main) => {
      rows.push({ ...main, parent_name: null });
      (main.branches ?? []).forEach((b) => {
        rows.push({
          ...(b as AcumaticaCustomer),
          parent_acumatica_id: main.acumatica_id,
          is_main_account: false,
          branch_count: 0,
          parent_name: main.name,
        });
      });
    });
    return rows;
  }, [data]);

  const columns = useMemo<ColumnDef<FlatCustomer>[]>(() => [
    {
      accessorKey: "name",
      header: "Customer Name",
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          {row.original.parent_name && (
            <span className="text-muted-foreground/40 text-xs select-none">└</span>
          )}
          <div className="min-w-0">
            <div className="flex items-center gap-1.5 flex-wrap">
              {row.original.parent_name && row.original.parent_acumatica_id ? (
                <Link
                  to="/app/customer-orders/$customerId/branch/$branchId"
                  params={{ customerId: row.original.parent_acumatica_id, branchId: row.original.acumatica_id }}
                  onClick={(e) => e.stopPropagation()}
                  className="text-sm font-medium text-foreground/80 underline-offset-4 hover:underline"
                >
                  {row.original.name}
                </Link>
              ) : (
                <Link
                  to="/app/customer-orders/$customerId"
                  params={{ customerId: row.original.acumatica_id }}
                  onClick={(e) => e.stopPropagation()}
                  className="text-sm font-medium underline-offset-4 hover:underline"
                >
                  {row.original.name}
                </Link>
              )}
              {row.original.is_main_account && (
                <Badge variant="outline" className="text-[9px] border-primary/30 bg-primary/5 text-primary shrink-0">
                  Main
                </Badge>
              )}
              {row.original.branch_count > 0 && (
                <span className="text-[10px] text-muted-foreground shrink-0">
                  {row.original.branch_count} branch{row.original.branch_count !== 1 ? "es" : ""}
                </span>
              )}
            </div>
            {row.original.parent_name && (
              <div className="text-[10px] text-muted-foreground">Branch of {row.original.parent_name}</div>
            )}
          </div>
        </div>
      ),
    },
    {
      accessorKey: "acumatica_id",
      header: "Customer ID",
      cell: ({ row }) => (
        <CustomerLink
          customerId={row.original.acumatica_id}
          className="font-mono text-xs text-muted-foreground"
          showId
        />
      ),
    },
    {
      accessorKey: "status",
      header: "Status",
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
    {
      accessorKey: "email",
      header: "Email",
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{row.original.email ?? "—"}</span>
      ),
    },
    {
      accessorKey: "phone",
      header: "Phone",
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{row.original.phone ?? "—"}</span>
      ),
    },
    {
      accessorKey: "payment_terms",
      header: "Terms",
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{row.original.payment_terms ?? "—"}</span>
      ),
    },
  ], []);

  const filtered = useMemo(() => {
    const lq = search.toLowerCase();
    return flat.filter((c) => {
      // Status filter
      if (statusFilter !== "all") {
        const s = (c.status ?? "").toLowerCase();
        if (statusFilter === "active"   && s !== "active")                return false;
        if (statusFilter === "inactive" && s !== "inactive")              return false;
        if (statusFilter === "on_hold"  && s !== "on hold" && s !== "onhold") return false;
      }
      // Search
      if (lq) {
        return (
          c.name.toLowerCase().includes(lq) ||
          c.acumatica_id.toLowerCase().includes(lq) ||
          (c.email ?? "").toLowerCase().includes(lq) ||
          (c.parent_name ?? "").toLowerCase().includes(lq)
        );
      }
      return true;
    });
  }, [flat, search, statusFilter]);

  // Client-side pagination over the filtered list
  const totalFiltered = filtered.length;
  const lastPage      = Math.max(1, Math.ceil(totalFiltered / perPage));
  const safePage      = Math.min(page, lastPage);
  const pageSlice     = filtered.slice((safePage - 1) * perPage, safePage * perPage);

  const table = useReactTable({
    data: pageSlice,
    columns,
    state: { sorting },
    onSortingChange: setSorting,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
  });

  if (loading) {
    return (
      <div className="space-y-2">
        {Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}
      </div>
    );
  }

  if (error) {
    return (
      <div className="rounded-lg border bg-card p-8 text-center text-sm text-muted-foreground">
        Failed to load customers. <button className="underline" onClick={onRetry}>Retry</button>
      </div>
    );
  }

  if (!data) return null;

  return (
    <div className="space-y-3">
      {/* Filter bar */}
      <div className="flex flex-wrap items-center gap-3">
        {/* Status tabs */}
        <div className="flex rounded-lg border bg-muted/40 p-1 gap-1">
          {STATUS_TABS.map((tab) => {
            const count = tab.key === "all" ? flat.length :
              flat.filter((c) => {
                const s = (c.status ?? "").toLowerCase();
                if (tab.key === "active")   return s === "active";
                if (tab.key === "inactive") return s === "inactive";
                if (tab.key === "on_hold")  return s === "on hold" || s === "onhold";
                return false;
              }).length;
            return (
              <button
                key={tab.key}
                type="button"
                onClick={() => setStatusFilter(tab.key)}
                className={`flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium transition-all ${
                  statusFilter === tab.key
                    ? "bg-background text-foreground shadow-sm"
                    : "text-muted-foreground hover:text-foreground"
                }`}
              >
                {tab.label}
                <span className="rounded-full bg-muted px-1.5 py-0.5 text-[10px] tabular-nums">
                  {count}
                </span>
              </button>
            );
          })}
        </div>

        <Input
          placeholder="Search name, ID, email…"
          value={search}
          onChange={(e) => onSearch(e.target.value)}
          className="h-8 max-w-xs text-sm"
        />

        <span className="text-xs text-muted-foreground ml-auto">
          {filtered.length} of {flat.length} customers
        </span>
      </div>

      {/* Table */}
      <div className="overflow-x-auto rounded-lg border bg-card shadow-sm">
        <table className="w-full text-sm">
          <thead className="bg-muted/40">
            {table.getHeaderGroups().map((hg) => (
              <tr key={hg.id}>
                {hg.headers.map((h) => (
                  <th
                    key={h.id}
                    className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground whitespace-nowrap"
                  >
                    {h.isPlaceholder ? null : h.column.getCanSort() ? (
                      <button
                        className="flex items-center gap-1 hover:text-foreground transition-colors"
                        onClick={h.column.getToggleSortingHandler()}
                      >
                        {flexRender(h.column.columnDef.header, h.getContext())}
                        {h.column.getIsSorted() === "asc"  && <ArrowUp className="h-3 w-3" />}
                        {h.column.getIsSorted() === "desc" && <ArrowDown className="h-3 w-3" />}
                        {!h.column.getIsSorted()           && <ArrowUpDown className="h-3 w-3 opacity-40" />}
                      </button>
                    ) : (
                      flexRender(h.column.columnDef.header, h.getContext())
                    )}
                  </th>
                ))}
                <th className="px-4 py-2.5" />
              </tr>
            ))}
          </thead>
          <tbody className="divide-y">
            {table.getRowModel().rows.length === 0 ? (
              <tr>
                <td colSpan={columns.length + 1} className="px-4 py-10 text-center text-sm text-muted-foreground">
                  No customers match your filters.
                </td>
              </tr>
            ) : (
              table.getRowModel().rows.map((row) => (
                <tr
                  key={row.id}
                  className={`hover:bg-muted/20 transition-colors cursor-pointer ${
                    row.original.parent_name ? "bg-muted/5" : ""
                  }`}
                  onClick={() => onSelectCustomer(row.original)}
                >
                  {row.getVisibleCells().map((cell) => (
                    <td key={cell.id} className="px-4 py-2.5">
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </td>
                  ))}
                  <td className="px-4 py-2.5 text-right">
                    <span className="text-[10px] text-muted-foreground/50 hover:text-primary">Details →</span>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
        <div className="border-t bg-muted/20 px-4 py-2">
          <PaginationControls
            currentPage={safePage}
            lastPage={lastPage}
            total={totalFiltered}
            perPage={perPage}
            onPageChange={setPage}
            onPerPageChange={(s) => { setPerPage(s); setPage(1); }}
          />
        </div>
      </div>
    </div>
  );
}

// -------------------------------------------------------------------------
// Customer detail sheet
// -------------------------------------------------------------------------

function CustomerDetailSheet({ customer }: { customer: CustomerWithBranches }) {
  const qc = useQueryClient();

  const setParent = useMutation({
    mutationFn: (payload: { parent_acumatica_id: string | null; is_main_account: boolean }) =>
      apiFetch(`customers/${customer.acumatica_id}/set-parent`, { method: "PATCH", body: payload }),
    onSuccess: () => {
      toast.success("Hierarchy updated");
      qc.invalidateQueries({ queryKey: ["customers-by-category"] });
      qc.invalidateQueries({ queryKey: ["customer-categories"] });
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.message : "Update failed"),
  });

  const [parentInput, setParentInput] = useState(customer.parent_acumatica_id ?? "");

  return (
    <>
      <SheetHeader>
        <SheetTitle className="flex items-center gap-2">
          {customer.name}
          {customer.is_main_account && (
            <Badge variant="outline" className="text-[10px] border-primary/30 bg-primary/5 text-primary">
              Main Account
            </Badge>
          )}
        </SheetTitle>
        <SheetDescription className="font-mono text-xs">{customer.acumatica_id}</SheetDescription>
      </SheetHeader>

      <Button asChild size="sm" variant="outline" className="mt-4">
        {customer.parent_acumatica_id ? (
          <Link
            to="/app/customer-orders/$customerId/branch/$branchId"
            params={{ customerId: customer.parent_acumatica_id, branchId: customer.acumatica_id }}
          >
            View order history →
          </Link>
        ) : (
          <Link to="/app/customer-orders/$customerId" params={{ customerId: customer.acumatica_id }}>
            View order history →
          </Link>
        )}
      </Button>

      <div className="mt-6 grid grid-cols-2 gap-3 text-sm">
        {[
          ["Category",      customer.customer_class ?? "—"],
          ["Status",        customer.status ?? "—"],
          ["Email",         customer.email ?? "—"],
          ["Phone",         customer.phone ?? "—"],
          ["Payment Terms", customer.payment_terms ?? "—"],
          ["Tax Zone",      customer.tax_zone ?? "—"],
        ].map(([k, v]) => (
          <div key={k as string} className="rounded-md border bg-muted/30 p-2.5">
            <div className="text-[10px] uppercase tracking-wide text-muted-foreground">{k}</div>
            <div className="mt-0.5 text-sm font-medium break-all">{v}</div>
          </div>
        ))}
      </div>

      {/* Branches */}
      {customer.branches && customer.branches.length > 0 && (
        <div className="mt-5">
          <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Branches ({customer.branches.length})
          </h4>
          <div className="space-y-1.5">
            {customer.branches.map((b) => (
              <div key={b.acumatica_id} className="flex items-center justify-between rounded-md border bg-muted/20 px-3 py-2 text-sm">
                <div>
                  <div className="font-medium">{b.name}</div>
                  <div className="font-mono text-[11px] text-muted-foreground">{b.acumatica_id}</div>
                </div>
                <StatusBadge status={b.status} />
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Hierarchy editor */}
      <div className="mt-5 rounded-lg border p-3">
        <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Hierarchy</h4>
        <div className="flex items-center gap-2">
          <Input
            value={parentInput}
            onChange={(e) => setParentInput(e.target.value)}
            placeholder="Parent account ID e.g. CUST100664"
            className="text-xs h-8"
          />
          <Button
            size="sm"
            className="h-8 shrink-0"
            disabled={setParent.isPending}
            onClick={() =>
              setParent.mutate({
                parent_acumatica_id: parentInput.trim() || null,
                is_main_account: !parentInput.trim(),
              })
            }
          >
            Save
          </Button>
        </div>
        <p className="mt-1.5 text-[10px] text-muted-foreground">
          Leave blank to mark this as a main / standalone account.
        </p>
      </div>

      {customer.synced_at && (
        <p className="mt-5 text-xs text-muted-foreground">
          Last synced: {new Date(customer.synced_at).toLocaleString("en-KE", { timeZone: "Africa/Nairobi" })}
        </p>
      )}
    </>
  );
}

// -------------------------------------------------------------------------
// Global customer search with KP / CS quick-filter chips
// Shown on the home view (no stat card active, no category selected)
// -------------------------------------------------------------------------

type CategoryQuickFilter = "all" | "KP" | "CS";

function GlobalCustomerSearch({
  search,
  onSearch,
  categoryFilter,
  onCategoryFilter,
  onSelectCustomer,
}: {
  search: string;
  onSearch: (v: string) => void;
  categoryFilter: CategoryQuickFilter;
  onCategoryFilter: (v: CategoryQuickFilter) => void;
  onSelectCustomer: (c: AcumaticaCustomer) => void;
}) {
  const [page, setPage]       = useState(1);
  const [perPage, setPerPage] = useState(50);

  // Derive the class prefix to pass to the API
  const classPrefix: string | undefined =
    categoryFilter === "KP" ? "KP" :
    categoryFilter === "CS" ? "CS" :
    undefined;

  const { data, isLoading, isError, refetch } = useCustomers({
    q:            search || undefined,
    class_prefix: classPrefix,
    page,
    per_page: perPage,
  });

  // Only show results when a search term is typed or a category filter is active
  const showResults = search.trim() !== "" || categoryFilter !== "all";

  const QUICK_FILTERS: { key: CategoryQuickFilter; label: string; description: string }[] = [
    { key: "KP",  label: "KP",             description: "Kimfay Professional accounts" },
    { key: "CS",  label: "Consumer Sales",  description: "Consumer Sales accounts" },
  ];

  return (
    <div className="space-y-3">
      {/* Search bar + quick-filter chips */}
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative flex-1 min-w-[220px] max-w-md">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            placeholder="Search by customer name or ID…"
            value={search}
            onChange={(e) => onSearch(e.target.value)}
            className="pl-8 h-9 text-sm"
          />
        </div>

        {/* Quick-filter chips */}
        <div className="flex items-center gap-1.5">
          <span className="text-xs text-muted-foreground">Category:</span>
          {QUICK_FILTERS.map((chip) => (
            <button
              key={chip.key}
              type="button"
              title={chip.description}
              onClick={() => onCategoryFilter(categoryFilter === chip.key ? "all" : chip.key)}
              className={`rounded-full border px-3 py-1 text-xs font-medium transition-all ${
                categoryFilter === chip.key
                  ? "bg-primary text-primary-foreground border-primary"
                  : "border-muted bg-muted/40 text-muted-foreground hover:border-primary/50 hover:text-foreground"
              }`}
            >
              {chip.label}
            </button>
          ))}
          {categoryFilter !== "all" && (
            <button
              type="button"
              onClick={() => onCategoryFilter("all")}
              className="text-[10px] text-muted-foreground hover:text-foreground underline"
            >
              Clear
            </button>
          )}
        </div>

        {showResults && data && (
          <span className="ml-auto text-xs text-muted-foreground">
            {data.total} customer{data.total !== 1 ? "s" : ""}
          </span>
        )}
      </div>

      {/* Results table — only shown when actively searching or filtering */}
      {showResults && (
        <div className="rounded-lg border bg-card shadow-sm overflow-x-auto">
          {isLoading ? (
            <div className="space-y-2 p-4">
              {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}
            </div>
          ) : isError ? (
            <div className="p-6 text-center text-sm text-muted-foreground">
              Failed to load customers.{" "}
              <button className="underline" onClick={() => refetch()}>Retry</button>
            </div>
          ) : (data?.data ?? []).length === 0 ? (
            <div className="p-8 text-center text-sm text-muted-foreground">
              No customers match your search.
            </div>
          ) : (
            <>
              <table className="w-full text-sm">
                <thead className="bg-muted/40">
                  <tr>
                    <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Customer</th>
                    <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Category</th>
                    <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Status</th>
                    <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Email</th>
                    <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Phone</th>
                    <th className="px-4 py-2.5" />
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {(data?.data ?? []).map((c) => (
                    <tr
                      key={c.acumatica_id}
                      className="cursor-pointer hover:bg-muted/20 transition-colors"
                      onClick={() => onSelectCustomer(c)}
                    >
                      <td className="px-4 py-2.5">
                        <CustomerLink customerId={c.acumatica_id} className="text-sm font-medium">
                          {c.name}
                        </CustomerLink>
                        <div className="font-mono text-[11px] text-muted-foreground">{c.acumatica_id}</div>
                      </td>
                      <td className="px-4 py-2.5 text-xs text-muted-foreground">{c.customer_class ?? "—"}</td>
                      <td className="px-4 py-2.5"><StatusBadge status={c.status} /></td>
                      <td className="px-4 py-2.5 text-xs text-muted-foreground">{c.email ?? "—"}</td>
                      <td className="px-4 py-2.5 text-xs text-muted-foreground">{c.phone ?? "—"}</td>
                      <td className="px-4 py-2.5 text-right text-[10px] text-muted-foreground/50">Details →</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <div className="border-t bg-muted/20 px-4 py-2">
                <PaginationControls
                  currentPage={data?.current_page ?? 1}
                  lastPage={data?.last_page ?? 1}
                  total={data?.total ?? 0}
                  perPage={perPage}
                  onPageChange={setPage}
                  onPerPageChange={(s) => { setPerPage(s); setPage(1); }}
                />
              </div>
            </>
          )}
        </div>
      )}
    </div>
  );
}

// -------------------------------------------------------------------------
// Shared
// -------------------------------------------------------------------------

function StatusBadge({ status }: { status: string | null }) {
  if (!status) return <span className="text-muted-foreground text-[10px]">—</span>;
  return <SharedStatusBadge status={status} />;
}
