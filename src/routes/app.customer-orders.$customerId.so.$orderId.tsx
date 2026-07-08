import { Link, createFileRoute, useParams } from "@tanstack/react-router";
import { ArrowLeft } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  ErrorBlock,
  OrderDetailBody,
  SkeletonRows,
} from "@/components/customer-orders-shared";
import { useOrder } from "@/hooks/useOrders";

export const Route = createFileRoute("/app/customer-orders/$customerId/so/$orderId")({
  head: () => ({ meta: [{ title: "Customer Document - Kim-Fay OrderWatch" }] }),
  component: CustomerDocumentDetailPage,
});

function CustomerDocumentDetailPage() {
  const { customerId, orderId } = useParams({ from: "/app/customer-orders/$customerId/so/$orderId" });
  const order = useOrder(orderId);
  const lines = order.data?.lines ?? [];
  const actualCustomerId = order.data?.customer_acumatica_id ?? null;
  const actualParentId = order.data?.customer?.parent_acumatica_id ?? null;
  const mismatched = order.data !== undefined && actualCustomerId !== null && actualCustomerId !== customerId;

  return (
    <div className="flex flex-col gap-6 p-6">
      <div>
        <Button asChild variant="ghost" size="sm" className="-ml-2 mb-2">
          <Link to="/app/customer-orders/$customerId" params={{ customerId }}>
            <ArrowLeft className="mr-2 h-4 w-4" />
            Customer documents
          </Link>
        </Button>
        <h1 className="text-2xl font-semibold tracking-tight">{orderId}</h1>
        <p className="text-sm text-muted-foreground">
          {order.data?.customer_name ?? customerId}
          {order.data?.order_type && <span className="ml-2 font-mono">{order.data.order_type}</span>}
        </p>
      </div>

      {mismatched && (
        <div className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
          <p>
            This order is attached to <span className="font-mono">{actualCustomerId}</span>, not{" "}
            <span className="font-mono">{customerId}</span>.
          </p>
          {actualCustomerId && (
            actualParentId ? (
              <Button asChild size="sm" variant="outline" className="mt-2">
                <Link
                  to="/app/customer-orders/$customerId/branch/$branchId/so/$orderId"
                  params={{ customerId: actualParentId, branchId: actualCustomerId, orderId }}
                >
                  Go to the correct branch document →
                </Link>
              </Button>
            ) : (
              <Button asChild size="sm" variant="outline" className="mt-2">
                <Link to="/app/customer-orders/$customerId/so/$orderId" params={{ customerId: actualCustomerId, orderId }}>
                  Go to the correct document →
                </Link>
              </Button>
            )
          )}
        </div>
      )}

      {order.isLoading ? (
        <SkeletonRows />
      ) : order.isError ? (
        <ErrorBlock message={order.error instanceof Error ? order.error.message : "Document could not be loaded."} onRetry={() => order.refetch()} />
      ) : order.data ? (
        <>
          <Card className="rounded-lg shadow-sm">
            <CardHeader className="pb-3">
              <CardTitle className="text-base">Attached To</CardTitle>
            </CardHeader>
            <CardContent>
              <Link
                to="/app/customer-orders/$customerId"
                params={{ customerId: actualCustomerId ?? customerId }}
                className="flex items-center justify-between rounded-md border bg-muted/20 px-3 py-2.5 text-sm hover:bg-muted/40"
              >
                <div>
                  <div className="font-medium">{order.data.customer?.name ?? order.data.customer_name ?? actualCustomerId ?? customerId}</div>
                  <div className="font-mono text-[11px] text-muted-foreground">{actualCustomerId ?? customerId}</div>
                </div>
                <span className="text-xs text-muted-foreground">View account →</span>
              </Link>
            </CardContent>
          </Card>

          <OrderDetailBody order={order.data} lines={lines} />
        </>
      ) : null}
    </div>
  );
}
