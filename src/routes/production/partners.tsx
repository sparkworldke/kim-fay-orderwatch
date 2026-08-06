import { createFileRoute } from "@tanstack/react-router";
import { useResetToken } from "@/routes/production";
import { StockPartnerIntelligencePage } from "@/pages/StockPartnerIntelligence";

export const Route = createFileRoute("/production/partners")({
  component: PartnersRoute,
});

function PartnersRoute() {
  const resetToken = useResetToken();
  return <StockPartnerIntelligencePage resetToken={resetToken} />;
}
