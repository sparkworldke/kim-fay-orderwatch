import { useState } from "react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { useMsi } from "@/hooks/useMsiOverrides";
import { formatNumber } from "@/utils/Stock/format";

export function MsiEditDialog({
  open,
  onOpenChange,
  inventoryId,
  productName,
  currentMsi,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  inventoryId: string;
  productName: string;
  currentMsi: number;
}) {
  const { updateMsi } = useMsi();
  const [value, setValue] = useState(String(currentMsi));
  const [reason, setReason] = useState("");
  const [error, setError] = useState("");

  const submit = () => {
    const next = Number(value);
    if (!Number.isFinite(next) || next < 0) return setError("Enter a valid non-negative number.");
    if (reason.trim().length < 5) return setError("A reason of at least 5 characters is required.");
    updateMsi({ inventoryId, productName, previousMsi: currentMsi, newMsi: Math.round(next), reason: reason.trim() });
    setError("");
    setReason("");
    onOpenChange(false);
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Update MSI</DialogTitle>
          <DialogDescription>
            {productName} Â· {inventoryId}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <div className="rounded-lg bg-secondary px-3 py-2 text-sm">
            Current MSI: <b className="tabular-nums">{formatNumber(currentMsi)}</b>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="msi-value">New MSI</Label>
            <Input id="msi-value" inputMode="numeric" value={value} onChange={(e) => setValue(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="msi-reason">Reason for change</Label>
            <Textarea id="msi-reason" value={reason} onChange={(e) => setReason(e.target.value)} rows={3} />
          </div>
          {error ? <p className="text-xs font-medium text-destructive">{error}</p> : null}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={submit}>Confirm change</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}