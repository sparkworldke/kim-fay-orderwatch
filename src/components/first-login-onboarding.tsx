import { useEffect, useState } from "react";
import { KeyRound, Loader2, Phone } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { apiFetch, getErrorMessage } from "@/lib/api";
import { getSession, setSession, useAuth } from "@/lib/auth";

export function FirstLoginOnboarding() {
  const { session, isImpersonating } = useAuth();
  const required = Boolean(session?.must_change_password) && !isImpersonating;
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [phone, setPhone] = useState(session?.phone_number ?? "");
  const [whatsapp, setWhatsapp] = useState(session?.whatsapp_number ?? "");
  const [sameNumber, setSameNumber] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    setPhone(session?.phone_number ?? "");
    setWhatsapp(session?.whatsapp_number ?? "");
  }, [session?.id, session?.phone_number, session?.whatsapp_number]);

  const updatePhone = (value: string) => {
    setPhone(value);
    if (sameNumber) setWhatsapp(value);
  };

  const submit = async () => {
    if (password.length < 8) return toast.error("Password must contain at least 8 characters.");
    if (password !== confirmation) return toast.error("Password confirmation does not match.");
    setSaving(true);
    try {
      const response = await apiFetch<{
        message: string;
        user: { phone_number?: string | null; whatsapp_number?: string | null; must_change_password: boolean };
      }>("auth/onboarding/complete", {
        method: "POST",
        body: {
          new_password: password,
          new_password_confirmation: confirmation,
          phone_number: phone.trim() || null,
          whatsapp_number: (sameNumber ? phone : whatsapp).trim() || null,
        },
      });
      const current = getSession();
      if (current) setSession({
        ...current,
        phone_number: response.user.phone_number ?? null,
        whatsapp_number: response.user.whatsapp_number ?? null,
        must_change_password: false,
      });
      toast.success(response.message);
    } catch (error) {
      toast.error(getErrorMessage(error, "Could not update your password."));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={required} onOpenChange={() => undefined}>
      <DialogContent
        className="sm:max-w-md [&>button]:hidden"
        onEscapeKeyDown={(event) => event.preventDefault()}
        onPointerDownOutside={(event) => event.preventDefault()}
      >
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2"><KeyRound className="h-5 w-5 text-primary" />Secure your account</DialogTitle>
          <DialogDescription>
            Change your temporary password to continue. Contact numbers are optional and can be updated later.
          </DialogDescription>
        </DialogHeader>
        <div className="grid gap-3">
          <label className="grid gap-1"><Label>New password</Label>
            <Input type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="8+ characters, mixed case and a number" />
          </label>
          <label className="grid gap-1"><Label>Confirm new password</Label>
            <Input type="password" autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
          </label>
          <div className="mt-1 border-t pt-3">
            <p className="mb-2 flex items-center gap-1 text-xs font-medium"><Phone className="h-3.5 w-3.5" />Optional contact numbers</p>
            <label className="grid gap-1"><Label>Safaricom number for OTP</Label>
              <Input type="tel" value={phone} onChange={(e) => updatePhone(e.target.value)} placeholder="+254712345678" />
            </label>
            <label className="mt-2 flex items-center gap-2 text-xs">
              <input type="checkbox" checked={sameNumber} onChange={(e) => {
                setSameNumber(e.target.checked);
                if (e.target.checked) setWhatsapp(phone);
              }} />
              Use the same number for WhatsApp
            </label>
            {!sameNumber && <label className="mt-2 grid gap-1"><Label>WhatsApp number</Label>
              <Input type="tel" value={whatsapp} onChange={(e) => setWhatsapp(e.target.value)} placeholder="+254712345678" />
            </label>}
          </div>
        </div>
        <DialogFooter>
          <Button className="w-full" disabled={saving} onClick={submit}>
            {saving && <Loader2 className="mr-1 h-4 w-4 animate-spin" />}Save password and continue
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
