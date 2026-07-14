import { Link, createFileRoute, useParams } from "@tanstack/react-router";
import { ArrowLeft } from "lucide-react";
import { Button } from "@/components/ui/button";
import { SuggestedOrdersCard } from "@/components/customer-orders-shared";
import { useSuggestedOrders } from "@/hooks/useCustomers";

export const Route = createFileRoute("/app/customer-orders/$customerId/branch/$branchId/suggested")({
  head: () => ({ meta: [{ title: "Whitespot - Kim-Fay OrderWatch" }] }),
  component: BranchSuggestedOrdersPage,
});

function BranchSuggestedOrdersPage() {
  const { customerId, branchId } = useParams({
    from: "/app/customer-orders/$customerId/branch/$branchId/suggested",
  });
  const suggested = useSuggestedOrders(branchId);

  return (
    <div className="flex flex-col gap-6 p-6">
      <div>
        <Button asChild variant="ghost" size="sm" className="-ml-2 mb-2">
          <Link to="/app/customer-orders/$customerId/branch/$branchId" params={{ customerId, branchId }}>
            <ArrowLeft className="mr-2 h-4 w-4" />
            Branch documents
          </Link>
        </Button>
        <h1 className="text-2xl font-semibold tracking-tight">Whitespot</h1>
        <p className="font-mono text-xs text-muted-foreground">{suggested.data?.customer_name ?? branchId}</p>
      </div>

      <SuggestedOrdersCard
        data={suggested.data}
        isLoading={suggested.isLoading}
        isError={suggested.isError}
        error={suggested.error}
        onRetry={() => suggested.refetch()}
      />
    </div>
  );
}
