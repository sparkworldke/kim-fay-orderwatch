import { Link, Outlet, createFileRoute, useParams, useRouterState } from "@tanstack/react-router";
import { ArrowLeft, FileText, Package, ClipboardList } from "lucide-react";
import { useMemo, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import {
  BranchesCard,
  CommonProductsCard,
  CustomerBackorderCard,
  DocumentsTable,
  EmptyBlock,
  ErrorBlock,
  MetricsGrid,
  SkeletonRows,
  SuggestedOrdersCard,
  summarizeDocuments,
} from "@/components/customer-orders-shared";
import { CustomerContactsCard } from "@/components/customer-contacts-card";
import { PaginationControls } from "@/components/ui/pagination-controls";
import { useCommonProducts, useSuggestedOrders } from "@/hooks/useCustomers";
import { useOrders, type OrderFilters } from "@/hooks/useOrders";
import { usePagination } from "@/hooks/usePagination";
import { apiFetch, ApiError } from "@/lib/api";
import type { AcumaticaCustomer } from "@/types/admin";
import { useQuery } from "@tanstack/react-query";

export const Route = createFileRoute("/app/customer-orders/$customerId")({
  head: () => ({ meta: [{ title: "Customer Documents - Kim-Fay Sight" }] }),
  component: CustomerDocumentsPage,
});

type SortOption = NonNullable<OrderFilters["sort"]>;

const WHITESPOT_PAGE_SIZE = 8;
const COMMON_PRODUCTS_PAGE_SIZE = 20;

function CustomerProfile({ customer }: { customer: AcumaticaCustomer }) {
  const extra = customer.customer_data;
  const fields: Array<[string, unknown]> = [
    ["Customer ID", customer.acumatica_id], ["Status", customer.status], ["Customer class", customer.customer_class],
    ["Customer group", extra?.customer_group], ["Category", extra?.category], ["Region", extra?.customer_region],
    ["Parent", customer.parent ? `${customer.parent.name} (${customer.parent.acumatica_id})` : customer.parent_acumatica_id],
    ["Terms", customer.payment_terms], ["Credit limit", extra?.credit_limit ? `${extra.currency_id ?? "KES"} ${Number(extra.credit_limit).toLocaleString()}` : null],
    ["Price class", [extra?.price_class_id, extra?.price_class_name].filter(Boolean).join(" — ")],
    ["Statement", [extra?.statement_type, extra?.statement_cycle].filter(Boolean).join(" / ")], ["Shipping rule", extra?.shipping_rule],
    ["Delivery", extra?.delivery], ["Sage code", extra?.sage_code], ["Business account ID", extra?.business_account_id],
    ["Tax registration ID", extra?.tax_registration_id], ["Main A/C owner", extra?.main_ac_owner],
    ["Rep / Sales rep", [extra?.rep_code, extra?.sales_rep].filter(Boolean).join(" — ")],
    ["Sight consultant", customer.servicing_consultant ? `${customer.servicing_consultant.name}${customer.servicing_consultant.rep_code ? ` (${customer.servicing_consultant.rep_code})` : ""}` : null],
    ["Route", [extra?.route_code, customer.route?.route_name].filter(Boolean).join(" — ")],
    ["Zone", [extra?.shipping_zone_id, extra?.customer_zone].filter(Boolean).join(" — ")],
    ["Address", [extra?.address_line_1, extra?.address_line_2, extra?.address_line_3, extra?.city, extra?.country].filter(Boolean).join(", ")],
    ["Company email", extra?.email ?? customer.email], ["Company phone", customer.phone],
    ["Source", extra?.source], ["Last synced", extra?.synced_at ? new Date(extra.synced_at).toLocaleString() : customer.synced_at ? new Date(customer.synced_at).toLocaleString() : null],
  ];
  return <Card className="rounded-lg shadow-sm"><CardHeader className="pb-3"><CardTitle className="text-base">Customer profile</CardTitle></CardHeader><CardContent className="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">{fields.map(([label, value]) => <div key={label}><p className="text-xs font-medium uppercase text-muted-foreground">{label}</p><p className="text-sm">{String(value || "—")}</p></div>)}</CardContent></Card>;
}

/** Local (non-UTC) date string in YYYY-MM-DD, matching <input type="date">. */
function toDateInputValue(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

/** Default filter range: first of the current month through today. */
function monthToDateRange() {
  const now = new Date();
  return {
    from: toDateInputValue(new Date(now.getFullYear(), now.getMonth(), 1)),
    to: toDateInputValue(now),
  };
}

function CustomerDocumentsPage() {
  const { customerId } = useParams({ from: "/app/customer-orders/$customerId" });
  const pathname = useRouterState({ select: (state) => state.location.pathname });
  const basePath = `/app/customer-orders/${customerId}`;
  if (pathname.replace(/\/$/, "") !== basePath) {
    return <Outlet />;
  }

  return <CustomerDocumentsIndex customerId={customerId} />;
}

function CustomerDocumentsIndex({ customerId }: { customerId: string }) {
  const [dateFrom, setDateFrom] = useState(() => monthToDateRange().from);
  const [dateTo, setDateTo] = useState(() => monthToDateRange().to);
  const [sort, setSort] = useState<SortOption>("latest");
  const { page: docsPage, perPage: docsPerPage, setPage: setDocsPage, setPerPage: setDocsPerPage } = usePagination(20);

  const customer = useQuery({
    queryKey: ["customers", customerId],
    queryFn: () => apiFetch<AcumaticaCustomer>(`customers/${encodeURIComponent(customerId)}`),
    retry: (failureCount, error) => !(error instanceof ApiError && error.status === 404) && failureCount < 2,
  });

  const orders = useOrders({
    customer_id: customerId,
    order_type: "ALL",
    with_fulfillment: true,
    sort,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
    page: docsPage,
    per_page: docsPerPage,
  });

  const commonProducts = useCommonProducts(customerId);
  const suggestedOrders = useSuggestedOrders(customerId);
  const docs = orders.data?.data ?? [];
  const summary = useMemo(() => summarizeDocuments(docs), [docs]);
  const branches = customer.data?.branches ?? [];

  // Reset page when filters change
  const filtersKey = `${sort}|${dateFrom}|${dateTo}`;
  const [lastFiltersKey, setLastFiltersKey] = useState(filtersKey);
  if (filtersKey !== lastFiltersKey) {
    setLastFiltersKey(filtersKey);
    if (docsPage !== 1) setDocsPage(1);
  }

  if (customer.isError && customer.error instanceof ApiError && customer.error.status === 404) {
    return (
      <div className="flex flex-col gap-4 p-6">
        <Button asChild variant="ghost" size="sm" className="-ml-2">
          <Link to="/app/customers">
            <ArrowLeft className="mr-2 h-4 w-4" />
            Customers
          </Link>
        </Button>
        <ErrorBlock message={`Customer "${customerId}" was not found.`} onRetry={() => customer.refetch()} />
      </div>
    );
  }

  const docsTotal = orders.data?.total ?? 0;
  const docsLastPage = orders.data?.last_page ?? 1;
  const commonProductsCount = commonProducts.data?.products.length ?? 0;
  const whitespotCount = suggestedOrders.data?.suggestions.length ?? 0;

  return (
    <div className="flex flex-col gap-6 p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Button asChild variant="ghost" size="sm" className="-ml-2 mb-2">
            <Link to="/app/customers">
              <ArrowLeft className="mr-2 h-4 w-4" />
              Customers
            </Link>
          </Button>
          <h1 className="text-2xl font-semibold tracking-tight">
            {customer.isLoading ? "Customer" : customer.data?.name ?? customerId}
          </h1>
          <p className="font-mono text-xs text-muted-foreground">{customerId}</p>
        </div>
        <div className="flex gap-2">{customer.data?.customer_data?.customer_group && <Badge>{customer.data.customer_data.customer_group}</Badge>}{customer.data?.customer_class && <Badge variant="secondary">{customer.data.customer_class}</Badge>}</div>
      </div>

      {branches.length > 0 && <BranchesCard customerId={customerId} branches={branches} />}

      {customer.data && <CustomerProfile customer={customer.data} />}

      <CustomerContactsCard customerId={customerId} health={customer.data?.contact_health ? { hasPhone: customer.data.contact_health.has_phone, hasEmail: customer.data.contact_health.has_email, hasPrimary: customer.data.contact_health.has_primary } : undefined} />

      <MetricsGrid summary={summary} loading={orders.isLoading} />

      <Card className="rounded-lg shadow-sm">
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Filters</CardTitle>
          <p className="text-sm text-muted-foreground">Defaults to the current month to date.</p>
        </CardHeader>
        <CardContent className="grid gap-3 sm:grid-cols-3">
          <div className="grid gap-1.5">
            <Label>From</Label>
            <Input type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} />
          </div>
          <div className="grid gap-1.5">
            <Label>To</Label>
            <Input type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} />
          </div>
          <div className="grid gap-1.5">
            <Label>Sort</Label>
            <Select value={sort} onValueChange={(value) => setSort(value as SortOption)}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="latest">Newest first</SelectItem>
                <SelectItem value="oldest">Oldest first</SelectItem>
                <SelectItem value="amount_desc">Amount: high to low</SelectItem>
                <SelectItem value="amount_asc">Amount: low to high</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {/* Backorder card: grouped by InventoryID → accordion of SO numbers (MTD by default). */}
      <CustomerBackorderCard
        customerId={customerId}
        dateFrom={dateFrom || undefined}
        dateTo={dateTo || undefined}
        includeBranches
      />

      <Card className="rounded-lg shadow-sm">
        <CardContent className="p-4">
          <Accordion type="multiple" defaultValue={["documents", "whitespot"]} className="w-full">
            <AccordionItem value="documents" className="border-b">
              <AccordionTrigger>
                <div className="flex items-center gap-2">
                  <FileText className="h-4 w-4 text-muted-foreground" />
                  <span className="text-base font-semibold">All Documents</span>
                  <Badge variant="secondary" className="ml-2">{docsTotal}</Badge>
                </div>
              </AccordionTrigger>
              <AccordionContent>
                {orders.isLoading ? (
                  <SkeletonRows />
                ) : orders.isError ? (
                  <ErrorBlock message={orders.error instanceof Error ? orders.error.message : "Documents could not be loaded."} onRetry={() => orders.refetch()} />
                ) : docs.length === 0 ? (
                  <EmptyBlock message={branches.length > 0 ? "No sales documents found directly against this account — check its branches above." : "No sales documents found for this customer."} />
                ) : (
                  <>
                    <DocumentsTable customerId={customerId} docs={docs} />
                    <div className="mt-4">
                      <PaginationControls
                        currentPage={docsPage}
                        lastPage={docsLastPage}
                        total={docsTotal}
                        perPage={docsPerPage}
                        onPageChange={setDocsPage}
                        onPerPageChange={setDocsPerPage}
                      />
                    </div>
                  </>
                )}
              </AccordionContent>
            </AccordionItem>

            <AccordionItem value="whitespot" className="border-b">
              <AccordionTrigger>
                <div className="flex items-center gap-2">
                  <ClipboardList className="h-4 w-4 text-muted-foreground" />
                  <span className="text-base font-semibold">Items not ordered</span>
                  <Badge variant="secondary" className="ml-2">{whitespotCount}</Badge>
                </div>
              </AccordionTrigger>
              <AccordionContent>
                <SuggestedOrdersCard
                  data={suggestedOrders.data}
                  isLoading={suggestedOrders.isLoading}
                  isError={suggestedOrders.isError}
                  error={suggestedOrders.error}
                  onRetry={() => suggestedOrders.refetch()}
                  pageSize={WHITESPOT_PAGE_SIZE}
                />
              </AccordionContent>
            </AccordionItem>

            <AccordionItem value="common-products" className="border-b-0">
              <AccordionTrigger>
                <div className="flex items-center gap-2">
                  <Package className="h-4 w-4 text-muted-foreground" />
                  <span className="text-base font-semibold">Common Products</span>
                  <Badge variant="secondary" className="ml-2">{commonProductsCount}</Badge>
                </div>
              </AccordionTrigger>
              <AccordionContent>
                <CommonProductsCard
                  data={commonProducts.data}
                  isLoading={commonProducts.isLoading}
                  isError={commonProducts.isError}
                  error={commonProducts.error}
                  onRetry={() => commonProducts.refetch()}
                  pageSize={COMMON_PRODUCTS_PAGE_SIZE}
                />
              </AccordionContent>
            </AccordionItem>
          </Accordion>
        </CardContent>
      </Card>
    </div>
  );
}
