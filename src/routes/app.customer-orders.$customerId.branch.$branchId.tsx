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
  CommonProductsCard,
  DocumentsTable,
  EmptyBlock,
  ErrorBlock,
  MetricsGrid,
  SkeletonRows,
  SuggestedOrdersCard,
  summarizeDocuments,
} from "@/components/customer-orders-shared";
import { PaginationControls } from "@/components/ui/pagination-controls";
import { useCommonProducts, useSuggestedOrders } from "@/hooks/useCustomers";
import { useOrders, type OrderFilters } from "@/hooks/useOrders";
import { apiFetch, ApiError } from "@/lib/api";
import type { AcumaticaCustomer } from "@/types/admin";
import { useQuery } from "@tanstack/react-query";

export const Route = createFileRoute("/app/customer-orders/$customerId/branch/$branchId")({
  head: () => ({ meta: [{ title: "Branch Documents - Kim-Fay OrderWatch" }] }),
  component: BranchDocumentsPage,
});

type SortOption = NonNullable<OrderFilters["sort"]>;

const WHITESPOT_PAGE_SIZE = 8;
const DOCUMENTS_PAGE_SIZE = 15;
const COMMON_PRODUCTS_PAGE_SIZE = 20;

function BranchDocumentsPage() {
  const { customerId, branchId } = useParams({ from: "/app/customer-orders/$customerId/branch/$branchId" });
  const pathname = useRouterState({ select: (state) => state.location.pathname });
  const basePath = `/app/customer-orders/${customerId}/branch/${branchId}`;
  if (pathname.replace(/\/$/, "") !== basePath) {
    return <Outlet />;
  }

  return <BranchDocumentsIndex customerId={customerId} branchId={branchId} />;
}

function BranchDocumentsIndex({ customerId, branchId }: { customerId: string; branchId: string }) {
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [sort, setSort] = useState<SortOption>("latest");
  const [docsPage, setDocsPage] = useState(1);

  const branch = useQuery({
    queryKey: ["customers", branchId],
    queryFn: () => apiFetch<AcumaticaCustomer>(`customers/${encodeURIComponent(branchId)}`),
    retry: (failureCount, error) => !(error instanceof ApiError && error.status === 404) && failureCount < 2,
  });
  const orders = useOrders({
    customer_id: branchId,
    order_type: "ALL",
    with_fulfillment: true,
    sort,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
    page: docsPage,
    per_page: DOCUMENTS_PAGE_SIZE,
  });
  const commonProducts = useCommonProducts(branchId);
  const suggestedOrders = useSuggestedOrders(branchId);
  const docs = orders.data?.data ?? [];
  const summary = useMemo(() => summarizeDocuments(docs), [docs]);

  const filtersKey = `${sort}|${dateFrom}|${dateTo}`;
  const [lastFiltersKey, setLastFiltersKey] = useState(filtersKey);
  if (filtersKey !== lastFiltersKey) {
    setLastFiltersKey(filtersKey);
    if (docsPage !== 1) setDocsPage(1);
  }

  const backButton = (
    <Button asChild variant="ghost" size="sm" className="-ml-2 mb-2">
      <Link to="/app/customer-orders/$customerId" params={{ customerId }}>
        <ArrowLeft className="mr-2 h-4 w-4" />
        Back to parent account
      </Link>
    </Button>
  );

  if (branch.isError && branch.error instanceof ApiError && branch.error.status === 404) {
    return (
      <div className="flex flex-col gap-4 p-6">
        {backButton}
        <ErrorBlock message={`Branch "${branchId}" was not found.`} onRetry={() => branch.refetch()} />
      </div>
    );
  }

  if (branch.data && branch.data.parent_acumatica_id !== customerId) {
    return (
      <div className="flex flex-col gap-4 p-6">
        {backButton}
        <ErrorBlock
          message={`"${branch.data.name}" (${branchId}) is not a branch of ${customerId}. It belongs to ${branch.data.parent_acumatica_id ?? "no parent account"}.`}
          onRetry={() => branch.refetch()}
        />
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
          {backButton}
          <h1 className="text-2xl font-semibold tracking-tight">
            {branch.isLoading ? "Branch" : branch.data?.name ?? branchId}
          </h1>
          <p className="font-mono text-xs text-muted-foreground">{branchId}</p>
        </div>
        {branch.data?.customer_class && <Badge variant="secondary">{branch.data.customer_class}</Badge>}
      </div>

      <MetricsGrid summary={summary} loading={orders.isLoading} />

      <Card className="rounded-lg shadow-sm">
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Filters</CardTitle>
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
                  <EmptyBlock message="No sales documents found for this branch." />
                ) : (
                  <>
                    <DocumentsTable customerId={customerId} branchId={branchId} docs={docs} />
                    <div className="mt-4">
                      <PaginationControls
                        currentPage={docsPage}
                        lastPage={docsLastPage}
                        total={docsTotal}
                        perPage={DOCUMENTS_PAGE_SIZE}
                        onPageChange={setDocsPage}
                        onPerPageChange={() => {}}
                        pageSizes={[DOCUMENTS_PAGE_SIZE]}
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
                  <span className="text-base font-semibold">Whitespot</span>
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