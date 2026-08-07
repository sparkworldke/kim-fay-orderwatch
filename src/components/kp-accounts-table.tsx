import { Link } from "@tanstack/react-router";
import { useMemo, useState, type ReactNode } from "react";
import {
  Building2,
  CalendarPlus,
  ClipboardList,
  Eye,
  History,
  MoreHorizontal,
  RefreshCw,
  Search,
  UserRound,
} from "lucide-react";
import { toast } from "sonner";
import { CustomerContactsCard } from "@/components/customer-contacts-card";
import { CustomerLink } from "@/components/entity-links";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { PaginationControls } from "@/components/ui/pagination-controls";
import { useAuth } from "@/lib/auth";
import { useCapabilities } from "@/hooks/useCapabilities";
import { useKpAccounts, type KpAccount } from "@/hooks/useKpAccounts";

export type KpAccountsTableProps = {
  title: string;
  description: string;
  searchPlaceholder?: string;
  emptyTitle?: string;
  emptyHint?: string;
  /** Exact Acumatica customer class (e.g. KPCCLNRS). Omit for default KP%. */
  customerClass?: string;
  /** Exclude a class from default KP% portfolio (e.g. KPCCLNRS). */
  excludeClass?: string;
  /** List every customer class in the portfolio (not only KP%). Used by My Portfolio. */
  allClasses?: boolean;
  badgeLabel?: string;
  icon?: ReactNode;
  /** "self" restricts to the caller's own book even if they'd otherwise see their whole team. */
  mode?: "auto" | "self" | "team";
  /** Scope to one team member's book — used when expanding a rep row in the My Team accordion. */
  repCode?: string;
  /** Hide the title/description block — for embedding inside another card (e.g. an accordion row). */
  hideHeader?: boolean;
};

