import { ArrowLeftRight, ChevronDown, Download, Loader2, Mail, PackageOpen, Send } from "lucide-react";
import { useMemo, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { ApiError } from "@/lib/api";
import { inventoryService } from "@/services/Stock/inventory.service";
import type { InventoryItem } from "@/types/Stock/inventory";
import { formatNumber } from "@/utils/Stock/format";
import { getFgsTransferRecommendation, isEligibleFgsTransferSource } from "@/utils/Stock/transferRecommendation";
import { cn } from "@/lib/utils";

interface TransferRequestsProps {
  items: InventoryItem[];
}

export function TransferRequests({ items }: TransferRequestsProps) {
  const [open, setOpen] = useState(false);
  const [expandedBrand, setExpandedBrand] = useState<string | null>(null);
  const [recipients, setRecipients] = useState("");
  const [sending, setSending] = useState(false);

  const requests = useMemo(
    () =>
      items
        .map((item) => ({ item, transfer: getFgsTransferRecommendation(item) }))
        .filter(
          (
            request,
          ): request is {
            item: InventoryItem;
            transfer: NonNullable<ReturnType<typeof getFgsTransferRecommendation>>;
          } => !!request.transfer,
        ),
    [items],
  );

  const brands = useMemo(() => {
    const grouped = new Map<string, typeof requests>();
    requests.forEach((request) => {
      grouped.set(request.item.brand, [...(grouped.get(request.item.brand) ?? []), request]);
    });
    return [...grouped.entries()].sort(([a], [b]) => a.localeCompare(b));
  }, [requests]);

  const exportRequests = () => {
    const rows = [
      ["Brand", "Inventory ID", "Product", "Source Warehouse", "Qty on Hand", "Qty Available"],
      ...requests.flatMap(({ item }) =>
        item.warehouseStocks
          .filter(isEligibleFgsTransferSource)
          .map((stock) => [
            item.brand,
            item.inventoryId,
            item.productName,
            stock.warehouseName,
            stock.qtyOnHand,
            stock.qtyAvailable,
          ]),
      ),
    ];
    const csv = rows
      .map((row) =>
        row.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(","),
      )
      .join("\r\n");
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = "fgs-transfer-requests.csv";
    link.click();
    URL.revokeObjectURL(url);
  };

  const sendEmail = async () => {
    const emails = recipients
      .split(/[,;\s]+/)
      .map((email) => email.trim())
      .filter(Boolean);
    const unique = [...new Set(emails.map((email) => email.toLowerCase()))];
    const invalid = unique.filter((email) => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email));

    if (!unique.length || invalid.length) {
      toast.error(
        invalid.length
          ? `Check these email addresses: ${invalid.join(", ")}`
          : "Enter at least one email address.",
      );
      return;
    }

    if (!requests.length) {
      toast.error("There are no transfer requests to send.");
      return;
    }

    setSending(true);
    try {
      const result = await inventoryService.sendTransferRequestEmail({
        recipients: unique,
        requests: requests.map(({ item, transfer }) => {
          const sources = item.warehouseStocks
            .filter(isEligibleFgsTransferSource)
            .map((stock) => ({
              warehouse_name: stock.warehouseName,
              qty_on_hand: stock.qtyOnHand,
              qty_available: stock.qtyAvailable,
            }));

          return {
            inventory_id: item.inventoryId,
            product_name: item.productName,
            brand: item.brand,
            source_warehouse: transfer.sourceWarehouse,
            quantity: transfer.quantity,
            sources,
          };
        }),
      });

      toast.success(
        `Email sent to ${result.recipients.join(", ")} (${result.request_count} SKUs).`,
      );
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : error instanceof Error
            ? error.message
            : "Failed to send transfer request email.";
      toast.error(message);
    } finally {
      setSending(false);
    }
  };

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        className="shrink-0 gap-1.5 border-blue-700 text-blue-700 hover:bg-blue-50 hover:text-blue-800"
        onClick={() => setOpen(true)}
      >
        <ArrowLeftRight className="size-3.5" />
        Transfer Requests
        <span className="grid min-w-5 place-items-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white">
          {requests.length}
        </span>
      </Button>

      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="right" className="w-full overflow-y-auto p-0 sm:max-w-xl">
          <SheetHeader className="border-b border-border px-4 py-4">
            <SheetTitle className="flex items-center gap-2">
              <PackageOpen className="size-5 text-blue-700" />
              Transfer Requests
            </SheetTitle>
            <SheetDescription>
              {requests.length} SKUs are not in FGS
            </SheetDescription>
          </SheetHeader>

          <div className="space-y-2 border-b border-border px-4 py-3">
            <div className="flex items-center gap-2">
              <Mail className="size-4 shrink-0 text-blue-700" />
              <Input
                type="text"
                value={recipients}
                onChange={(event) => setRecipients(event.target.value)}
                placeholder="Emails, separated by commas"
                aria-label="Transfer request email recipients"
                className="h-8 text-xs"
                disabled={sending}
              />
            </div>
            <p className="text-[10px] text-muted-foreground">
              Sends from the system mailer (not your local email app). Recipients get a full brand-grouped list.
            </p>
            <div className="grid grid-cols-2 gap-2">
              <Button variant="outline" size="sm" className="gap-1.5" onClick={exportRequests} disabled={sending}>
                <Download className="size-3.5" />
                Export CSV
              </Button>
              <Button
                size="sm"
                className="gap-1.5 bg-blue-700 hover:bg-blue-800"
                onClick={() => void sendEmail()}
                disabled={sending || requests.length === 0}
              >
                {sending ? (
                  <Loader2 className="size-3.5 animate-spin" />
                ) : (
                  <Send className="size-3.5" />
                )}
                {sending ? "Sending…" : "Send Email"}
              </Button>
            </div>
          </div>

          <div className="divide-y divide-border">
            {brands.map(([brand, brandRequests]) => {
              const expanded = expandedBrand === brand;
              return (
                <section key={brand}>
                  <button
                    type="button"
                    className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-secondary"
                    onClick={() => setExpandedBrand(expanded ? null : brand)}
                  >
                    <span>
                      <span className="block text-sm font-bold text-navy">{brand}</span>
                      <span className="text-xs text-muted-foreground">
                        {brandRequests.length} {brandRequests.length === 1 ? "SKU" : "SKUs"}
                      </span>
                    </span>
                    <ChevronDown
                      className={cn("size-4 transition-transform", expanded && "rotate-180")}
                    />
                  </button>

                  {expanded ? (
                    <div className="border-t border-border bg-secondary/30 px-3 py-2">
                      {brandRequests.map(({ item }) => {
                        const sources = item.warehouseStocks.filter(isEligibleFgsTransferSource);
                        return (
                          <div
                            key={item.inventoryId}
                            className="mb-2 overflow-hidden rounded-md border border-border bg-background last:mb-0"
                          >
                            <div className="border-b border-border px-3 py-2">
                              <p className="text-xs font-bold text-navy">{item.productName}</p>
                              <p className="text-[10px] text-muted-foreground">{item.inventoryId}</p>
                            </div>
                            <table className="w-full text-[10px]">
                              <thead className="text-blue-700">
                                <tr>
                                  <th className="px-3 py-1.5 text-left">Available Warehouse</th>
                                  <th className="px-3 py-1.5 text-right">Qty on Hand</th>
                                  <th className="px-3 py-1.5 text-right">Qty Available</th>
                                </tr>
                              </thead>
                              <tbody>
                                {sources.map((source) => (
                                  <tr
                                    key={source.warehouseId}
                                    className="border-t border-border/60"
                                  >
                                    <td className="px-3 py-1.5 font-bold">
                                      {source.warehouseName}
                                    </td>
                                    <td className="px-3 py-1.5 text-right tabular-nums">
                                      {formatNumber(source.qtyOnHand)}
                                    </td>
                                    <td className="px-3 py-1.5 text-right font-semibold tabular-nums">
                                      {formatNumber(source.qtyAvailable)}
                                    </td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          </div>
                        );
                      })}
                    </div>
                  ) : null}
                </section>
              );
            })}
          </div>
        </SheetContent>
      </Sheet>
    </>
  );
}
