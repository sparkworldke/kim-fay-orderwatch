import { createFileRoute } from "@tanstack/react-router";
import { useProductionResetToken } from "@/features/kimfay-production/reset-context";
import { StockProductionIntelligencePage } from "@/features/kimfay-production/StockProductionIntelligence";

export const Route = createFileRoute("/app/production/")({
  component: ProductionDashboardRoute,
});

function ProductionDashboardRoute() {
  return <StockProductionIntelligencePage resetToken={useProductionResetToken()} />;
}
