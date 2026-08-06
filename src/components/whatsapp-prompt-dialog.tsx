import { useState } from "react";
import { MessageCircle, Loader2, X } from "lucide-react";
import { toast } from "sonner";
import { useNavigate } from "@tanstack/react-router";
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
import { apiFetch, getErrorMessage } from "@/lib/api";
import { getSession, setSession, useAuth } from "@/lib/auth";

/**
 * Shown once per login session when the user has no WhatsApp number on file.
 * Can be dismissed — it won't reappear until the next login.
 * Skipped entirely while impersonating, or after the first-login onboarding
 * dialog is still open (must_change_password).
 */
export function WhatsAppPromptDialog() {
  const { session, isImpersonating } = useAuth();
  const navigate = useNavigate();

  // Only show when:
  //  - session is loaded
  //  - not impersonating (admins shouldn't be nagged as another user)
  //  - password change is not pending (first-login dialog takes priority)
  //  - no whatsapp number set yet
  const shouldShow =
    !!session &&
    !isImpersonating &&
    !session.must_change_password &&
    !session.whatsapp_number?.trim();

  const [open, setOpen] = useState(true);
  const [whatsapp, setWhatsapp] = useState("");
  const [saving, setSaving] = useState(false);

  // Dialog is visible only when conditions are met AND the user hasn't dismissed.
  const isOpen = shouldShow && open;

  const dismiss = () => setOpen(false);

  const save = async () => {
    const trimmed = whatsapp.trim();
    if (!trimmed) {
      // Allow saving empty — same as skipping but stores nothing (API handles validation).
      dismiss();
      return;
    }
    setSaving(true);
    try {
      await apiFetch("profile", {
        method: "PATCH",
        body: { whatsapp_number: trimmed },
      });
      const current = getSession();
      if (current) {
        setSession({ ...current, whatsapp_number: trimmed });
      }
      toast.success("WhatsApp number saved.");
      dismiss();
    } catch (err) {
      toast.error(getErrorMessage(err, "Could not save your WhatsApp number. Try again from your profile."));
    } finally {
      setSaving(false);
    }
  };

  const goToProfile = () => {
    dismiss();
    navigate({ to: "/app/profile" });
  };

  return (
    <Dialog open={isOpen} onOpenChange={(v) => { if (!v) dismiss(); }}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <MessageCircle className="h-5 w-5 text-green-500" />
            Add your WhatsApp number
          </DialogTitle>
          <DialogDescription>
            We use WhatsApp to send you order alerts and OTP codes. You can skip this and add it later from your profile.
          </DialogDescription>
        </DialogHeader>

        <div className="grid gap-1.5">
          <Label htmlFor="wa-prompt-number">WhatsApp number</Label>
          <Input
            id="wa-prompt-number"
            type="tel"
            placeholder="+254712345678"
            value={whatsapp}
            onChange={(e) => setWhatsapp(e.target.value)}
            onKeyDown={(e) => { if (e.key === "Enter") save(); }}
            autoFocus
          />
        </div>

        <DialogFooter className="flex-col gap-2 sm:flex-row sm:justify-between">
          <Button
            variant="ghost"
            size="sm"
            onClick={goToProfile}
            className="text-muted-foreground"
          >
            Update in profile
          </Button>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={dismiss} disabled={saving}>
              <X className="mr-1 h-3.5 w-3.5" />
              Skip
            </Button>
            <Button size="sm" onClick={save} disabled={saving}>
              {saving && <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" />}
              Save
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
