import { Link } from "@tanstack/react-router";
import { Calendar, ExternalLink } from "lucide-react";
import { type ReactNode } from "react";
import { formatDate, toIsoDate } from "@/lib/format";

/**
 * Shared clickable entity-link components.
 *
 * Each component renders a consistent, accessible anchor that navigates to the
 * appropriate detail page. All links share:
 *  - underline-offset hover styling
 *  - `aria-label` for screen readers
 *  - `stopPropagation` when nested inside row-click handlers
 *  - graceful fallbacks for missing identifiers (renders plain text)
 */

const linkBaseClass =
  "cursor-pointer underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 rounded-sm";

// ---------------------------------------------------------------------------
// Customer
// ---------------------------------------------------------------------------

export function CustomerLink({
  customerId,
  children,
  className = "",
  showId = false,
  customerName,
  onNavigate,
}: {
  customerId: string | null | undefined;
  children?: ReactNode;
  className?: string;
  showId?: boolean;
  customerName?: string | null;
  onNavigate?: (e: React.MouseEvent) => void;
}) {
  if (!customerId) {
    return <span className={className}>{children ?? customerName ?? "—"}</span>;
  }
  return (
    <Link
      to="/app/customer-orders/$customerId"
      params={{ customerId }}
      className={`${linkBaseClass} ${className}`}
      onClick={(e) => {
        e.stopPropagation();
        onNavigate?.(e);
      }}
      aria-label={`View customer ${customerName ?? customerId} details`}
    >
      {children ?? (
        <>
          {customerName && <span className="font-medium">{customerName}</span>}
          {showId && (
            <span className="ml-1 font-mono text-[10px] text-muted-foreground">{customerId}</span>
          )}
        </>
      )}
    </Link>
  );
}

// ---------------------------------------------------------------------------
// Sales Order (SO)
// ---------------------------------------------------------------------------

export function OrderLink({
  customerId,
  branchId,
  orderId,
  children,
  className = "",
  onNavigate,
}: {
  customerId: string | null | undefined;
  branchId?: string | null;
  orderId: string | null | undefined;
  children?: ReactNode;
  className?: string;
  onNavigate?: (e: React.MouseEvent) => void;
}) {
  if (!customerId || !orderId) {
    return <span className={`font-mono text-xs font-semibold ${className}`}>{orderId ?? "—"}</span>;
  }

  const to = branchId
    ? "/app/customer-orders/$customerId/branch/$branchId/so/$orderId"
    : "/app/customer-orders/$customerId/so/$orderId";
  const params = branchId
    ? { customerId, branchId, orderId }
    : { customerId, orderId };

  return (
    <Link
      to={to}
      params={params}
      className={`${linkBaseClass} font-mono text-xs font-semibold ${className}`}
      onClick={(e) => {
        e.stopPropagation();
        onNavigate?.(e);
      }}
      aria-label={`View sales order ${orderId} details`}
    >
      {children ?? orderId}
    </Link>
  );
}

// ---------------------------------------------------------------------------
// Inventory / SKU
// ---------------------------------------------------------------------------

export function InventoryLink({
  inventoryId,
  children,
  className = "",
  description,
  onNavigate,
}: {
  inventoryId: string | null | undefined;
  children?: ReactNode;
  className?: string;
  description?: string | null;
  onNavigate?: (e: React.MouseEvent) => void;
}) {
  if (!inventoryId) {
    return <span className={className}>{children ?? description ?? "—"}</span>;
  }
  return (
    <Link
      to="/app/inventory"
      search={{ sku: inventoryId } as never}
      className={`${linkBaseClass} inline-flex items-center gap-1 ${className}`}
      onClick={(e) => {
        e.stopPropagation();
        onNavigate?.(e);
      }}
      aria-label={`View inventory details for ${description ?? inventoryId}`}
    >
      {children ?? (
        <>
          {description && <span className="font-medium">{description}</span>}
          <span className="font-mono text-[10px] text-muted-foreground">{inventoryId}</span>
        </>
      )}
      <ExternalLink className="h-3 w-3 text-muted-foreground" />
    </Link>
  );
}

