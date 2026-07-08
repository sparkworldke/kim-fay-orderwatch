import { Link, Outlet, createFileRoute, useParams, useRouterState } from "@tanstack/react-router";
import { ArrowLeft, FileText } from "lucide-react";
import { useMemo, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import {
  BranchesCard,
  CommonProductsCard,
  DocumentsTable,
  EmptyBlock,
  ErrorBlock,
  MetricsGrid,
  SkeletonRows,
  SuggestedOrdersCard,
  summarizeDocuments,
} from "@/components/customer-orders-shared";
import { useCommonProducts, useSuggestedOrders } from "@/hooks/useCustomers";
import { useOrders, type OrderFilters } from "@/hooks/useOrders";
import { apiFetch, ApiError } from "@/lib/api";
import type { AcumaticaCustomer } from "@/types/admin";
import { useQuery } from "@tanstack/react-query";

export const Route = createFileRoute("/app/customer-orders/$customerId")({
  head: () => ({ meta: [{ title: "Customer Documents - Kim-Fay OrderWatch" }] }),
  component: CustomerDocumentsPage,
});

type SortOption = NonNullable<OrderFilters["sort"]>;

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
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [sort, setSort] = useState<SortOption>("latest");

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
    per_page: 200,
  });
  const commonProducts = useCommonProducts(customerId);
  const suggestedOrders = useSuggestedOrders(customerId);
  const docs = orders.data?.data ?? [];
  const summary = useMemo(() => summarizeDocuments(docs), [docs]);
  const branches = customer.data?.branches ?? [];

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
        {customer.data?.customer_class && <Badge variant="secondary">{customer.data.customer_class}</Badge>}
      </div>

      {branches.length > 0 && <BranchesCard customerId={customerId} branches={branches} />}

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
        <CardHeader className="flex flex-row items-center justify-between gap-3 pb-3">
          <CardTitle className="text-base">All Documents</CardTitle>
          <FileText className="h-5 w-5 text-muted-foreground" />
        </CardHeader>
        <CardContent>
          {orders.isLoading ? (
            <SkeletonRows />
          ) : orders.isError ? (
            <ErrorBlock message={orders.error instanceof Error ? orders.error.message : "Documents could not be loaded."} onRetry={() => orders.refetch()} />
          ) : docs.length === 0 ? (
            <EmptyBlock message={branches.length > 0 ? "No sales documents found directly against this account — check its branches above." : "No sales documents found for this customer."} />
          ) : (
            <DocumentsTable customerId={customerId} docs={docs} />
          )}
        </CardContent>
      </Card>

      <CommonProductsCard
        data={commonProducts.data}
        isLoading={commonProducts.isLoading}
        isError={commonProducts.isError}
        error={commonProducts.error}
        onRetry={() => commonProducts.refetch()}
      />

      <SuggestedOrdersCard
        data={suggestedOrders.data}
        isLoading={suggestedOrders.isLoading}
        isError={suggestedOrders.isError}
        error={suggestedOrders.error}
        onRetry={() => suggestedOrders.refetch()}
      />
    </div>
  );
}
