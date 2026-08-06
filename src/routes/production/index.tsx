import { createFileRoute } from "@tanstack/react-router";
import { useResetToken } from "@/routes/production";
import { StockProductionIntelligencePage } from "@/features/kimfay-production/StockProductionIntelligence";

export const Route = createFileRoute("/production/")({
  component: ProductionRoute,
});

function ProductionRoute() {
  const resetToken = useResetToken();
  return <StockProductionIntelligencePage resetToken={resetToken} />;
}
