import { createFileRoute } from "@tanstack/react-router";
import { useProductionResetToken } from "@/features/kimfay-production/reset-context";
import { StockSalesIntelligencePage } from "@/pages/StockSalesIntelligence";

export const Route = createFileRoute("/app/production/sales")({
  component: StockSalesRoute,
});

function StockSalesRoute() {
  return <StockSalesIntelligencePage resetToken={useProductionResetToken()} />;
}
