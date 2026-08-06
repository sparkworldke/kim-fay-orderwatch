import { createFileRoute } from "@tanstack/react-router";
import { Sparkles } from "lucide-react";
import { KpAccountsTable } from "@/components/kp-accounts-table";

export const Route = createFileRoute("/app/kp/contract-cleaners")({
  head: () => ({ meta: [{ title: "KP Contract Cleaners - Kim-Fay Sight" }] }),
  component: KpContractCleanersPage,
});

/** Contract Cleaners portfolio — customer class KPCCLNRS only. */
function KpContractCleanersPage() {
  return (
    <KpAccountsTable
      title="KP Contract Cleaners"
      description="Customer class KPCCLNRS only."
      searchPlaceholder="Search contract cleaner name, ID, email…"
      emptyTitle="No contract cleaner accounts found"
      emptyHint="No KPCCLNRS customers in your portfolio."
      customerClass="KPCCLNRS"
      badgeLabel="cleaners"
      icon={<Sparkles className="mx-auto mb-2 h-8 w-8 opacity-30" />}
    />
  );
}