// ---------------------------------------------------------------------------
// Sales Consultant
// ---------------------------------------------------------------------------

export function ConsultantLink({
  consultantId,
  repCode,
  children,
  className = "",
  consultantName,
  onNavigate,
}: {
  consultantId?: string | null;
  repCode?: string | null;
  children?: ReactNode;
  className?: string;
  consultantName?: string | null;
  onNavigate?: (e: React.MouseEvent) => void;
}) {
  const id = repCode ?? consultantId;
  if (!id) {
    return <span className={className}>{children ?? consultantName ?? "—"}</span>;
  }
  return (
    <Link
      to="/app/sales-consultants/$id"
      params={{ id }}
      className={`${linkBaseClass} ${className}`}
      onClick={(e) => {
        e.stopPropagation();
        onNavigate?.(e);
      }}
      aria-label={`View sales consultant ${consultantName ?? id} profile`}
    >
      {children ?? (
        <span className="font-medium">{consultantName ?? id}</span>
      )}
    </Link>
  );
}

// ---------------------------------------------------------------------------
// Date - navigates to orders-by-date page showing all SOs for that date
// ---------------------------------------------------------------------------

export function DateLink({
  value,
  children,
  className = "",
  format = "date",
  emptyText = "—",
  showButton = false,
  onNavigate,
}: {
  value: string | null | undefined;
  children?: ReactNode;
  className?: string;
  format?: "date" | "datetime" | "raw";
  emptyText?: string;
  showButton?: boolean;
  onNavigate?: (e: React.MouseEvent) => void;
}) {
  const isoDate = toIsoDate(value);
  if (!isoDate) {
    return <span className={`text-muted-foreground ${className}`}>{emptyText}</span>;
  }

  const label =
    children ??
    (format === "raw"
      ? value
      : format === "datetime"
        ? value
        : formatDate(value));

  const ariaLabel = `View all sales orders for ${formatDate(value) ?? isoDate}`;

  const content = showButton ? (
    <span className="inline-flex items-center gap-1">
      <Calendar className="h-3 w-3 text-muted-foreground" />
      <span>{label}</span>
    </span>
  ) : (
    <span>{label}</span>
  );

  return (
    <Link
      to="/app/orders-by-date/$date"
      params={{ date: isoDate }}
      className={`${linkBaseClass} ${className}`}
      onClick={(e) => {
        e.stopPropagation();
        onNavigate?.(e);
      }}
      aria-label={ariaLabel}
      title={ariaLabel}
    >
      {content}
    </Link>
  );
}

/**
 * A standalone "View Date" button that can be placed adjacent to any date display.
 */
export function ViewDateButton({
  value,
  className = "",
  label = "View Date",
}: {
  value: string | null | undefined;
  className?: string;
  label?: string;
}) {
  const isoDate = toIsoDate(value);
  if (!isoDate) return null;

  return (
    <Link
      to="/app/orders-by-date/$date"
      params={{ date: isoDate }}
      className={`inline-flex items-center gap-1 rounded-md border bg-background px-2 py-0.5 text-xs font-medium text-foreground transition-colors hover:bg-accent ${linkBaseClass} ${className}`}
      aria-label={`View all sales orders for ${formatDate(value) ?? isoDate}`}
      title={`View all sales orders for ${formatDate(value) ?? isoDate}`}
    >
      <Calendar className="h-3 w-3" />
      {label}
    </Link>
  );
}

/**
 * Clickable date with an adjacent "View Date" button — standard pattern site-wide.
 */
export function DateWithActions({
  value,
  className = "",
  format = "date",
  emptyText = "—",
  buttonLabel = "View Date",
}: {
  value: string | null | undefined;
  className?: string;
  format?: "date" | "datetime" | "raw";
  emptyText?: string;
  buttonLabel?: string;
}) {
  const isoDate = toIsoDate(value);
  if (!isoDate) {
    return <span className={`text-muted-foreground ${className}`}>{emptyText}</span>;
  }

  return (
    <span className={`inline-flex flex-wrap items-center gap-2 ${className}`}>
      <DateLink value={value} format={format} emptyText={emptyText} />
      <ViewDateButton value={value} label={buttonLabel} />
    </span>
  );
}
