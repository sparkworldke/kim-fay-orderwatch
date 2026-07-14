import { useCallback } from "react";
import { useCapabilities } from "@/hooks/useCapabilities";
import { formatKES } from "@/lib/format";
import { cn } from "@/lib/utils";

type Props = {
  value: number | string | null | undefined;
  className?: string;
  currency?: string;
  compact?: boolean;
};

const MASKED_LABEL = "KES •••••";

export function useMaskedKESFormatter() {
  const { maskRevenue } = useCapabilities();

  return useCallback(
    (n: number | string | null | undefined, opts: { compact?: boolean } = {}) => {
      if (maskRevenue) {
        return MASKED_LABEL;
      }
      return formatKES(Number(n ?? 0), opts);
    },
    [maskRevenue],
  );
}

export function MaskedCurrency({ value, className, currency = "KES", compact = false }: Props) {
  const { maskRevenue } = useCapabilities();

  if (maskRevenue) {
    return (
      <span className={cn("select-none blur-sm", className)} aria-label="Revenue hidden">
        {MASKED_LABEL}
      </span>
    );
  }

  const numeric = typeof value === "number" ? value : Number(value ?? 0);

  if (compact) {
    return <span className={className}>{formatKES(numeric, { compact: true })}</span>;
  }

  // Fixed locale avoids SSR/client hydration mismatches (React #418).
  return (
    <span className={className}>
      {currency}{" "}
      {numeric.toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
    </span>
  );
}

/** Drop-in replacement for formatKES() in JSX — respects revenue masking. */
export function MaskedKES({
  value,
  className,
  compact = false,
}: {
  value: number | string | null | undefined;
  className?: string;
  compact?: boolean;
}) {
  return <MaskedCurrency value={value} className={className} compact={compact} />;
}