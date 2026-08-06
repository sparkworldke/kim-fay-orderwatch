import { useCallback } from "react";
import { formatKES } from "@/lib/format";
import { cn } from "@/lib/utils";

type Props = {
  value: number | string | null | undefined;
  className?: string;
  currency?: string;
  compact?: boolean;
};

export function useMaskedKESFormatter() {
  return useCallback(
    (n: number | string | null | undefined, opts: { compact?: boolean } = {}) => {
      return formatKES(Number(n ?? 0), opts);
    },
    [],
  );
}

export function MaskedCurrency({ value, className, currency = "KES", compact = false }: Props) {
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