function formatDate(value: string | null | undefined): string {
  if (!value) return "—";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value.slice(0, 10);
  return d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

function scopeLabel(scope: string | undefined, role: string | undefined): string {
  if (scope === "my_portfolio") return "Your assigned portfolio";
  if (scope === "all_kp") return "All matching customers";
  if (scope === "scoped") return "Your team / sector portfolio";
  if (role === "Sales Consultant") return "Your assigned portfolio";
  return "Customers in your scope";
}

export function KpAccountsTable({
  title,
  description,
  searchPlaceholder = "Search account name, ID, email…",
  emptyTitle = "No accounts found",
  emptyHint,
  customerClass,
  excludeClass,
  allClasses = false,
  badgeLabel = "accounts",
  icon,
  mode,
  repCode,
  hideHeader = false,
}: KpAccountsTableProps) {
  const { session } = useAuth();
  const caps = useCapabilities();
  const permissions = caps.permissions ?? [];
  const canRequestFol = permissions.includes("kp.fol.request") || session?.role === "Administrator";

  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(hideHeader ? 10 : 20);
  const [contactsAccount, setContactsAccount] = useState<KpAccount | null>(null);

  const list = useKpAccounts({
    q,
    page,
    per_page: perPage,
    customer_class: customerClass,
    exclude_class: excludeClass,
    all_classes: allClasses,
    mode,
    rep_code: repCode,
  });
  const accounts = list.data?.data ?? [];
  const total = list.data?.total ?? 0;
  const lastPage = list.data?.last_page ?? 1;

  const subtitle = useMemo(
    () => scopeLabel(list.data?.scope, session?.role),
    [list.data?.scope, session?.role],
  );

  function placeholderMeeting(account: KpAccount) {
    toast.message("Create Meeting — coming soon", {
      description: `Meeting scheduling for ${account.name} is not available yet.`,
    });
  }

  const defaultEmptyHint =
    emptyHint ??
    (session?.role === "Sales Consultant"
      ? "No customers in this class are attached to your portfolio yet."
      : "No customers in this class for your scope.");

  return (
    <div className="space-y-5">
      {!hideHeader && (
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">KP CRM</p>
            <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
            <p className="text-sm text-muted-foreground">
              {description} {subtitle}. Sales Consultants see only accounts attached to them.
            </p>
          </div>
          <Button variant="outline" size="sm" onClick={() => list.refetch()} disabled={list.isFetching}>
            <RefreshCw className={`mr-1 h-3.5 w-3.5 ${list.isFetching ? "animate-spin" : ""}`} />
            Refresh
          </Button>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-[240px] max-w-md flex-1">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            value={q}
            onChange={(e) => {
              setQ(e.target.value);
              setPage(1);
            }}
            placeholder={searchPlaceholder}
            className="h-9 pl-8"
          />
        </div>
        <Badge variant="secondary" className="tabular-nums">
          {total.toLocaleString()} {badgeLabel}
        </Badge>
        {customerClass && (
          <Badge variant="outline" className="font-mono text-[10px]">{customerClass}</Badge>
        )}
        {list.data?.scope === "my_portfolio" && (
          <Badge variant="outline">Portfolio scoped</Badge>
        )}
      </div>

      <div className="overflow-x-auto rounded-lg border bg-card shadow-sm">
        <table className="w-full text-sm">
          <thead className="bg-muted/40">
            <tr>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Account</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Class</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Status</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Contacts</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Last order</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Contact</th>
              <th className="px-4 py-2.5 text-right text-[11px] font-semibold uppercase text-muted-foreground">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {list.isLoading &&
              Array.from({ length: 8 }).map((_, i) => (
                <tr key={i}>
                  <td colSpan={7} className="px-4 py-3">
                    <Skeleton className="h-5 w-full" />
                  </td>
                </tr>
              ))}

            {!list.isLoading && accounts.length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-12 text-center text-muted-foreground">
                  {icon ?? <Building2 className="mx-auto mb-2 h-8 w-8 opacity-30" />}
                  <p className="text-sm font-medium text-foreground">{emptyTitle}</p>
                  <p className="mt-1 text-xs">
                    {q.trim() ? "Try a different search." : defaultEmptyHint}
                  </p>
                </td>
              </tr>
            )}

            {accounts.map((account) => (
              <tr key={account.acumatica_id} className="hover:bg-muted/20">
                <td className="px-4 py-3">
                  <CustomerLink customerId={account.acumatica_id} customerName={account.name} className="block">
                    <div className="font-medium leading-tight">{account.name}</div>
                    <div className="font-mono text-[11px] text-muted-foreground">{account.acumatica_id}</div>
                  </CustomerLink>
                </td>
                <td className="px-4 py-3">
                  <Badge variant="outline" className="font-mono text-[10px]">
                    {account.customer_class ?? "KP"}
                  </Badge>
                </td>
                <td className="px-4 py-3">
                  <StatusPill status={account.status} />
                </td>
                <td className="px-4 py-3 tabular-nums text-muted-foreground">
                  {account.contact_count}
                </td>
                <td className="px-4 py-3 text-xs text-muted-foreground">
                  {formatDate(account.last_order_date)}
                </td>
                <td className="px-4 py-3 text-xs text-muted-foreground">
                  <div className="max-w-[10rem] truncate">{account.email || "—"}</div>
                  <div className="max-w-[10rem] truncate">{account.phone || ""}</div>
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="flex flex-wrap items-center justify-end gap-1">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-8"
                      onClick={() => setContactsAccount(account)}
                    >
                      <UserRound className="mr-1 h-3.5 w-3.5" />
                      Contacts
                    </Button>
                    <Button asChild variant="outline" size="sm" className="h-8">
                      <Link
                        to="/app/customer-orders/$customerId"
                        params={{ customerId: account.acumatica_id }}
                      >
                        <History className="mr-1 h-3.5 w-3.5" />
                        History
                      </Link>
                    </Button>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button type="button" variant="ghost" size="icon" className="h-8 w-8">
                          <MoreHorizontal className="h-4 w-4" />
                          <span className="sr-only">More actions</span>
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end" className="w-52">
                        <DropdownMenuLabel>Account actions</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={() => setContactsAccount(account)}>
                          <UserRound className="mr-2 h-4 w-4" />
                          View Contact
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                          <Link
                            to="/app/customer-orders/$customerId"
                            params={{ customerId: account.acumatica_id }}
                          >
                            <History className="mr-2 h-4 w-4" />
                            View Purchase History
                          </Link>
                        </DropdownMenuItem>
                        {canRequestFol ? (
                          <DropdownMenuItem asChild>
                            <Link
                              to="/app/kp/fol/new"
                              search={{ customer: account.acumatica_id, name: account.name }}
                            >
                              <ClipboardList className="mr-2 h-4 w-4" />
                              Request FOL
                            </Link>
                          </DropdownMenuItem>
                        ) : (
                          <DropdownMenuItem
                            disabled
                            onClick={() => toast.error("You do not have permission to request FOL.")}
                          >
                            <ClipboardList className="mr-2 h-4 w-4" />
                            Request FOL
                          </DropdownMenuItem>
                        )}
                        <DropdownMenuItem onClick={() => placeholderMeeting(account)}>
                          <CalendarPlus className="mr-2 h-4 w-4" />
                          Create Meeting
                          <Badge variant="secondary" className="ml-auto text-[9px]">Soon</Badge>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                          <Link
                            to="/app/customer-orders/$customerId"
                            params={{ customerId: account.acumatica_id }}
                          >
                            <Eye className="mr-2 h-4 w-4" />
                            View Customer
                          </Link>
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {(lastPage > 1 || total > 0) && (
        <PaginationControls
          currentPage={page}
          lastPage={lastPage}
          total={total}
          perPage={perPage}
          onPageChange={setPage}
          onPerPageChange={(size) => {
            setPerPage(size);
            setPage(1);
          }}
        />
      )}

      <Sheet open={!!contactsAccount} onOpenChange={(open) => !open && setContactsAccount(null)}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
          <SheetHeader>
            <SheetTitle>Contacts — {contactsAccount?.name}</SheetTitle>
            <SheetDescription className="font-mono text-xs">
              {contactsAccount?.acumatica_id}
            </SheetDescription>
          </SheetHeader>
          <div className="mt-4">
            {contactsAccount && (
              <CustomerContactsCard customerId={contactsAccount.acumatica_id} />
            )}
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}

function StatusPill({ status }: { status: string | null }) {
  const s = (status ?? "").toLowerCase();
  const tone =
    s === "active"
      ? "border-success/40 bg-success/10 text-success"
      : s === "inactive" || s === "on hold" || s.includes("hold")
        ? "border-warning/40 bg-warning/10 text-warning-foreground"
        : "border-muted bg-muted/40 text-muted-foreground";

  return (
    <Badge variant="outline" className={`text-[10px] capitalize ${tone}`}>
      {status || "Unknown"}
    </Badge>
  );
}
