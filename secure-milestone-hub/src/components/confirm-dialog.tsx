import { useState, type ReactNode } from "react";
import { AlertTriangle, Loader2, ShieldCheck } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

export interface ConsequenceItem {
  label: string;
  detail: string;
}

interface ConfirmDialogProps {
  open: boolean;
  onOpenChange: (v: boolean) => void;
  title: string;
  description: string;
  consequences?: ConsequenceItem[];
  confirmLabel: string;
  cancelLabel?: string;
  tone?: "primary" | "destructive" | "success";
  weight?: "standard" | "heavy";
  /** When set, the user must type this string exactly before confirming. */
  typedConfirmation?: string | undefined;
  typedConfirmationLabel?: string | undefined;
  extra?: ReactNode;
  onConfirm: () => void | Promise<void>;
}

export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  consequences = [],
  confirmLabel,
  cancelLabel = "Cancel",
  tone = "primary",
  weight = "standard",
  typedConfirmation,
  typedConfirmationLabel,
  extra,
  onConfirm,
}: ConfirmDialogProps) {
  const [typed, setTyped] = useState("");
  const [busy, setBusy] = useState(false);
  const needsTyped = Boolean(typedConfirmation);
  const matches = !needsTyped || typed.trim().toLowerCase() === typedConfirmation!.trim().toLowerCase();
  const touched = typed.length > 0;

  async function handleConfirm() {
    setBusy(true);
    try {
      await onConfirm();
      setTyped("");
      onOpenChange(false);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(v) => {
        if (!v) setTyped("");
        onOpenChange(v);
      }}
    >
      <DialogContent
        className={cn(
          "gap-0 overflow-hidden p-0",
          weight === "heavy" ? "sm:max-w-xl" : "sm:max-w-md",
        )}
      >
        <div
          className={cn(
            "px-6 pt-6",
            weight === "heavy" && "border-b border-border bg-secondary/60 pb-6",
          )}
        >
          <DialogHeader className="space-y-2 text-left">
            <div className="flex items-center gap-2">
              {tone === "destructive" ? (
                <AlertTriangle className="h-4 w-4 text-destructive" aria-hidden />
              ) : (
                <ShieldCheck className="h-4 w-4 text-primary" aria-hidden />
              )}
              <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {weight === "heavy" ? "Binding action" : "Confirm"}
              </span>
            </div>
            <DialogTitle className={weight === "heavy" ? "text-2xl" : "text-lg"}>
              {title}
            </DialogTitle>
            <DialogDescription className="text-sm leading-relaxed">{description}</DialogDescription>
          </DialogHeader>
        </div>

        <div className="space-y-5 px-6 py-5">
          {consequences.length > 0 && (
            <ul className="divide-y divide-border overflow-hidden rounded-md border border-border">
              {consequences.map((c) => (
                <li key={c.label} className="flex flex-col gap-0.5 px-4 py-3 text-sm">
                  <span className="font-medium text-foreground">{c.label}</span>
                  <span className="text-muted-foreground">{c.detail}</span>
                </li>
              ))}
            </ul>
          )}

          {extra}

          {needsTyped && (
            <div className="space-y-2">
              <Label htmlFor="typed-confirm" className="text-sm">
                {typedConfirmationLabel ?? `Type “${typedConfirmation}” to confirm`}
              </Label>
              <Input
                id="typed-confirm"
                value={typed}
                autoComplete="off"
                onChange={(e) => setTyped(e.target.value)}
                placeholder={typedConfirmation}
                aria-invalid={touched && !matches}
                className={cn(
                  "font-mono",
                  touched && !matches && "border-destructive focus-visible:ring-destructive/40",
                )}
              />
              {touched && !matches && (
                <p className="text-xs text-destructive">
                  This must match “{typedConfirmation}” exactly, including spelling.
                </p>
              )}
            </div>
          )}
        </div>

        <DialogFooter className="gap-2 border-t border-border bg-secondary/40 px-6 py-4 sm:justify-between">
          <Button
            variant="ghost"
            onClick={() => onOpenChange(false)}
            disabled={busy}
            className="press"
          >
            {cancelLabel}
          </Button>
          <Button
            onClick={handleConfirm}
            disabled={!matches || busy}
            variant={tone === "destructive" ? "destructive" : "default"}
            className="press"
          >
            {busy && <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />}
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
