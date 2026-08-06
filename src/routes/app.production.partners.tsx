import { createFileRoute } from "@tanstack/react-router";
import { useProductionResetToken } from "@/features/kimfay-production/reset-context";
import { StockPartnerIntelligencePage } from "@/pages/StockPartnerIntelligence";

export const Route = createFileRoute("/app/production/partners")({
  component: PartnerStockRoute,
});

function PartnerStockRoute() {
  return <StockPartnerIntelligencePage resetToken={useProductionResetToken()} />;
}
